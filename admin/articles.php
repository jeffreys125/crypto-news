<?php
/** 文章管理 */
require_once dirname(dirname(__FILE__)) . '/admin/_inc.php';
cn_admin_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cn_admin_guard();
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $id = intval(isset($_POST['id']) ? $_POST['id'] : 0);
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : array();

    if ($action === 'top' && $id > 0) {
        cn_db_exec('UPDATE ' . cn_table('articles') . ' SET is_top = 1 - is_top WHERE id = ?', array($id));
        cn_admin_flash('置顶状态已更新');
    } elseif ($action === 'recommend' && $id > 0) {
        cn_db_exec('UPDATE ' . cn_table('articles') . ' SET is_recommend = 1 - is_recommend WHERE id = ?', array($id));
        cn_admin_flash('推荐状态已更新');
    } elseif ($action === 'delete' && $id > 0) {
        cn_db_exec('DELETE FROM ' . cn_table('articles') . ' WHERE id = ?', array($id));
        cn_admin_flash('资讯已删除');
    } elseif ($action === 'batch_delete' && !empty($ids)) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        cn_db_exec('DELETE FROM ' . cn_table('articles') . ' WHERE id IN (' . $marks . ')', $ids);
        cn_admin_flash('已删除 ' . count($ids) . ' 条资讯');
    } elseif ($action === 'batch_top' && !empty($ids)) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        cn_db_exec('UPDATE ' . cn_table('articles') . ' SET is_top = 1 WHERE id IN (' . $marks . ')', $ids);
        cn_admin_flash('已批量置顶');
    } elseif ($action === 'purge_all') {
        cn_db_exec('DELETE FROM ' . cn_table('articles'));
        cn_admin_flash('已清空全部资讯，可重新采集');
    }
    cn_redirect('articles.php');
}

$cat = isset($_GET['cat']) ? (string) $_GET['cat'] : '';
$q = cn_cut(trim(isset($_GET['q']) ? (string) $_GET['q'] : ''), 50, '');
$page = max(1, intval(isset($_GET['page']) ? $_GET['page'] : 1));
$perPage = 20;

$where = array('1 = 1');
$params = array();
if ($cat !== '' && in_array($cat, cn_categories())) {
    $where[] = 'category = ?';
    $params[] = $cat;
}
if ($q !== '') {
    $where[] = '(title LIKE ? OR source_name LIKE ? OR tags LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$whereSql = implode(' AND ', $where);
$total = intval(cn_db_value('SELECT COUNT(*) FROM ' . cn_table('articles') . ' WHERE ' . $whereSql, $params, 0));
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$rows = cn_db_all('SELECT * FROM ' . cn_table('articles') . ' WHERE ' . $whereSql
    . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset, $params);

cn_admin_head('文章管理', 'articles');
echo '<div class="panel"><form class="filter-bar" method="get" action="articles.php">';
echo '<select name="cat"><option value="">全部栏目</option>';
foreach (cn_categories() as $c) {
    echo '<option value="' . h($c) . '"' . ($cat === $c ? ' selected' : '') . '>' . h($c) . '</option>';
}
echo '</select><input type="text" name="q" value="' . h($q) . '" placeholder="标题 / 来源 / 标签" style="width:220px">';
echo '<button class="btn btn-sm" type="submit">筛选</button>';
echo '<a class="btn btn-sm" href="articles.php">重置</a>';
echo '<span class="dim small" style="margin-left:auto">共 ' . $total . ' 条</span></form>';

echo '<div class="table-wrap"><table class="data"><thead><tr>'
    . '<th style="width:28px"><input type="checkbox" id="checkAll"></th><th style="width:52px">ID</th><th>标题</th>'
    . '<th>来源</th><th>栏目</th><th>发布时间</th><th>浏览</th><th>标记</th><th>操作</th></tr></thead><tbody>';
if (empty($rows)) {
    echo '<tr><td colspan="9" class="empty">暂无资讯</td></tr>';
}
foreach ($rows as $r) {
    echo '<tr><td><input type="checkbox" form="batchForm" name="ids[]" value="' . intval($r['id']) . '"></td>';
    echo '<td class="dim">' . intval($r['id']) . '</td>';
    echo '<td><a class="link ellipsis" href="' . cn_url('article', array('id' => $r['id'])) . '" target="_blank" title="' . h($r['title']) . '">'
        . h(cn_cut($r['title'], 54)) . '</a></td>';
    echo '<td class="muted nowrap">' . h($r['source_name']) . '</td><td class="muted nowrap">' . h($r['category']) . '</td>';
    echo '<td class="dim small nowrap">' . h($r['published_at']) . '</td><td class="dim">' . intval($r['views']) . '</td>';
    echo '<td class="nowrap">' . ($r['is_top'] ? '<span class="badge badge-warn">置顶</span> ' : '')
        . ($r['is_recommend'] ? '<span class="badge badge-ok">推荐</span>' : '') . '</td><td class="actions">';
    echo '<form class="inline-form" method="post" action="articles.php">' . cn_csrf_field()
        . '<input type="hidden" name="action" value="top"><input type="hidden" name="id" value="' . intval($r['id']) . '">'
        . '<button type="submit">' . ($r['is_top'] ? '取消置顶' : '置顶') . '</button></form> ';
    echo '<form class="inline-form" method="post" action="articles.php">' . cn_csrf_field()
        . '<input type="hidden" name="action" value="recommend"><input type="hidden" name="id" value="' . intval($r['id']) . '">'
        . '<button type="submit">' . ($r['is_recommend'] ? '取消推荐' : '推荐') . '</button></form> ';
    echo '<form class="inline-form" method="post" action="articles.php" onsubmit="return confirm(\'确认删除该条资讯？\')">'
        . cn_csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . intval($r['id']) . '">'
        . '<button type="submit" class="danger">删除</button></form>';
    echo '</td></tr>';
}
echo '</tbody></table></div></div>';

echo '<form id="batchForm" method="post" action="articles.php" class="panel" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">'
    . cn_csrf_field()
    . '<button class="btn btn-sm" type="submit" name="action" value="batch_top">批量置顶</button>'
    . '<button class="btn btn-sm btn-danger" type="submit" name="action" value="batch_delete" onclick="return confirm(\'确认删除选中的资讯？\')">批量删除</button>'
    . '<span class="dim small">勾选上方表格中的条目后操作</span></form>';

$pages = array();
for ($i = 1; $i <= $totalPages; $i++) {
    if ($i <= 2 || $i >= $totalPages - 1 || abs($i - $page) <= 2) {
        $pages[] = $i;
    }
}
if ($totalPages > 1) {
    echo '<div class="pagination" style="justify-content:flex-start">';
    foreach ($pages as $i) {
        $url = 'articles.php?page=' . $i . ($cat !== '' ? '&amp;cat=' . rawurlencode($cat) : '') . ($q !== '' ? '&amp;q=' . rawurlencode($q) : '');
        echo '<a class="page-btn' . ($i == $page ? ' current' : '') . '" href="' . $url . '">' . $i . '</a>';
    }
    echo '</div>';
}

echo '<div class="panel" style="margin-top:24px"><h3 class="panel-title">危险操作</h3>'
    . '<p class="muted small">清空后所有已采集资讯将被删除，且相同 URL 可以重新被采集入库。</p>'
    . '<form method="post" action="articles.php" onsubmit="return confirm(\'确认清空全部资讯？此操作不可恢复\')">'
    . cn_csrf_field() . '<input type="hidden" name="action" value="purge_all">'
    . '<button class="btn btn-danger btn-sm" type="submit">清空全部资讯</button></form></div>';
?>
<script>
document.getElementById('checkAll').addEventListener('change', function () {
  var boxes = document.querySelectorAll('input[form="batchForm"][name="ids[]"]');
  for (var i = 0; i < boxes.length; i++) boxes[i].checked = this.checked;
});
</script>
<?php
cn_admin_foot();
