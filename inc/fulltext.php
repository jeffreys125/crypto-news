<?php
/**
 * 全文抓取与正文清洗
 *
 * 流程：
 *  1. RSS 中若带 content:encoded / content 且确为全文，直接采用（最省请求、最尊重来源）；
 *  2. 否则访问原文页，用 DOM 从常见正文容器（article / articleBody / post-content 等）
 *     中提取主内容；服务器无 DOM 扩展时退化为正则提取；
 *  3. 无论哪条路径，最终都经过白名单清洗：只保留排版标签与 a[href] / img[src]，
 *     移除 on* 事件、javascript:/data: 协议、style/class/id 等属性，
 *     相对链接转绝对，外链加 nofollow，图片加 no-referrer；
 *  4. 清洗后的 HTML 才入库，详情页直接输出，杜绝存储型 XSS。
 *
 * 兼容 PHP 5.4+ / MySQL 5.5（入库前过滤 4 字节字符并限长）。
 */
if (!defined('CN_ROOT')) {
    die('Access denied');
}

/** 文章页 URL 安全校验（防 SSRF：拒绝内网 / 保留地址 / 非 http(s)） */
function cn_safe_article_url($url)
{
    $url = trim((string) $url);
    if (!preg_match('#^https?://#i', $url)) {
        return false;
    }
    $p = parse_url($url);
    if (!$p || empty($p['host'])) {
        return false;
    }
    $host = $p['host'];
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : @gethostbyname($host);
    if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}

/** 把相对 URL 转成绝对 URL，并过滤危险协议 */
function cn_safe_href($url, $baseUrl)
{
    $url = trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'));
    if ($url === '' || preg_match('/^\s*(javascript|data|vbscript|file)\s*:/i', $url)) {
        return '';
    }
    if (strpos($url, '//') === 0) {
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        $url = ($scheme ? $scheme : 'https') . ':' . $url;
    }
    if (!preg_match('#^https?://#i', $url)) {
        $p = parse_url($baseUrl);
        if (!$p || empty($p['host'])) {
            return '';
        }
        $root = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if (strpos($url, '/') === 0) {
            $url = $root . $url;
        } else {
            $path = isset($p['path']) ? $p['path'] : '/';
            $url = $root . rtrim(dirname($path), '/') . '/' . $url;
        }
    }
    return $url;
}

/** 清洗后 HTML 的纯文本长度 */
function cn_text_length($html)
{
    $text = cn_clean_text($html, 0);
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

/** 判断 RSS 里取到的 HTML 是否够格算「全文」 */
function cn_is_full_html($html)
{
    $html = (string) $html;
    if ($html === '') {
        return false;
    }
    if (!preg_match('/<\s*(p|div|br|h[1-6]|ul|li|img|figure|blockquote)\b/i', $html)) {
        return false;
    }
    return cn_text_length($html) >= 300;
}

/** 入库前处理：过滤 4 字节字符 + 限长（MEDIUMTEXT 足够，但防止异常大页面） */
function cn_prepare_content($html)
{
    $html = cn_strip_4byte((string) $html);
    if (function_exists('mb_substr')) {
        return mb_substr($html, 0, 200000, 'UTF-8');
    }
    return substr($html, 0, 300000);
}

/** 响应体编码转 UTF-8（仅处理常见非 UTF-8 声明） */
function cn_to_utf8($body, $contentType)
{
    if (!function_exists('mb_convert_encoding') || !preg_match('/charset\s*=\s*([\w-]+)/i', $contentType, $m)) {
        return $body;
    }
    $cs = strtolower($m[1]);
    $known = array(
        'iso-8859-1' => 'ISO-8859-1', 'latin1' => 'ISO-8859-1',
        'windows-1252' => 'Windows-1252', 'gb2312' => 'GB2312',
        'gbk' => 'GBK', 'shift_jis' => 'SJIS', 'euc-jp' => 'EUC-JP',
    );
    if (!isset($known[$cs])) {
        return $body;
    }
    $out = @mb_convert_encoding($body, 'UTF-8', $known[$cs]);
    return (is_string($out) && $out !== '') ? $out : $body;
}

/** 载入 HTML 到 DOMDocument（强制按 UTF-8 解析，抑制畸形标签警告） */
function cn_dom_load($html)
{
    if (!class_exists('DOMDocument')) {
        return null;
    }
    $doc = new DOMDocument('1.0', 'UTF-8');
    $old = libxml_use_internal_errors(true);
    $flags = 0;
    if (defined('LIBXML_NONET')) {
        $flags = $flags | LIBXML_NONET;
    }
    // 前置 meta 声明让 libxml 按 UTF-8 处理，避免中文乱码
    $ok = @$doc->loadHTML('<?xml encoding="UTF-8" ?><meta charset="utf-8">' . $html, $flags);
    libxml_clear_errors();
    libxml_use_internal_errors($old);
    return $ok ? $doc : null;
}

/** 删除指定标签（连同内容） */
function cn_dom_remove_tags($doc, $tags)
{
    foreach ($tags as $tag) {
        $list = array();
        foreach ($doc->getElementsByTagName($tag) as $n) {
            $list[] = $n;
        }
        foreach ($list as $n) {
            if ($n->parentNode) {
                $n->parentNode->removeChild($n);
            }
        }
    }
}

/** 取节点 innerHTML */
function cn_dom_inner_html($doc, $node)
{
    $out = '';
    if (!$node) {
        return $out;
    }
    foreach ($node->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return $out;
}

/** 去掉标签但保留子节点（用于不在白名单里的容器） */
function cn_dom_unwrap($doc, $node)
{
    $parent = $node->parentNode;
    if (!$parent) {
        return;
    }
    while ($node->firstChild) {
        $parent->insertBefore($node->firstChild, $node);
    }
    $parent->removeChild($node);
}

/** 删除 HTML 注释、空容器与明显的分享/广告短段落 */
function cn_dom_strip_noise($doc, $root)
{
    if (!$root) {
        return;
    }
    $xp = new DOMXPath($doc);
    $comments = @$xp->query('//comment()');
    if ($comments) {
        $list = array();
        foreach ($comments as $c) {
            $list[] = $c;
        }
        foreach ($list as $c) {
            if ($c->parentNode) {
                $c->parentNode->removeChild($c);
            }
        }
    }
    // 丢弃纯空白文本节点（保留 <pre> 内的换行），让入库 HTML 更紧凑
    $blank = @$xp->query('//text()[not(normalize-space(.)) and not(ancestor::pre)]');
    if ($blank) {
        $list = array();
        foreach ($blank as $t) {
            $list[] = $t;
        }
        foreach ($list as $t) {
            if ($t->parentNode) {
                $t->parentNode->removeChild($t);
            }
        }
    }
    $noiseWords = '/^(share|shared|advertisement|advert|sponsored|subscribe|follow us|sign up|read more|click here|tags?)\b/i';
    for ($pass = 0; $pass < 4; $pass++) {
        $nodes = array();
        foreach ($doc->getElementsByTagName('*') as $n) {
            $nodes[] = $n;
        }
        $changed = false;
        foreach ($nodes as $n) {
            if (!$n->parentNode) {
                continue;
            }
            $tag = strtolower($n->nodeName);
            if (!in_array($tag, array('p', 'li', 'figure', 'figcaption', 'blockquote', 'ul', 'ol'))) {
                continue;
            }
            $hasMedia = false;
            foreach (array('img', 'br', 'hr', 'table', 'pre', 'blockquote', 'ul', 'ol') as $keep) {
                if ($n->getElementsByTagName($keep)->length > 0) {
                    $hasMedia = true;
                    break;
                }
            }
            $text = trim(preg_replace('/\s+/u', ' ', $n->textContent));
            if ($text === '' && !$hasMedia) {
                $n->parentNode->removeChild($n);
                $changed = true;
                continue;
            }
            if ($tag === 'p' && !$hasMedia && strlen($text) < 60 && preg_match($noiseWords, $text)) {
                $n->parentNode->removeChild($n);
                $changed = true;
            }
        }
        if (!$changed) {
            break;
        }
    }
}

/** 常见噪声标签 */
function cn_noise_tags()
{
    return array('script', 'style', 'noscript', 'iframe', 'object', 'embed', 'form',
        'input', 'button', 'select', 'textarea', 'svg', 'canvas', 'video', 'audio',
        'source', 'template', 'link', 'meta', 'base', 'nav', 'header', 'footer',
        'aside', 'advertisement');
}

/** 正文容器候选关键词（class / id / itemprop） */
function cn_content_hints()
{
    return array('article-body', 'article-content', 'articlebody', 'article__content',
        'article-text', 'post-content', 'post-content-inner', 'post-body', 'post__content',
        'entry-content', 'story-body', 'story-content', 'content-body', 'content__body',
        'td-post-content', 'news-content', 'detail-content', 'main-content',
        'rich-text', 'wysiwyg', 'markdown-body', 'read__content', 'article_body');
}

/**
 * 从完整网页里提取正文容器的 innerHTML。
 * 评分依据：纯文本长度 + 段落数 + 图片数 - 链接文本占比，选最优候选。
 */
function cn_extract_main_content($html, $baseUrl)
{
    $doc = cn_dom_load($html);
    if (!$doc) {
        return cn_extract_main_content_regex($html);
    }
    cn_dom_remove_tags($doc, cn_noise_tags());

    $xp = new DOMXPath($doc);
    $conds = array();
    foreach (cn_content_hints() as $hint) {
        $conds[] = 'contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "' . $hint . '")';
        $conds[] = 'contains(translate(@id, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "' . $hint . '")';
    }
    $query = '//article | //main | //*[@itemprop="articleBody"]'
        . ' | //*[' . implode(' or ', $conds) . ']';
    $candidates = @$xp->query($query);

    $best = null;
    $bestScore = 0;
    if ($candidates) {
        foreach ($candidates as $node) {
            $text = trim($node->textContent);
            $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
            if ($len < 180) {
                continue;
            }
            $pNodes = @$xp->query('.//p', $node);
            $imgNodes = @$xp->query('.//img', $node);
            $pCount = $pNodes ? $pNodes->length : 0;
            $imgCount = $imgNodes ? $imgNodes->length : 0;
            $linkText = 0;
            $links = @$xp->query('.//a', $node);
            if ($links) {
                foreach ($links as $a) {
                    $linkText += strlen(trim($a->textContent));
                }
            }
            $divNodes = @$xp->query('.//div', $node);
            $divCount = $divNodes ? $divNodes->length : 0;
            // 外层布局容器通常套着大量 div，予以惩罚；明确的正文容器 class 予以加权
            $hintStr = strtolower($node->getAttribute('class') . ' ' . $node->getAttribute('id') . ' ' . $node->getAttribute('itemprop'));
            $strongBonus = 0;
            foreach (array('article-body', 'post-content', 'entry-content', 'article-content',
                'articlebody', 'story-body', 'post-detail__content', 'post-detail__body',
                'news-content', 'detail-content') as $strong) {
                if (strpos($hintStr, $strong) !== false) {
                    $strongBonus = 400;
                    break;
                }
            }
            $score = $len + $pCount * 120 + $imgCount * 30 - $linkText * 0.9 - $divCount * 12 + $strongBonus;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $node;
            }
        }
    }

    if ($best) {
        return cn_dom_inner_html($doc, $best);
    }
    $body = $doc->getElementsByTagName('body')->item(0);
    $fallback = cn_dom_inner_html($doc, $body);
    // body 兜底时若几乎没有文本，判定为无正文（常见于 JS 渲染 / 反爬拦截页）
    return cn_text_length($fallback) >= 180 ? $fallback : '';
}

/** 无 DOM 扩展时的正则兜底提取 */
function cn_extract_main_content_regex($html)
{
    $html = preg_replace('/<(script|style|noscript|nav|header|footer|aside|form|iframe|svg|template)[^>]*>.*?<\/\1>/is', ' ', $html);
    if (preg_match('/<article[^>]*>(.*?)<\/article>/is', $html, $m)) {
        return $m[1];
    }
    if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $m)) {
        return $m[1];
    }
    return $html;
}

/** 允许保留的排版标签 */
function cn_allowed_html_tags()
{
    return array('p' => 1, 'br' => 1, 'h1' => 1, 'h2' => 1, 'h3' => 1, 'h4' => 1,
        'h5' => 1, 'h6' => 1, 'ul' => 1, 'ol' => 1, 'li' => 1, 'blockquote' => 1,
        'strong' => 1, 'b' => 1, 'em' => 1, 'i' => 1, 'u' => 1, 's' => 1,
        'code' => 1, 'pre' => 1, 'hr' => 1, 'figure' => 1, 'figcaption' => 1,
        'table' => 1, 'thead' => 1, 'tbody' => 1, 'tr' => 1, 'th' => 1, 'td' => 1,
        'sup' => 1, 'sub' => 1, 'a' => 1, 'img' => 1);
    // 注意：div / span 不在白名单 —— 它们会被「去壳」保留子内容，
    // 避免把站点布局容器、分享组件、空 div 一起带进正文。
}

/** 白名单清洗（DOM 实现） */
function cn_sanitize_html_dom($html, $baseUrl)
{
    $doc = cn_dom_load($html);
    if (!$doc) {
        return cn_sanitize_html_regex($html, $baseUrl);
    }
    cn_dom_remove_tags($doc, cn_noise_tags());
    $allowed = cn_allowed_html_tags();

    $nodes = array();
    foreach ($doc->getElementsByTagName('*') as $n) {
        $nodes[] = $n;
    }
    foreach ($nodes as $n) {
        if (!$n->parentNode) {
            continue;
        }
        $tag = strtolower($n->nodeName);
        if ($tag === 'html' || $tag === 'head' || $tag === 'body') {
            continue;
        }
        if (!isset($allowed[$tag])) {
            cn_dom_unwrap($doc, $n);
            continue;
        }
        $keep = array();
        if ($tag === 'a') {
            $href = cn_safe_href($n->getAttribute('href'), $baseUrl);
            if ($href !== '') {
                $keep['href'] = $href;
                $keep['rel'] = 'nofollow noopener noreferrer';
            }
        } elseif ($tag === 'img') {
            $src = cn_safe_href($n->getAttribute('src'), $baseUrl);
            if ($src !== '') {
                $keep['src'] = $src;
                $alt = trim($n->getAttribute('alt'));
                if ($alt !== '') {
                    $keep['alt'] = cn_cut($alt, 150, '');
                }
                $keep['loading'] = 'lazy';
                $keep['referrerpolicy'] = 'no-referrer';
            }
        }
        // 移除全部原属性（含 on* 事件、style、class、id、srcset 等），再写回白名单属性
        while ($n->attributes->length) {
            $n->removeAttributeNode($n->attributes->item(0));
        }
        foreach ($keep as $k => $v) {
            $n->setAttribute($k, $v);
        }
        // 图片若 src 被判定为危险，整张图丢弃
        if ($tag === 'img' && !$n->hasAttribute('src')) {
            $n->parentNode->removeChild($n);
        }
    }
    $body = $doc->getElementsByTagName('body')->item(0);
    cn_dom_strip_noise($doc, $body);
    return cn_dom_inner_html($doc, $body);
}

/** 白名单清洗（无 DOM 扩展时的正则实现） */
function cn_sanitize_html_regex($html, $baseUrl)
{
    $noise = 'script|style|noscript|iframe|object|embed|form|nav|header|footer|aside|svg|template';
    $html = preg_replace('/<(' . $noise . ')\b[^>]*>.*?<\/\1>/is', ' ', $html);
    $html = preg_replace('/<!--.*?-->/s', '', $html);
    $allowed = '<p><br><h1><h2><h3><h4><h5><h6><ul><ol><li><blockquote><strong><b><em><i><u><s><code><pre><hr><figure><figcaption><table><thead><tbody><tr><th><td><sup><sub><a><img>';
    $html = strip_tags($html, $allowed);
    return preg_replace_callback('/<([a-z][a-z0-9]*)([^>]*)>/i', function ($m) use ($baseUrl) {
        $tag = strtolower($m[1]);
        $attrs = $m[2];
        $keep = '';
        if ($tag === 'a') {
            if (preg_match('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attrs, $am)) {
                $v = (isset($am[1]) && $am[1] !== '') ? $am[1]
                    : ((isset($am[2]) && $am[2] !== '') ? $am[2] : (isset($am[3]) ? $am[3] : ''));
                $href = cn_safe_href($v, $baseUrl);
                if ($href !== '') {
                    $keep = ' href="' . h($href) . '" rel="nofollow noopener noreferrer"';
                }
            }
        } elseif ($tag === 'img') {
            if (preg_match('/\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attrs, $sm)) {
                $v = (isset($sm[1]) && $sm[1] !== '') ? $sm[1]
                    : ((isset($sm[2]) && $sm[2] !== '') ? $sm[2] : (isset($sm[3]) ? $sm[3] : ''));
                $src = cn_safe_href($v, $baseUrl);
                if ($src !== '') {
                    $alt = '';
                    if (preg_match('/\balt\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $attrs, $am2)) {
                        $alt = (isset($am2[1]) && $am2[1] !== '') ? $am2[1] : (isset($am2[2]) ? $am2[2] : '');
                    }
                    $keep = ' src="' . h($src) . '" alt="' . h(cn_cut($alt, 150, ''))
                        . '" loading="lazy" referrerpolicy="no-referrer"';
                } else {
                    return '';
                }
            } else {
                return '';
            }
        }
        $self = in_array($tag, array('br', 'hr', 'img')) ? ' /' : '';
        return '<' . $tag . $keep . $self . '>';
    }, $html);
}

/** 对外统一入口：任何来源的 HTML 都要经过这里 */
function cn_sanitize_html($html, $baseUrl)
{
    $html = cn_prepare_content((string) $html);
    if (class_exists('DOMDocument')) {
        return cn_sanitize_html_dom($html, $baseUrl);
    }
    return cn_sanitize_html_regex($html, $baseUrl);
}

/**
 * 批量抓取文章页（curl_multi 并行）。
 * $urls 为 key => url；返回同 key 的响应结构。
 */
function cn_http_get_multi($urls, $options = array())
{
    $timeout = isset($options['timeout']) ? intval($options['timeout']) : 10;
    $out = array();
    foreach ($urls as $k => $u) {
        $out[$k] = array('code' => 0, 'body' => '', 'error' => '', 'final_url' => $u, 'content_type' => '');
    }

    // 无 curl_multi 时串行降级，逐条复用单请求逻辑
    if (!function_exists('curl_multi_init') || !function_exists('curl_init')) {
        foreach ($urls as $k => $u) {
            if (!cn_safe_article_url($u)) {
                $out[$k]['error'] = 'unsafe url';
                continue;
            }
            $r = cn_http_get($u, array('timeout' => $timeout));
            if (!cn_safe_article_url($r['final_url'])) {
                $r['body'] = '';
                $r['error'] = 'redirect to unsafe host';
            }
            $out[$k] = array(
                'code' => $r['code'], 'body' => substr($r['body'], 0, 1500000),
                'error' => $r['error'], 'final_url' => $r['final_url'],
                'content_type' => $r['content_type'],
            );
        }
        return $out;
    }

    $mh = curl_multi_init();
    $handles = array();
    $ua = 'Mozilla/5.0 (compatible; CryptoIndexBot/2.0; RSS full-text reader)';
    foreach ($urls as $k => $u) {
        if (!cn_safe_article_url($u)) {
            $out[$k]['error'] = 'unsafe url';
            continue;
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $u);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'User-Agent: ' . $ua,
            'Accept: text/html, application/xhtml+xml, */*',
            'Accept-Language: en-US,en;q=0.9',
        ));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, cn_config('ssl_verify', false) ? true : false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, cn_config('ssl_verify', false) ? 2 : 0);
        if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        $proxy = cn_config('http_proxy', '');
        if ($proxy !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }
        $handles[$k] = $ch;
        curl_multi_add_handle($mh, $ch);
    }

    $running = null;
    $deadline = microtime(true) + $timeout + 5;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 0.2);
        }
    } while ($running > 0 && $status === CURLM_OK && microtime(true) < $deadline);

    foreach ($handles as $k => $ch) {
        $body = curl_multi_getcontent($ch);
        $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);
        if ($final && !cn_safe_article_url($final)) {
            $body = '';
            $err = 'redirect to unsafe host';
        }
        $out[$k] = array(
            'code' => intval(curl_getinfo($ch, CURLINFO_HTTP_CODE)),
            'body' => is_string($body) ? substr($body, 0, 1500000) : '',
            'error' => $err,
            'final_url' => $final ? $final : $urls[$k],
            'content_type' => is_string($ct) ? $ct : '',
        );
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

/**
 * 为一批新入库条目补齐全文。
 * $items 为索引数组，每项含 url / summary / content_html。
 * 返回同索引的 array('content' => 已清洗 HTML, 'status' => 0|1|2)。
 *   status: 1=拿到全文, 2=失败并用摘要兜底, 0=未处理（超出限量）
 */
function cn_fulltext_collect($items, $limit)
{
    $result = array();
    $needFetch = array();
    $limit = max(0, intval($limit));

    foreach ($items as $i => $it) {
        $raw = isset($it['content_html']) ? $it['content_html'] : '';
        $url = isset($it['url']) ? $it['url'] : '';
        if ($raw !== '' && cn_is_full_html($raw)) {
            $clean = cn_sanitize_html($raw, $url);
            if (cn_text_length($clean) >= 120) {
                $result[$i] = array('content' => cn_prepare_content($clean), 'status' => 1);
                continue;
            }
        }
        $result[$i] = array('content' => '', 'status' => 0);
        if ($url !== '' && count($needFetch) < $limit) {
            $needFetch[$i] = $url;
        }
    }

    if (empty($needFetch)) {
        return $result;
    }

    // 每批最多 8 个并发，避免一次性对同一站点发起过多请求
    $responses = array();
    foreach (array_chunk($needFetch, 8, true) as $chunk) {
        foreach (cn_http_get_multi($chunk, array('timeout' => 10)) as $k => $v) {
            $responses[$k] = $v;
        }
    }
    foreach ($needFetch as $i => $url) {
        $r = isset($responses[$i]) ? $responses[$i] : null;
        $fallback = isset($items[$i]['summary']) ? $items[$i]['summary'] : '';
        if (!$r || $r['code'] < 200 || $r['code'] >= 300 || $r['body'] === '') {
            $result[$i] = array(
                'content' => $fallback !== '' ? '<p>' . h($fallback) . '</p>' : '',
                'status' => 2,
            );
            continue;
        }
        $body = cn_to_utf8($r['body'], $r['content_type']);
        $main = cn_extract_main_content($body, $r['final_url']);
        $clean = $main !== '' ? cn_sanitize_html($main, $r['final_url']) : '';
        if (cn_text_length($clean) >= 120) {
            $result[$i] = array('content' => cn_prepare_content($clean), 'status' => 1);
        } else {
            $result[$i] = array(
                'content' => $fallback !== '' ? '<p>' . h($fallback) . '</p>' : '',
                'status' => 2,
            );
        }
    }
    return $result;
}
