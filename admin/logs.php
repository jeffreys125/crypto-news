<?php
/** 采集日志 */
require_once dirname(dirname(__FILE__)) . '/admin/_inc.php';
cn_admin_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cn_admin_guard();
    if (isset($_POST['action']) && $_POST['action'] === 'clear') {
        cn_db_exec('DELETE FROM ' . cn_table('collect_log'));
        cn_admin_flash('日志已清空');
    }
    cn_redirect('logs.php');
}

$page = max(1, intval(isset($_GET['page']) ? $_GET['page'] : 1));
$perPage = 50;
$total = intval(cn_db_value('SELECT COUNT(*) FROM ' . cn_table('collect_log'), array(), 0));
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$rows = cn_db_all('SELECT * FROM ' . cn_table('collect_log') . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);

cn_admin_head('采集日志', 'logs');
echo '<div class="panel panel-tight"><div class="panel-head" style="padding:16px 18px 0"><h3>共 ' . $total . ' 条记录（保留最近 30 天）</h3>'
    . '<form method="post" action="logs.php" onsubmit="return confirm(\'确认清空全部日志？\')">' . cn_csrf_field()
    . '<input type="hidden" name="action" value="clear"><button class="btn btn-sm btn-danger" type="submit">清空日志</button></form></div>';
echo '<div class="table-wrap"><table class="data"><thead><tr><th>ID</th><th>时间</th><th>来源</th><th>状态</th>'
    . '<th>抓取</th><th>新增</th><th>说明</th></tr></thead><tbody>';
if (empty($rows)) {
    echo '<tr><td colspan="7" class="empty">暂无日志</td></tr>';
}
foreach ($rows as $l) {
    echo '<tr><td class="dim">' . intval($l['id']) . '</td><td class="dim nowrap">' . h($l['created_at']) . '</td>'
        . '<td>' . h($l['source_name']) . '</td><td>' . cn_admin_badge($l['status']) . '</td>'
        . '<td>' . intval($l['fetched']) . '</td><td>' . intval($l['added']) . '</td>'
        . '<td class="muted">' . h($l['message']) . '</td></tr>';
}
echo '</tbody></table></div></div>';
if ($totalPages > 1) {
    echo '<div class="pagination" style="justify-content:flex-start">';
    for ($i = 1; $i <= min($totalPages, 30); $i++) {
        echo '<a class="page-btn' . ($i == $page ? ' current' : '') . '" href="logs.php?page=' . $i . '">' . $i . '</a>';
    }
    echo '</div>';
}
cn_admin_foot();
