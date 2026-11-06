<?php
/** 资讯搜索 */
require_once dirname(__FILE__) . '/inc/bootstrap.php';
require_once CN_ROOT . '/inc/template.php';

$q = trim(isset($_GET['q']) ? (string) $_GET['q'] : '');
$q = cn_cut($q, 50, '');
$page = max(1, intval(isset($_GET['page']) ? $_GET['page'] : 1));
$perPage = max(5, min(50, intval(cn_setting('per_page', '20'))));

$total = 0;
$rows = array();
if ($q !== '') {
    // 预处理绑定参数，用户输入中的通配符仅影响匹配范围，不构成注入
    $like = '%' . $q . '%';
    $params = array($like, $like, $like);
    $total = intval(cn_db_value(
        'SELECT COUNT(*) FROM ' . cn_table('articles')
        . ' WHERE status = 1 AND (title LIKE ? OR summary LIKE ? OR tags LIKE ?)',
        $params, 0
    ));
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
    $rows = cn_db_all(
        'SELECT * FROM ' . cn_table('articles')
        . ' WHERE status = 1 AND (title LIKE ? OR summary LIKE ? OR tags LIKE ?)'
        . ' ORDER BY published_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
        $params
    );
} else {
    $totalPages = 1;
}

cn_head($q !== '' ? '搜索：' . $q : '搜索资讯', '', '', '');
echo '<section class="main">';
echo '<div class="page-head"><h1>资讯搜索</h1><span class="sub">支持标题、摘要与标签关键词</span></div>';
echo '<form class="search-form" method="get" action="' . cn_base_url() . '/search.php">'
    . '<input type="search" name="q" value="' . h($q) . '" placeholder="输入关键词，例如：Bitcoin、ETF、监管" maxlength="50">'
    . '<button type="submit">搜索</button></form>';
if ($q !== '') {
    echo '<p class="search-hint">关键词「' . h($q) . '」找到 ' . $total . ' 条结果</p>';
    if (empty($rows)) {
        echo '<div class="empty-state"><div class="big">◇</div><p>没有找到相关资讯，换个关键词试试</p></div>';
    } else {
        echo '<div class="article-list">';
        foreach ($rows as $row) {
            cn_article_card($row);
        }
        echo '</div>';
        echo cn_pagination($total, $page, $perPage, cn_base_url() . '/search.php?q=' . rawurlencode($q));
    }
}
echo '</section>';
cn_sidebar();
cn_foot();
