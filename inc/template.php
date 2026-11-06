<?php
/** Front-end template helpers (English UI) */
if (!defined('CN_ROOT')) {
    die('Access denied');
}

/** Only http(s) links are considered safe for external URLs */
function cn_safe_external($url)
{
    $url = trim((string) $url);
    return preg_match('#^https?://#i', $url) ? $url : '';
}

/** Page header */
function cn_head($title, $description = '', $keywords = '', $currentCat = '')
{
    $site = cn_setting('site_name', 'CryptoIndex');
    $desc = $description !== '' ? $description : cn_setting('site_desc', 'Crypto news aggregator with full articles from public RSS/Atom feeds.');
    $kw = $keywords !== '' ? $keywords : cn_setting('site_keywords', 'crypto news, bitcoin, ethereum, blockchain, markets, regulation');
    $pageTitle = ($title === '' ? $site : $title . ' - ' . $site);
    $notice = trim(cn_setting('site_notice', ''));
    $market = cn_market_fetch();

    echo '<!doctype html>' . "\n";
    echo '<html lang="en"><head><meta charset="utf-8">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    echo '<title>' . h($pageTitle) . '</title>' . "\n";
    echo '<meta name="description" content="' . h($desc) . '">' . "\n";
    echo '<meta name="keywords" content="' . h($kw) . '">' . "\n";
    echo '<meta name="robots" content="index,follow">' . "\n";
    echo '<link rel="alternate" type="application/rss+xml" title="' . h($site) . ' RSS" href="' . cn_url('feed') . '">' . "\n";
    echo '<link rel="stylesheet" href="' . cn_base_url() . '/assets/style.css?v=' . CN_VERSION . '">' . "\n";
    echo '</head><body>' . "\n";

    // Optional market ticker (hidden when disabled or no data)
    if (!empty($market)) {
        echo '<div class="ticker"><div class="ticker-inner"><span class="ticker-label">MARKETS</span>';
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
        echo '<span class="ticker-tip">Prices are for reference only, not investment advice</span></div></div>' . "\n";
    }

    echo '<header class="site-header"><div class="container header-inner">';
    echo '<a class="brand" href="' . cn_url('index') . '"><span class="brand-mark"></span><span class="brand-text">' . h($site) . '</span></a>';
    echo '<nav class="nav"><a href="' . cn_url('index') . '"' . ($currentCat === '' ? ' class="active"' : '') . '>Home</a>';
    foreach (cn_categories() as $cat) {
        $url = cn_url('index', array('cat' => $cat));
        echo '<a href="' . $url . '"' . ($currentCat === $cat ? ' class="active"' : '') . '>' . h($cat) . '</a>';
    }
    echo '</nav>';
    echo '<form class="search-box" action="' . cn_base_url() . '/search.php" method="get">'
        . '<input type="search" name="q" placeholder="Search news..." value="' . h(isset($_GET['q']) ? $_GET['q'] : '') . '" maxlength="50">'
        . '<button type="submit" aria-label="Search">Search</button></form>';
    echo '</div></header>' . "\n";

    if ($notice !== '') {
        echo '<div class="notice"><div class="container">' . h($notice) . '</div></div>' . "\n";
    }
    echo '<main class="container layout">' . "\n";
}

/** Page footer */
function cn_foot()
{
    $site = cn_setting('site_name', 'CryptoIndex');
    $disclaimer = cn_setting('disclaimer', 'This site aggregates and mirrors full articles from public RSS/Atom feeds for technical indexing purposes. All content remains the property of its original authors and publishers; source attribution and the original URL are retained. This site does not provide trading, exchange, or investment advisory services, and nothing here constitutes investment advice. Please comply with the laws and regulations of your jurisdiction.');
    echo '</main>' . "\n";
    echo '<footer class="site-footer"><div class="container">';
    echo '<div class="footer-top"><div><div class="footer-brand">' . h($site) . '</div>'
        . '<p class="footer-desc">Aggregated crypto news &middot; Full articles &middot; Auto-updated</p></div>'
        . '<div class="footer-links"><a href="' . cn_url('feed') . '">RSS Feed</a>'
        . '<a href="' . cn_url('admin') . '">Admin</a>'
        . '<a href="' . cn_url('search') . '">Search</a></div></div>';
    echo '<p class="disclaimer">' . h($disclaimer) . '</p>';
    echo '<p class="copyright">&copy; ' . date('Y') . ' ' . h($site) . ' &middot; Powered by CryptoIndex ' . CN_VERSION . '</p>';
    echo '</div></footer>' . "\n";
    echo '<button id="backTop" class="back-top" aria-label="Back to top">&uarr;</button>' . "\n";
    echo '<script src="' . cn_base_url() . '/assets/app.js?v=' . CN_VERSION . '"></script>' . "\n";
    echo '</body></html>';
}

/** Article card */
function cn_article_card($row, $showThumb = true)
{
    $url = cn_url('article', array('id' => intval($row['id'])));
    $thumb = cn_safe_external($row['thumb']);
    echo '<article class="card">';
    if ($showThumb && $thumb !== '') {
        echo '<a class="card-thumb" href="' . $url . '"><img src="' . h($thumb) . '" alt="" loading="lazy" referrerpolicy="no-referrer"></a>';
    }
    echo '<div class="card-body">';
    echo '<div class="card-meta"><span class="badge">' . h($row['category']) . '</span>'
        . '<span class="source">' . h($row['source_name']) . '</span>'
        . '<span class="dot">&middot;</span><time>' . h(cn_time_ago($row['published_at'])) . '</time>';
    if (!empty($row['is_top'])) {
        echo '<span class="badge badge-top">Pinned</span>';
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
    echo '<span class="card-actions"><a href="' . $url . '">Read full story &rarr;</a></span>';
    echo '</div></div></article>' . "\n";
}

/**
 * Render the article body.
 * $row['content'] is HTML that has already been sanitized by inc/fulltext.php
 * (tag/attribute whitelist, no scripts, no dangerous protocols) -> safe to echo.
 */
function cn_article_body($row)
{
    $content = isset($row['content']) ? (string) $row['content'] : '';
    $fetched = isset($row['content_fetched']) ? intval($row['content_fetched']) : 0;
    $summary = isset($row['summary']) ? (string) $row['summary'] : '';

    if ($content !== '') {
        $body = '<div class="article-content">' . $content . '</div>';
        if ($fetched !== 1) {
            $body .= '<p class="content-note">Full text could not be retrieved from the publisher; the feed excerpt is shown above.</p>';
        }
        return $body;
    }
    if ($summary !== '') {
        return '<div class="article-summary">' . h($summary) . '</div>'
            . '<p class="content-note">Full text is not available for this article yet. It will be filled in on the next collection run.</p>';
    }
    return '<div class="article-summary" style="color:var(--muted)">This article has no body text available.</div>';
}

/** Right sidebar */
function cn_sidebar($activeCat = '')
{
    echo '<aside class="sidebar">' . "\n";

    echo '<section class="panel"><h3 class="panel-title">Trending</h3><ol class="hot-list">';
    $hot = cn_db_all('SELECT id, title, published_at FROM ' . cn_table('articles')
        . ' WHERE status = 1 ORDER BY views DESC, published_at DESC LIMIT 6');
    if (empty($hot)) {
        echo '<li class="empty">No articles yet. Run a collection from the admin panel.</li>';
    }
    $n = 1;
    foreach ($hot as $r) {
        echo '<li><span class="hot-num">' . $n++ . '</span><a href="' . cn_url('article', array('id' => $r['id'])) . '">' . h(cn_cut($r['title'], 46)) . '</a></li>';
    }
    echo '</ol></section>' . "\n";

    echo '<section class="panel"><h3 class="panel-title">Categories</h3><div class="cat-list">';
    foreach (cn_categories() as $cat) {
        $cnt = cn_db_value('SELECT COUNT(*) FROM ' . cn_table('articles') . ' WHERE status = 1 AND category = ?', array($cat), 0);
        echo '<a class="' . ($activeCat === $cat ? 'active' : '') . '" href="' . cn_url('index', array('cat' => $cat)) . '">'
            . h($cat) . '<em>' . intval($cnt) . '</em></a>';
    }
    echo '</div></section>' . "\n";

    echo '<section class="panel"><h3 class="panel-title">Sources</h3><div class="source-list">';
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

    echo '<section class="panel panel-tip"><h3 class="panel-title">About</h3>'
        . '<p>Full articles are collected from public RSS/Atom feeds and mirrored here for reading. All content belongs to its original authors and publishers; every article keeps its source attribution.</p></section>' . "\n";
    echo '</aside>' . "\n";
}
