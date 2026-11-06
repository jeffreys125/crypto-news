<?php
/** 首页 / 分类列表 */
require_once dirname(__FILE__) . '/inc/bootstrap.php';
require_once CN_ROOT . '/inc/template.php';

$cat = isset($_GET['cat']) ? trim((string) $_GET['cat']) : '';
if (!in_array($cat, cn_categories())) {
    $cat = '';
}
$page = max(1, intval(isset($_GET['page']) ? $_GET['page'] : 1));
$perPage = max(5, min(50, intval(cn_setting('per_page', '20'))));

$where = 'status = 1';
$params = array();
if ($cat !== '') {
    $where .= ' AND category = ?';
    $params[] = $cat;
}
$total = intval(cn_db_value('SELECT COUNT(*) FROM ' . cn_table('articles') . ' WHERE ' . $where, $params, 0));
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

// LIMIT 使用已强制转换的整数直接拼接，兼容 PDO 参数绑定与 MySQL 5.5
$sql = 'SELECT * FROM ' . cn_table('articles') . ' WHERE ' . $where
    . ' ORDER BY is_top DESC, published_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
$rows = cn_db_all($sql, $params);

$pageTitle = $cat !== '' ? $cat : '最新资讯';
cn_head($cat !== '' ? $cat : '', cn_setting('site_desc', ''), '', $cat);
echo '<section class="main">' . "\n";
echo '<div class="page-head"><h1>' . h($pageTitle) . '</h1>'
    . '<span class="sub">共 ' . $total . ' 条 · 第 ' . $page . '/' . $totalPages . ' 页 · 自动采集更新</span></div>' . "\n";

if (empty($rows)) {
    echo '<div class="empty-state"><div class="big">◇</div><p>还没有采集到资讯</p>'
        . '<p style="font-size:13px">请进入 <a href="' . cn_url('admin') . '">管理后台</a> 执行一次采集，或配置定时任务是自动采集。</p></div>';
} else {
    echo '<div class="article-list">' . "\n";
    foreach ($rows as $row) {
        cn_article_card($row);
    }
    echo '</div>' . "\n";
    $base = $cat !== '' ? cn_url('index', array('cat' => $cat)) : cn_base_url() . '/index.php';
    echo cn_pagination($total, $page, $perPage, $base);
}
echo '</section>' . "\n";
cn_sidebar($cat);
cn_foot();
