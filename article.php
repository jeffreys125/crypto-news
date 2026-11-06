<?php
/** 资讯详情页：仅展示摘要，完整内容引导回原文 */
require_once dirname(__FILE__) . '/inc/bootstrap.php';
require_once CN_ROOT . '/inc/template.php';

$id = intval(isset($_GET['id']) ? $_GET['id'] : 0);
$row = $id > 0 ? cn_db_one('SELECT * FROM ' . cn_table('articles') . ' WHERE id = ? AND status = 1', array($id)) : null;

if (!$row) {
    cn_head('资讯不存在');
    echo '<section class="main"><div class="empty-state"><div class="big">◇</div>'
        . '<p>该资讯不存在或已下线</p><p style="font-size:13px"><a href="' . cn_url('index') . '">返回首页</a></p></div></section>';
    cn_sidebar();
    cn_foot();
    exit;
}

cn_db_exec('UPDATE ' . cn_table('articles') . ' SET views = views + 1 WHERE id = ?', array($id));
$origin = cn_safe_external($row['url']);
$thumb = cn_safe_external($row['thumb']);

cn_head($row['title'], cn_cut($row['summary'], 150), $row['tags'], $row['category']);
echo '<section class="main">' . "\n";
echo '<div class="breadcrumb"><a href="' . cn_url('index') . '">首页</a> / '
    . '<a href="' . cn_url('index', array('cat' => $row['category'])) . '">' . h($row['category']) . '</a> / 正文</div>';

echo '<article class="article-box">';
echo '<h1>' . h($row['title']) . '</h1>';
echo '<div class="article-meta">'
    . '<span>来源：' . h($row['source_name']) . '</span>'
    . ($row['author'] !== '' ? '<span>作者：' . h($row['author']) . '</span>' : '')
    . '<span>发布：' . h($row['published_at']) . '</span>'
    . '<span>收录：' . h($row['created_at']) . '</span>'
    . '<span>浏览：' . intval($row['views'] + 1) . '</span></div>';
if ($thumb !== '') {
    echo '<img class="article-hero" src="' . h($thumb) . '" alt="" referrerpolicy="no-referrer">';
}
if ($row['summary'] !== '') {
    echo '<div class="article-summary">' . h($row['summary']) . '</div>';
} else {
    echo '<div class="article-summary" style="color:var(--muted)">该来源未提供摘要，请前往原文阅读。</div>';
}
if ($row['tags'] !== '') {
    echo '<div class="article-tags">';
    foreach (explode(',', $row['tags']) as $tag) {
        if ($tag !== '') {
            echo '<span class="tag">#' . h($tag) . '</span>';
        }
    }
    echo '</div>';
}
echo '<div class="origin-box"><p>本站仅收录公开资讯的标题与摘要，不存储、不转载全文。完整内容版权归原媒体所有，请前往原文阅读。</p>';
if ($origin !== '') {
    echo '<a class="btn-origin" href="' . h($origin) . '" target="_blank" rel="nofollow noopener noreferrer">前往原文阅读 &rarr;</a>';
    echo '<span class="origin-url">' . h($origin) . '</span>';
}
echo '</div></article>' . "\n";

$related = cn_db_all('SELECT id, title, published_at FROM ' . cn_table('articles')
    . ' WHERE status = 1 AND category = ? AND id <> ? ORDER BY published_at DESC LIMIT 6',
    array($row['category'], $id));
if (!empty($related)) {
    echo '<div class="related"><h2 class="section-title">相关资讯</h2><div class="related-list">';
    foreach ($related as $r) {
        echo '<a href="' . cn_url('article', array('id' => $r['id'])) . '">' . h($r['title'])
            . ' <span style="color:var(--dim);font-size:12px">' . h(cn_time_ago($r['published_at'])) . '</span></a>';
    }
    echo '</div></div>';
}
echo '</section>' . "\n";
cn_sidebar($row['category']);
cn_foot();
