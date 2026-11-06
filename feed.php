<?php
/** Outbound aggregated RSS - includes the sanitized full article body. */
require_once dirname(__FILE__) . '/inc/bootstrap.php';

function cn_cdata($s)
{
    return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', (string) $s) . ']]>';
}

$site = cn_setting('site_name', 'CryptoIndex');
$desc = cn_setting('site_desc', 'Crypto news aggregator with full articles from public RSS/Atom feeds.');
$base = cn_base_url();
$rows = cn_db_all('SELECT * FROM ' . cn_table('articles')
    . ' WHERE status = 1 ORDER BY published_at DESC, id DESC LIMIT 30');

header('Content-Type: application/rss+xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" '
    . 'xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel>' . "\n";
echo '<title>' . cn_cdata($site) . '</title>' . "\n";
echo '<link>' . cn_cdata($base . '/') . '</link>' . "\n";
echo '<description>' . cn_cdata($desc) . '</description>' . "\n";
echo '<language>en-us</language>' . "\n";
echo '<lastBuildDate>' . date(DATE_RSS) . '</lastBuildDate>' . "\n";
echo '<atom:link href="' . h($base . '/feed.php') . '" rel="self" type="application/rss+xml" />' . "\n";
foreach ($rows as $row) {
    echo '<item>' . "\n";
    echo '<title>' . cn_cdata($row['title']) . '</title>' . "\n";
    echo '<link>' . cn_cdata($row['url']) . '</link>' . "\n";
    echo '<guid isPermaLink="false">' . cn_cdata($base . '/article.php?id=' . intval($row['id'])) . '</guid>' . "\n";
    echo '<pubDate>' . date(DATE_RSS, strtotime($row['published_at'])) . '</pubDate>' . "\n";
    echo '<source url="' . h($row['url']) . '">' . cn_cdata($row['source_name']) . '</source>' . "\n";
    echo '<category>' . cn_cdata($row['category']) . '</category>' . "\n";
    echo '<description>' . cn_cdata($row['summary']) . '</description>' . "\n";
    $full = isset($row['content']) ? (string) $row['content'] : '';
    if ($full !== '') {
        echo '<content:encoded>' . cn_cdata($full) . '</content:encoded>' . "\n";
    }
    echo '</item>' . "\n";
}
echo '</channel></rss>';
