<?php
/**
 * RSS / Atom 采集引擎（v2 · 全文版）
 *
 * 设计要点：
 *  1. 采集标题、摘要、原文链接、署名，并抓取文章全文（优先 RSS content:encoded，
 *     缺失时访问原文页提取正文），经白名单清洗后入库供站内阅读；
 *  2. 支持 ETag / Last-Modified 条件请求（304 直接跳过）与失败指数退避，礼貌抓取；
 *  3. URL 规范化后 md5 去重，支持 RSS 2.0 / Atom 1.0 / RDF；
 *  4. 采集地址与正文抓取目标均做内网地址校验，降低 SSRF 风险；
 *  5. 全部字符串过滤 4 字节 UTF-8 字符，兼容 MySQL 5.5 的 utf8(utf8mb3)；
 *  6. 全文抓取可在后台开关，并限制每个来源每次补齐的篇数，避免压垮来源站点。
 */
if (!defined('CN_ROOT')) {
    die('Access denied');
}

/** 预置资讯源（均为公开 RSS，可在后台增删改） */
function cn_default_sources()
{
    return array(
        array('name' => 'Cointelegraph',    'site_url' => 'https://cointelegraph.com',      'feed_url' => 'https://cointelegraph.com/rss',                                'category' => 'General'),
        array('name' => 'CoinDesk',          'site_url' => 'https://www.coindesk.com',       'feed_url' => 'https://www.coindesk.com/arc/outboundfeeds/rss/?outputType=xml', 'category' => 'General'),
        array('name' => 'The Block',         'site_url' => 'https://www.theblock.co',        'feed_url' => 'https://www.theblock.co/rss.xml',                             'category' => 'Markets'),
        array('name' => 'Decrypt',           'site_url' => 'https://decrypt.co',             'feed_url' => 'https://decrypt.co/feed',                                      'category' => 'Industry'),
        array('name' => 'Bitcoin Magazine',  'site_url' => 'https://bitcoinmagazine.com',    'feed_url' => 'https://bitcoinmagazine.com/.rss/full/',                      'category' => 'Blockchain'),
        array('name' => 'NewsBTC',           'site_url' => 'https://www.newsbtc.com',        'feed_url' => 'https://www.newsbtc.com/feed/',                                'category' => 'Markets'),
        array('name' => 'AMBCrypto',         'site_url' => 'https://ambcrypto.com',          'feed_url' => 'https://ambcrypto.com/feed/',                                  'category' => 'General'),
        array('name' => 'crypto.news',       'site_url' => 'https://crypto.news',            'feed_url' => 'https://crypto.news/feed/',                                    'category' => 'General'),
        array('name' => 'BeInCrypto',        'site_url' => 'https://beincrypto.com',         'feed_url' => 'https://beincrypto.com/feed/',                                 'category' => 'Regulation'),
        array('name' => 'U.Today',           'site_url' => 'https://u.today',                'feed_url' => 'https://u.today/rss',                                          'category' => 'General'),
        array('name' => '99Bitcoins',        'site_url' => 'https://99bitcoins.com',         'feed_url' => 'https://99bitcoins.com/feed/',                                 'category' => 'Blockchain'),
        array('name' => 'Cryptopolitan',     'site_url' => 'https://www.cryptopolitan.com',  'feed_url' => 'https://www.cryptopolitan.com/feed/',                          'category' => 'Industry'),
    );
}

/** 判断是否为内网 / 保留 IP */
function cn_is_private_ip($ip)
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/** 采集地址合法性校验（防 SSRF 与误填） */
function cn_validate_feed_url($url)
{
    $url = trim((string) $url);
    if (!preg_match('#^https?://#i', $url)) {
        return array(false, '仅支持 http:// 或 https:// 开头的 RSS 地址');
    }
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return array(false, 'URL 格式不正确，缺少主机名');
    }
    $host = $parts['host'];
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : @gethostbyname($host);
    if ($ip && cn_is_private_ip($ip)) {
        return array(false, '出于安全考虑，不允许采集内网 / 保留地址');
    }
    return array(true, '');
}

/**
 * HTTP GET，返回统一结构。
 * 优先 cURL，缺失时回退 file_get_contents（allow_url_fopen）。
 */
function cn_http_get($url, $options = array())
{
    $timeout = isset($options['timeout']) ? intval($options['timeout']) : 15;
    $result = array(
        'code' => 0, 'body' => '', 'error' => '', 'etag' => '',
        'last_modified' => '', 'content_type' => '', 'final_url' => $url,
    );
    $headers = array(
        'User-Agent: CryptoIndexBot/1.0 (RSS aggregator; polite crawl)',
        'Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, */*',
    );
    if (!empty($options['etag'])) {
        $headers[] = 'If-None-Match: ' . $options['etag'];
    }
    if (!empty($options['last_modified'])) {
        $headers[] = 'If-Modified-Since: ' . $options['last_modified'];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
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
        $respHeaders = array();
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($handle, $line) use (&$respHeaders) {
            $len = strlen($line);
            $p = explode(':', $line, 2);
            if (count($p) === 2) {
                $k = strtolower(trim($p[0]));
                $v = trim($p[1]);
                if ($k === 'etag') {
                    $respHeaders['etag'] = substr($v, 0, 250);
                } elseif ($k === 'last-modified') {
                    $respHeaders['last_modified'] = substr($v, 0, 250);
                } elseif ($k === 'content-type') {
                    $respHeaders['content_type'] = $v;
                }
            }
            return $len;
        });
        $body = curl_exec($ch);
        if ($body === false) {
            $result['error'] = curl_error($ch);
        }
        $result['code'] = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        if ($final) {
            $result['final_url'] = $final;
        }
        curl_close($ch);
        if (is_string($body)) {
            $result['body'] = $body;
        }
        foreach ($respHeaders as $k => $v) {
            $result[$k] = $v;
        }
        return $result;
    }

    // 无 cURL 时的回退方案
    $headerStr = implode("\r\n", $headers) . "\r\n";
    $ctx = stream_context_create(array('http' => array(
        'method' => 'GET',
        'header' => $headerStr,
        'timeout' => $timeout,
        'follow_location' => 1,
        'max_redirects' => 3,
    )));
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        $result['error'] = 'file_get_contents 请求失败（请检查 allow_url_fopen 与网络连通性）';
        return $result;
    }
    $result['code'] = 200;
    $result['body'] = $body;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (stripos($line, 'ETag:') === 0) {
                $result['etag'] = substr(trim(substr($line, 5)), 0, 250);
            }
            if (stripos($line, 'Last-Modified:') === 0) {
                $result['last_modified'] = substr(trim(substr($line, 14)), 0, 250);
            }
        }
    }
    return $result;
}

/** 取直接子节点（忽略命名空间），返回 SimpleXMLElement 数组 */
function cn_xml_nodes($node, $localName)
{
    $res = @$node->xpath('./*[local-name()="' . $localName . '"]');
    return ($res === false || $res === null) ? array() : $res;
}

/** 取第一个匹配子节点的文本 */
function cn_xml_text($node, $names)
{
    foreach ($names as $name) {
        foreach (cn_xml_nodes($node, $name) as $el) {
            $text = trim((string) $el);
            if ($text !== '') {
                return $text;
            }
        }
    }
    return '';
}

/** 取节点属性（忽略命名空间前缀） */
function cn_xml_attr($node, $attrName)
{
    $groups = array($node->attributes());
    foreach ($node->getNamespaces(true) as $uri) {
        if ($uri) {
            $groups[] = $node->attributes($uri);
        }
    }
    foreach ($groups as $attrs) {
        foreach ($attrs as $k => $v) {
            if (strtolower((string) $k) === strtolower($attrName)) {
                return trim((string) $v);
            }
        }
    }
    return '';
}

/** 规范化 URL：补全相对地址、去掉追踪参数与 fragment */
function cn_normalize_url($url, $base)
{
    $url = trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'));
    if ($url === '') {
        return '';
    }
    if (strpos($url, '//') === 0) {
        $scheme = parse_url($base, PHP_URL_SCHEME);
        $url = ($scheme ? $scheme : 'https') . ':' . $url;
    }
    if (!preg_match('#^https?://#i', $url)) {
        $bp = parse_url($base);
        if (!$bp || empty($bp['host'])) {
            return '';
        }
        $root = $bp['scheme'] . '://' . $bp['host'] . (isset($bp['port']) ? ':' . $bp['port'] : '');
        if (strpos($url, '/') === 0) {
            $url = $root . $url;
        } else {
            $path = isset($bp['path']) ? $bp['path'] : '/';
            $url = $root . rtrim(dirname($path), '/') . '/' . $url;
        }
    }
    $p = parse_url($url);
    if (!$p || empty($p['host'])) {
        return '';
    }
    $query = '';
    if (!empty($p['query'])) {
        parse_str($p['query'], $q);
        foreach (array_keys($q) as $k) {
            if (preg_match('/^(utm_[a-z_]+|fbclid|gclid|dclid|msclkid|igshid|spm|ref|ref_src|source|from|share_[a-z_]+)$/i', $k)) {
                unset($q[$k]);
            }
        }
        $query = http_build_query($q, '', '&');
    }
    $out = strtolower($p['scheme']) . '://' . $p['host'];
    if (isset($p['port']) && $p['port'] != 80 && $p['port'] != 443) {
        $out .= ':' . $p['port'];
    }
    $out .= isset($p['path']) ? $p['path'] : '/';
    if ($query !== '') {
        $out .= '?' . $query;
    }
    return $out;
}

/** 关键词 -> 标签 */
function cn_auto_tags($title, $summary, $extra)
{
    $text = strtolower($title . ' ' . $summary . ' ' . $extra);
    $map = array(
        'Bitcoin'    => array('bitcoin', 'btc', 'satoshi', 'halving', 'ordinals', '比特币'),
        'Ethereum'   => array('ethereum', 'eth ', 'ether ', 'vitalik', 'eip-', '以太坊'),
        'DeFi'       => array('defi', 'dex ', 'liquidity', 'yield', 'lending', 'uniswap', 'aave', '借贷'),
        'NFT'        => array('nft', 'opensea', 'collectible', '数字藏品'),
        'Regulation' => array('sec ', 'regulat', 'lawsuit', 'court ', 'cftc', 'policy', 'ban ', 'legislat', '监管', '法案', '诉讼', '合规'),
        'Stablecoin' => array('stablecoin', 'usdt', 'usdc', 'tether', 'circle ', '稳定币'),
        'Exchange'   => array('exchange', 'binance', 'coinbase', 'kraken', 'okx', 'bybit', 'upbit', '交易所'),
        'Security'   => array('hack', 'exploit', 'stolen', 'breach', 'phishing', 'vulnerab', '被盗', '漏洞', '攻击'),
        'Layer2'     => array('layer 2', 'layer2', 'rollup', 'arbitrum', 'optimism', 'zksync', 'starknet'),
        'Mining'     => array('mining', 'miner', 'hashrate', '算力', '矿机'),
        'ETF'        => array('etf', 'spot bitcoin', 'inflow', 'outflow'),
        'Web3'       => array('web3', 'metaverse', 'gamefi', 'dao ', 'tokeniz'),
        'AI'         => array('artificial intelligence', 'ai ', '人工智能'),
        'Markets'    => array('price ', 'rally', 'surge', 'plunge', 'bull ', 'bear ', 'all-time high', '跌破', '涨幅', '行情'),
    );
    $tags = array();
    foreach ($map as $tag => $words) {
        foreach ($words as $w) {
            if (strpos($text, $w) !== false) {
                $tags[] = $tag;
                break;
            }
        }
        if (count($tags) >= 4) {
            break;
        }
    }
    return implode(',', $tags);
}

/** 根据关键词推断栏目（未命中则用来源默认栏目） */
function cn_guess_category($text, $default)
{
    $text = strtolower($text);
    $rules = array(
        'Regulation' => array('sec ', 'regulat', 'lawsuit', 'court', 'cftc', 'ban ', 'policy', '监管', '法案', '合规'),
        'Security'   => array('hack', 'exploit', 'stolen', 'breach', 'phishing', '被盗', '漏洞', '攻击'),
        'Markets'    => array('price ', 'rally', 'surge', 'plunge', 'bull ', 'bear ', 'bitcoin drops', 'ethereum price', '涨', '跌'),
        'Technology' => array('upgrade', 'mainnet', 'testnet', 'developer', 'protocol', 'rollup', 'github', 'eip-', '升级'),
        'Blockchain' => array('blockchain', 'bitcoin', 'ethereum', 'node', 'consensus', '链上'),
        'Industry'   => array('raises', 'funding', 'acquir', 'partnership', 'launch', '融资', '收购'),
    );
    foreach ($rules as $cat => $words) {
        foreach ($words as $w) {
            if (strpos($text, $w) !== false) {
                return $cat;
            }
        }
    }
    return $default;
}

/** 入库前的字符串清洗（限长 + 去 4 字节字符） */
function cn_s($value, $len)
{
    $v = cn_strip_4byte(trim((string) $value));
    return cn_cut($v, $len, '');
}

/**
 * 解析 RSS 2.0 / Atom 1.0 / RDF，返回规范化条目数组
 */
function cn_parse_feed($xml, $feedUrl)
{
    $xml = preg_replace('/^\xEF\xBB\xBF/', '', (string) $xml);
    $xml = ltrim($xml);
    if ($xml === '') {
        return array();
    }
    // 移除 XML 不允许的控制字符
    $xml = @preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $xml);

    $old = libxml_use_internal_errors(true);
    $flags = LIBXML_NOCDATA;
    if (defined('LIBXML_NONET')) {
        $flags = $flags | LIBXML_NONET; // 禁止加载外部实体，防 XXE
    }
    $root = simplexml_load_string($xml, 'SimpleXMLElement', $flags);
    libxml_clear_errors();
    libxml_use_internal_errors($old);
    if ($root === false) {
        return array();
    }

    $nodes = @$root->xpath('//*[local-name()="item" or local-name()="entry"]');
    if (!$nodes) {
        return array();
    }

    $out = array();
    foreach ($nodes as $node) {
        $title = cn_clean_text(cn_xml_text($node, array('title')), 0);

        // 链接：Atom 的 link[href]，RSS 的 link 文本，最后用 guid / id 兜底
        $link = '';
        foreach (cn_xml_nodes($node, 'link') as $ln) {
            $rel = cn_xml_attr($ln, 'rel');
            $href = cn_xml_attr($ln, 'href');
            $text = trim((string) $ln);
            if ($href !== '' && ($rel === '' || $rel === 'alternate')) {
                $link = $href;
                break;
            }
            if ($text !== '' && $link === '') {
                $link = $text;
            }
        }
        if ($link === '') {
            $link = cn_xml_text($node, array('guid', 'id'));
        }
        $link = cn_normalize_url($link, $feedUrl);
        if ($link === '' || $title === '') {
            continue;
        }

        // 摘要优先取 description / summary；全文优先取 content:encoded / Atom content
        $rawSummary = cn_xml_text($node, array('description', 'summary', 'encoded', 'content'));
        $rawContent = cn_xml_text($node, array('encoded', 'content', 'description', 'summary'));
        $summary = cn_clean_text($rawSummary !== '' ? $rawSummary : $rawContent, 400);
        // Atom 的 content type="html" 常把标签转义，需还原成 HTML 再交给清洗器
        $contentHtml = html_entity_decode((string) $rawContent, ENT_QUOTES, 'UTF-8');

        // 缩略图：media:* / enclosure / 正文第一张图
        $thumb = '';
        foreach (array('thumbnail', 'content', 'enclosure') as $tag) {
            foreach (cn_xml_nodes($node, $tag) as $el) {
                $u = cn_xml_attr($el, 'url');
                $type = cn_xml_attr($el, 'type');
                if ($tag === 'enclosure' && $type !== '' && stripos($type, 'image') === false) {
                    continue;
                }
                if (preg_match('#^https?://#i', $u)) {
                    $thumb = $u;
                    break 2;
                }
            }
        }
        if ($thumb === '') {
            foreach (cn_xml_nodes($node, 'group') as $group) {
                foreach (cn_xml_nodes($group, 'content') as $el) {
                    $u = cn_xml_attr($el, 'url');
                    if (preg_match('#^https?://#i', $u)) {
                        $thumb = $u;
                        break 2;
                    }
                }
            }
        }
        if ($thumb === '') {
            $imgSrc = '';
            if ($contentHtml !== '' && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $contentHtml, $m)) {
                $imgSrc = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            } elseif ($rawSummary !== '' && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $rawSummary, $m)) {
                $imgSrc = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            }
            if ($imgSrc !== '') {
                $abs = cn_normalize_url($imgSrc, $link);
                if ($abs !== '') {
                    $thumb = $abs;
                }
            }
        }

        // 作者：dc:creator / author / Atom 的 author>name
        $author = cn_xml_text($node, array('creator', 'author'));
        foreach (cn_xml_nodes($node, 'author') as $a) {
            $name = cn_xml_text($a, array('name'));
            if ($name !== '') {
                $author = $name;
                break;
            }
        }

        $rawDate = cn_xml_text($node, array('pubDate', 'published', 'updated', 'date', 'modified', 'created'));
        $ts = $rawDate !== '' ? strtotime($rawDate) : false;
        if (!$ts || $ts > time() + 86400) {
            $ts = time();
        }

        $cats = array();
        foreach (cn_xml_nodes($node, 'category') as $c) {
            $v = cn_clean_text((string) $c, 0);
            if ($v !== '') {
                $cats[] = $v;
            }
        }
        $catText = implode(' ', $cats);

        $out[] = array(
            'title'        => $title,
            'url'          => $link,
            'summary'      => $summary,
            'content_html' => $contentHtml,
            'thumb'        => $thumb,
            'author'       => $author,
            'categories'   => $catText,
            'published_at' => date('Y-m-d H:i:s', $ts),
            'tags'         => cn_auto_tags($title, $summary, $catText),
        );
    }
    return $out;
}

/** 写采集日志 */
function cn_collect_log($source, $status, $fetched, $added, $message)
{
    cn_db_exec(
        'INSERT INTO ' . cn_table('collect_log') . ' (source_id, source_name, status, fetched, added, message, created_at) VALUES (?,?,?,?,?,?,?)',
        array(
            intval($source['id']), cn_s($source['name'], 100), cn_s($status, 20),
            intval($fetched), intval($added), cn_s($message, 500), cn_now(),
        )
    );
}

/** 更新来源状态 */
function cn_update_source_state($id, $data)
{
    $sets = array();
    $params = array();
    foreach ($data as $k => $v) {
        $sets[] = '`' . preg_replace('/[^a-zA-Z0-9_]/', '', $k) . '` = ?';
        $params[] = $v;
    }
    $params[] = intval($id);
    cn_db_exec('UPDATE ' . cn_table('sources') . ' SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
}

/** 采集单个来源 */
function cn_collect_source($source, $options = array())
{
    $force = !empty($options['force']);
    $r = array(
        'id' => intval($source['id']), 'name' => $source['name'],
        'status' => 'ok', 'fetched' => 0, 'added' => 0, 'fulltext' => 0,
        'message' => '', 'skipped' => false,
    );

    // 礼貌抓取：未到间隔则跳过；连续失败时按 2^n 退避（最长 8 倍）
    if (!$force) {
        $interval = max(5, intval($source['interval_min']));
        $factor = min(8, pow(2, min(3, intval($source['fail_count']))));
        $interval = $interval * $factor;
        if (!empty($source['last_collect_at']) && strtotime($source['last_collect_at']) + $interval * 60 > time()) {
            $r['skipped'] = true;
            $r['message'] = '未到采集间隔';
            return $r;
        }
    }

    $valid = cn_validate_feed_url($source['feed_url']);
    if (!$valid[0]) {
        $r['status'] = 'error';
        $r['message'] = $valid[1];
        cn_update_source_state($source['id'], array(
            'last_collect_at' => cn_now(), 'last_status' => 'error',
            'last_message' => $r['message'], 'fail_count' => intval($source['fail_count']) + 1,
        ));
        cn_collect_log($source, 'error', 0, 0, $r['message']);
        return $r;
    }

    $resp = cn_http_get($source['feed_url'], array(
        'timeout' => 20,
        'etag' => $source['etag'],
        'last_modified' => $source['last_modified'],
    ));

    if ($resp['code'] === 304) {
        cn_update_source_state($source['id'], array(
            'last_collect_at' => cn_now(), 'last_status' => 'not_modified',
            'last_message' => '304 Not Modified，源未更新', 'fail_count' => 0,
        ));
        $r['status'] = 'not_modified';
        $r['message'] = '源未更新（304）';
        cn_collect_log($source, 'not_modified', 0, 0, $r['message']);
        return $r;
    }

    if ($resp['error'] !== '' || $resp['code'] < 200 || $resp['code'] >= 300 || $resp['body'] === '') {
        $r['status'] = 'error';
        $r['message'] = 'HTTP ' . $resp['code'] . ($resp['error'] !== '' ? ' / ' . cn_s($resp['error'], 200) : ' / 空响应');
        cn_update_source_state($source['id'], array(
            'last_collect_at' => cn_now(), 'last_status' => 'error',
            'last_message' => cn_s($r['message'], 500), 'fail_count' => intval($source['fail_count']) + 1,
        ));
        cn_collect_log($source, 'error', 0, 0, $r['message']);
        return $r;
    }

    $items = cn_parse_feed($resp['body'], $source['feed_url']);
    if (empty($items)) {
        $r['status'] = 'error';
        $r['message'] = '响应不是有效的 RSS/Atom（可能被反爬拦截或返回 HTML）';
        cn_update_source_state($source['id'], array(
            'last_collect_at' => cn_now(), 'last_status' => 'error',
            'last_message' => $r['message'], 'fail_count' => intval($source['fail_count']) + 1,
        ));
        cn_collect_log($source, 'error', 0, 0, $r['message']);
        return $r;
    }

    $maxItems = max(1, min(100, intval($source['max_items'])));
    $items = array_slice($items, 0, $maxItems);
    $r['fetched'] = count($items);

    // v1 老库若因权限限制未能自动补列，则自动降级为不带全文字段的插入
    $hasFulltext = cn_has_fulltext_column();
    $sql = 'INSERT IGNORE INTO ' . cn_table('articles')
        . ' (source_id, source_name, title, url, url_hash, summary, '
        . ($hasFulltext ? 'content, content_fetched, ' : '')
        . 'thumb, author, category, tags, published_at, created_at, status)'
        . ' VALUES (?,?,?,?,?,?,'
        . ($hasFulltext ? '?,?,' : '')
        . '?,?,?,?,?,?,1)';
    $defaultCategory = $source['category'] !== '' ? $source['category'] : 'General';

    $newItems = array();
    foreach ($items as $it) {
        $params = array(
            intval($source['id']),
            cn_s($source['name'], 100),
            cn_s($it['title'], 250),
            cn_s($it['url'], 480),
            md5($it['url']),
            cn_s($it['summary'], 400),
        );
        if ($hasFulltext) {
            $params[] = '';
            $params[] = 0;
        }
        $params[] = cn_s($it['thumb'], 480);
        $params[] = cn_s($it['author'], 90);
        $params[] = cn_s(cn_guess_category($it['title'] . ' ' . $it['summary'] . ' ' . $it['categories'], $defaultCategory), 50);
        $params[] = cn_s($it['tags'], 250);
        $params[] = $it['published_at'];
        $params[] = cn_now();
        try {
            $affected = cn_db_exec($sql, $params);
            if ($affected > 0) {
                $r['added']++;
                if ($hasFulltext) {
                    $newItems[] = array(
                        'id' => intval(cn_db_last_id()),
                        'url' => $it['url'],
                        'summary' => $it['summary'],
                        'content_html' => isset($it['content_html']) ? $it['content_html'] : '',
                    );
                }
            }
        } catch (Exception $e) {
            // 单条异常不影响整批采集
            $r['message'] = '部分条目写入失败：' . cn_s($e->getMessage(), 200);
        }
    }

    // 全文补齐：本轮新条目优先，额度未满时顺带补抓历史「待补」条目
    // （否则超过每源限量的文章会永远停留在无全文状态）
    $fullEnabled = cn_setting('fulltext_enabled', '1') === '1';
    $fullLimit = max(0, min(50, intval(cn_setting('fulltext_limit', '30'))));
    if ($hasFulltext && $fullEnabled && $fullLimit > 0) {
        $batch = array_slice($newItems, 0, $fullLimit);
        if (count($batch) < $fullLimit) {
            $need = $fullLimit - count($batch);
            $feedByUrl = array();
            foreach ($items as $fi) {
                $feedByUrl[$fi['url']] = $fi;
            }
            // 排除本轮刚插入的条目，避免同一篇文章被抓两次、统计虚高
            $newIds = array();
            foreach ($newItems as $ni) {
                $newIds[] = intval($ni['id']);
            }
            $exclude = empty($newIds) ? '' : ' AND id NOT IN (' . implode(',', $newIds) . ')';
            $pending = cn_db_all('SELECT id, url, summary FROM ' . cn_table('articles')
                . ' WHERE source_id = ? AND content_fetched = 0 AND (content IS NULL OR content = \'\')'
                . $exclude . ' ORDER BY id ASC LIMIT ' . intval($need),
                array(intval($source['id'])));
            foreach ($pending as $p) {
                $fi = isset($feedByUrl[$p['url']]) ? $feedByUrl[$p['url']] : array();
                $batch[] = array(
                    'id' => intval($p['id']),
                    'url' => $p['url'],
                    'summary' => $p['summary'],
                    'content_html' => isset($fi['content_html']) ? $fi['content_html'] : '',
                );
            }
        }
        if (!empty($batch)) {
            try {
                $full = cn_fulltext_collect($batch, $fullLimit);
                foreach ($batch as $i => $row) {
                    if (!isset($full[$i]) || $full[$i]['status'] === 0) {
                        continue;
                    }
                    cn_db_exec('UPDATE ' . cn_table('articles')
                        . ' SET content = ?, content_fetched = ? WHERE id = ?',
                        array($full[$i]['content'], intval($full[$i]['status']), intval($row['id'])));
                    if ($full[$i]['status'] === 1) {
                        $r['fulltext']++;
                    }
                }
            } catch (Exception $e) {
                // 全文属于增值内容，异常不影响已入库的资讯
            }
        }
    }

    $state = array(
        'last_collect_at' => cn_now(),
        'etag' => cn_s($resp['etag'], 250),
        'last_modified' => cn_s($resp['last_modified'], 250),
        'last_status' => 'ok',
        'last_message' => '抓取 ' . $r['fetched'] . ' 条，新增 ' . $r['added'] . ' 条，全文 ' . $r['fulltext'] . ' 篇',
        'fail_count' => 0,
        'total_items' => intval($source['total_items']) + $r['added'],
    );
    cn_update_source_state($source['id'], $state);
    $r['message'] = $state['last_message'];
    cn_collect_log($source, 'ok', $r['fetched'], $r['added'], $r['message']);
    return $r;
}

/** 采集全部已启用来源 */
function cn_collect_all($options = array())
{
    $sources = cn_db_all('SELECT * FROM ' . cn_table('sources') . ' WHERE enabled = 1 ORDER BY id ASC');
    $summary = array(
        'sources' => count($sources), 'fetched' => 0, 'added' => 0, 'fulltext' => 0,
        'failed' => 0, 'skipped' => 0, 'details' => array(),
    );
    foreach ($sources as $s) {
        $r = cn_collect_source($s, $options);
        $summary['details'][] = $r;
        $summary['fetched'] += $r['fetched'];
        $summary['added'] += $r['added'];
        $summary['fulltext'] += $r['fulltext'];
        if ($r['skipped']) {
            $summary['skipped']++;
        } elseif ($r['status'] === 'error') {
            $summary['failed']++;
        }
    }
    // 仅保留最近 30 天日志
    try {
        cn_db_exec('DELETE FROM ' . cn_table('collect_log') . ' WHERE created_at < ?', array(date('Y-m-d H:i:s', time() - 30 * 86400)));
    } catch (Exception $e) {
        // 忽略
    }
    return $summary;
}

/** 获取非阻塞文件锁，返回句柄或 false；不可写时返回 true（降级为不加锁） */
function cn_acquire_lock($name)
{
    $dir = CN_ROOT . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return true;
    }
    $fp = @fopen($dir . '/' . preg_replace('/[^a-zA-Z0-9_]/', '', $name) . '.lock', 'c');
    if (!$fp) {
        return true;
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false;
    }
    @ftruncate($fp, 0);
    @fwrite($fp, cn_now() . ' pid=' . (function_exists('getmypid') ? getmypid() : 0));
    return $fp;
}
