<?php
/** 前台模板函数 */
if (!defined('CN_ROOT')) {
    die('Access denied');
}

/** 外链安全检查：仅允许 http/https */
function cn_safe_external($url)
{
    $url = trim((string) $url);
    return preg_match('#^https?://#i', $url) ? $url : '';
}

/** 页面头部 */
function cn_head($title, $description = '', $keywords = '', $currentCat = '')
{
    $site = cn_setting('site_name', '链讯 CryptoIndex');
    $desc = $description !== '' ? $description : cn_setting('site_desc', '虚拟货币与区块链资讯聚合索引');
    $kw = $keywords !== '' ? $keywords : cn_setting('site_keywords', '区块链资讯,加密货币,虚拟货币,行业动态');
    $pageTitle = ($title === '' ? $site : $title . ' - ' . $site);
    $notice = trim(cn_setting('site_notice', ''));
    $market = cn_market_fetch();

    echo '<!doctype html>' . "\n";
    echo '<html lang="zh-CN"><head><meta charset="utf-8">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    echo '<title>' . h($pageTitle) . '</title>' . "\n";
    echo '<meta name="description" content="' . h($desc) . '">' . "\n";
    echo '<meta name="keywords" content="' . h($kw) . '">' . "\n";
    echo '<meta name="robots" content="index,follow">' . "\n";
    echo '<link rel="alternate" type="application/rss+xml" title="' . h($site) . ' RSS" href="' . cn_url('feed') . '">' . "\n";
    echo '<link rel="stylesheet" href="' . cn_base_url() . '/assets/style.css?v=' . CN_VERSION . '">' . "\n";
    echo '</head><body>' . "\n";

    // 顶部行情条（无数据或后台关闭时自动隐藏）
    if (!empty($market)) {
        echo '<div class="ticker"><div class="ticker-inner"><span class="ticker-label">行情</span>';
        foreach ($market as $m) {
            $change = floatval($m['change']);
            $cls = $change >= 0 ? 'up' : 'down';
            $sign = $change >= 0 ? '+' : '';
            $price = $m['price'] >= 1000 ? '$' . number_format($m['price'], 2)
                : ($m['price'] >= 1 ? '$' . number_format($m['price'], 2) : '$' . number_format($m['price'], 6));
            echo '<span class="ticker-item"><b>' . h($m['symbol']) . '</b> '
                . '<span class="ticker-price">' . $price . '</span> '
                . '<span class="' . $cls . '">' . $sign . number_format($change, 2) . '%</span></span>';
        }
        echo '<span class="ticker-tip">数据仅供参考，不构成投资建议</span></div></div>' . "\n";
    }

    echo '<header class="site-header"><div class="container header-inner">';
    echo '<a class="brand" href="' . cn_url('index') . '"><span class="brand-mark"></span><span class="brand-text">' . h($site) . '</span></a>';
    echo '<nav class="nav"><a href="' . cn_url('index') . '"' . ($currentCat === '' ? ' class="active"' : '') . '>首页</a>';
    foreach (cn_categories() as $cat) {
        $url = cn_url('index', array('cat' => $cat));
        echo '<a href="' . $url . '"' . ($currentCat === $cat ? ' class="active"' : '') . '>' . h($cat) . '</a>';
    }
    echo '</nav>';
    echo '<form class="search-box" action="' . cn_base_url() . '/search.php" method="get">'
        . '<input type="search" name="q" placeholder="搜索资讯…" value="' . h(isset($_GET['q']) ? $_GET['q'] : '') . '" maxlength="50">'
        . '<button type="submit" aria-label="搜索">搜索</button></form>';
    echo '</div></header>' . "\n";

    if ($notice !== '') {
        echo '<div class="notice"><div class="container">' . h($notice) . '</div></div>' . "\n";
    }
    echo '<main class="container layout">' . "\n";
}

/** 页面底部 */
function cn_foot()
{
    $site = cn_setting('site_name', '链讯 CryptoIndex');
    $disclaimer = cn_setting('disclaimer', '本站为技术性信息聚合索引，仅收录公开资讯的标题与摘要并保留原文链接，不存储、不转载全文，版权归原作者及原媒体所有。内容不构成任何投资建议，不提供交易服务，请遵守所在国家或地区的法律法规。');
    echo '</main>' . "\n";
    echo '<footer class="site-footer"><div class="container">';
    echo '<div class="footer-top"><div><div class="footer-brand">' . h($site) . '</div>'
        . '<p class="footer-desc">公开资讯聚合 · 保留原文链接 · 定时自动采集</p></div>'
        . '<div class="footer-links"><a href="' . cn_url('feed') . '">RSS 订阅</a>'
        . '<a href="' . cn_url('admin') . '">管理后台</a>'
        . '<a href="' . cn_url('search') . '">资讯搜索</a></div></div>';
    echo '<p class="disclaimer">' . h($disclaimer) . '</p>';
    echo '<p class="copyright">&copy; ' . date('Y') . ' ' . h($site) . ' · Powered by CryptoIndex ' . CN_VERSION . '</p>';
    echo '</div></footer>' . "\n";
    echo '<button id="backTop" class="back-top" aria-label="返回顶部">&uarr;</button>' . "\n";
    echo '<script src="' . cn_base_url() . '/assets/app.js?v=' . CN_VERSION . '"></script>' . "\n";
    echo '</body></html>';
}

/** 文章卡片 */
function cn_article_card($row, $showThumb = true)
{
    $url = cn_url('article', array('id' => intval($row['id'])));
    $origin = cn_safe_external($row['url']);
    $thumb = cn_safe_external($row['thumb']);
    echo '<article class="card">';
    if ($showThumb && $thumb !== '') {
        echo '<a class="card-thumb" href="' . $url . '"><img src="' . h($thumb) . '" alt="" loading="lazy" referrerpolicy="no-referrer"></a>';
    }
    echo '<div class="card-body">';
    echo '<div class="card-meta"><span class="badge">' . h($row['category']) . '</span>'
        . '<span class="source">' . h($row['source_name']) . '</span>'
        . '<span class="dot">·</span><time>' . h(cn_time_ago($row['published_at'])) . '</time>';
    if (!empty($row['is_top'])) {
        echo '<span class="badge badge-top">置顶</span>';
    }
    echo '</div>';
    echo '<h2 class="card-title"><a href="' . $url . '">' . h($row['title']) . '</a></h2>';
    if ($row['summary'] !== '') {
        echo '<p class="card-summary">' . h($row['summary']) . '</p>';
    }
    echo '<div class="card-foot">';
    if ($row['tags'] !== '') {
        foreach (explode(',', $row['tags']) as $tag) {
            if ($tag !== '') {
                echo '<span class="tag">#' . h($tag) . '</span>';
            }
        }
    }
    echo '<span class="card-actions">';
    echo '<a href="' . $url . '">查看摘要</a>';
    if ($origin !== '') {
        echo '<a class="origin" href="' . h($origin) . '" target="_blank" rel="nofollow noopener noreferrer">阅读原文 &rarr;</a>';
    }
    echo '</span></div></div></article>' . "\n";
}

/** 右侧栏 */
function cn_sidebar($activeCat = '')
{
    echo '<aside class="sidebar">' . "\n";

    echo '<section class="panel"><h3 class="panel-title">热门资讯</h3><ol class="hot-list">';
    $hot = cn_db_all('SELECT id, title, published_at FROM ' . cn_table('articles')
        . ' WHERE status = 1 ORDER BY views DESC, published_at DESC LIMIT 6');
    if (empty($hot)) {
        echo '<li class="empty">暂无数据，请先在后台执行采集</li>';
    }
    $n = 1;
    foreach ($hot as $r) {
        echo '<li><span class="hot-num">' . $n++ . '</span><a href="' . cn_url('article', array('id' => $r['id'])) . '">' . h(cn_cut($r['title'], 46)) . '</a></li>';
    }
    echo '</ol></section>' . "\n";

    echo '<section class="panel"><h3 class="panel-title">资讯分类</h3><div class="cat-list">';
    foreach (cn_categories() as $cat) {
        $cnt = cn_db_value('SELECT COUNT(*) FROM ' . cn_table('articles') . ' WHERE status = 1 AND category = ?', array($cat), 0);
        echo '<a class="' . ($activeCat === $cat ? 'active' : '') . '" href="' . cn_url('index', array('cat' => $cat)) . '">'
            . h($cat) . '<em>' . intval($cnt) . '</em></a>';
    }
    echo '</div></section>' . "\n";

    echo '<section class="panel"><h3 class="panel-title">收录来源</h3><div class="source-list">';
    $sources = cn_db_all('SELECT name, site_url, last_status FROM ' . cn_table('sources') . ' WHERE enabled = 1 ORDER BY id ASC LIMIT 12');
    foreach ($sources as $s) {
        $site = cn_safe_external($s['site_url']);
        if ($site !== '') {
            echo '<a href="' . h($site) . '" target="_blank" rel="nofollow noopener">' . h($s['name']) . '</a>';
        } else {
            echo '<span>' . h($s['name']) . '</span>';
        }
    }
    echo '</div></section>' . "\n";

    echo '<section class="panel panel-tip"><h3 class="panel-title">关于本站</h3>'
        . '<p>本站仅收录公开 RSS 的标题、摘要与原文链接，完整内容请前往原站阅读。资讯版权归原作者所有。</p></section>' . "\n";
    echo '</aside>' . "\n";
}
