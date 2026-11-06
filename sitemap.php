<?php
/** Simple sitemap, referenced by robots.txt */
require_once dirname(__FILE__) . '/inc/bootstrap.php';

$base = cn_base_url();
$rows = cn_db_all('SELECT id, published_at FROM ' . cn_table('articles')
    . ' WHERE status = 1 ORDER BY published_at DESC LIMIT 500');
header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
echo '<url><loc>' . h($base . '/index.php') . '</loc><changefreq>hourly</changefreq><priority>1.0</priority></url>' . "\n";
foreach (cn_categories() as $cat) {
    echo '<url><loc>' . h($base . '/index.php?cat=' . rawurlencode($cat)) . '</loc><changefreq>daily</changefreq><priority>0.6</priority></url>' . "\n";
}
foreach ($rows as $r) {
    echo '<url><loc>' . h($base . '/article.php?id=' . intval($r['id'])) . '</loc>'
        . '<lastmod>' . date('Y-m-d', strtotime($r['published_at'])) . '</lastmod>'
        . '<changefreq>monthly</changefreq><priority>0.5</priority></url>' . "\n";
}
echo '</urlset>';
