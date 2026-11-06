<?php
/** Article detail page - renders the sanitized full text in-site. */
require_once dirname(__FILE__) . '/inc/bootstrap.php';
require_once CN_ROOT . '/inc/template.php';

$id = intval(isset($_GET['id']) ? $_GET['id'] : 0);
$row = $id > 0 ? cn_db_one('SELECT * FROM ' . cn_table('articles') . ' WHERE id = ? AND status = 1', array($id)) : null;

if (!$row) {
    cn_head('Article not found');
    echo '<section class="main"><div class="empty-state"><div class="big">&#9671;</div>'
        . '<p>This article does not exist or has been removed.</p>'
        . '<p style="font-size:13px"><a href="' . cn_url('index') . '">Back to home</a></p></div></section>';
    cn_sidebar();
    cn_foot();
    exit;
}

cn_db_exec('UPDATE ' . cn_table('articles') . ' SET views = views + 1 WHERE id = ?', array($id));
$origin = cn_safe_external($row['url']);
$thumb = cn_safe_external($row['thumb']);
$originHost = $origin !== '' ? parse_url($origin, PHP_URL_HOST) : '';

cn_head($row['title'], cn_cut($row['summary'], 150), $row['tags'], $row['category']);
echo '<section class="main">' . "\n";
echo '<div class="breadcrumb"><a href="' . cn_url('index') . '">Home</a> / '
    . '<a href="' . cn_url('index', array('cat' => $row['category'])) . '">' . h($row['category']) . '</a> / Article</div>';

echo '<article class="article-box">';
echo '<h1>' . h($row['title']) . '</h1>';
echo '<div class="article-meta">'
    . '<span>Source: ' . h($row['source_name']) . '</span>'
    . ($row['author'] !== '' ? '<span>By ' . h($row['author']) . '</span>' : '')
    . '<span>Published: ' . h($row['published_at']) . '</span>'
    . '<span>Collected: ' . h($row['created_at']) . '</span>'
    . '<span>Views: ' . intval($row['views'] + 1) . '</span></div>';
if ($thumb !== '') {
    echo '<img class="article-hero" src="' . h($thumb) . '" alt="" referrerpolicy="no-referrer">';
}

// Full text (sanitized HTML) or a graceful fallback to the feed excerpt
echo cn_article_body($row);

if ($row['tags'] !== '') {
    echo '<div class="article-tags">';
    foreach (explode(',', $row['tags']) as $tag) {
        if ($tag !== '') {
            echo '<span class="tag">#' . h($tag) . '</span>';
        }
    }
    echo '</div>';
}

// Neutral source attribution - no "read at source" call-to-action
echo '<div class="source-line">';
echo '<span>Source: <b>' . h($row['source_name']) . '</b></span>';
if ($origin !== '') {
    echo '<span>Original URL: <a href="' . h($origin) . '" target="_blank" rel="nofollow noopener noreferrer">'
        . h($originHost !== '' ? $originHost : $origin) . '</a></span>';
}
echo '</div>';
echo '</article>' . "\n";

$related = cn_db_all('SELECT id, title, published_at FROM ' . cn_table('articles')
    . ' WHERE status = 1 AND category = ? AND id <> ? ORDER BY published_at DESC LIMIT 6',
    array($row['category'], $id));
if (!empty($related)) {
    echo '<div class="related"><h2 class="section-title">Related News</h2><div class="related-list">';
    foreach ($related as $r) {
        echo '<a href="' . cn_url('article', array('id' => $r['id'])) . '">' . h($r['title'])
            . ' <span style="color:var(--dim);font-size:12px">' . h(cn_time_ago($r['published_at'])) . '</span></a>';
    }
    echo '</div></div>';
}
echo '</section>' . "\n";
cn_sidebar($row['category']);
cn_foot();
