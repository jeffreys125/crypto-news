<?php
/** 手动采集 */
require_once dirname(dirname(__FILE__)) . '/admin/_inc.php';
cn_admin_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cn_admin_guard();
    $force = (isset($_POST['action']) && $_POST['action'] === 'run_all');
    $summary = cn_collect_all(array('force' => $force));
    $_SESSION['cn_last_collect'] = $summary;
    $_SESSION['cn_last_collect_at'] = cn_now();
    cn_admin_flash($force ? '强制采集已完成' : '按间隔采集已完成');
    cn_redirect('collect.php');
}

$last = isset($_SESSION['cn_last_collect']) ? $_SESSION['cn_last_collect'] : null;
$lastAt = isset($_SESSION['cn_last_collect_at']) ? $_SESSION['cn_last_collect_at'] : '';
$enabled = intval(cn_db_value('SELECT COUNT(*) FROM ' . cn_table('sources') . ' WHERE enabled = 1', array(), 0));

cn_admin_head('立即采集', 'collect');
echo '<div class="alert alert-info">采集会遍历所有已启用来源（当前 ' . $enabled . ' 个），保存标题、摘要、原文链接与署名，并抓取文章全文（优先 RSS content:encoded，缺失时访问原文页提取正文，清洗后入库）。只对本轮新增文章补齐全文，受「站点设置」中的每源篇数上限约束；连续失败的来源会自动延长采集间隔。</div>';
echo '<div class="panel"><form method="post" action="collect.php" style="display:inline">' . cn_csrf_field()
    . '<input type="hidden" name="action" value="run_all">'
    . '<button class="btn btn-primary" type="submit" onclick="return confirm(\'忽略间隔，立即采集全部来源？\')">强制采集全部来源</button></form> ';
echo '<form method="post" action="collect.php" style="display:inline">' . cn_csrf_field()
    . '<input type="hidden" name="action" value="run_due">'
    . '<button class="btn" type="submit">按设定间隔采集</button></form> ';
echo '<a class="btn" href="sources.php">管理来源</a> <a class="btn" href="logs.php">查看日志</a></div>';

if ($last) {
    echo '<h2>上次执行结果 <span class="dim small">（' . h($lastAt) . '）</span></h2>';
    echo '<div class="stats">'
        . '<div class="stat"><label>来源数</label><strong>' . intval($last['sources']) . '</strong></div>'
        . '<div class="stat"><label>抓取条目</label><strong>' . intval($last['fetched']) . '</strong></div>'
        . '<div class="stat"><label>新增入库</label><strong>' . intval($last['added']) . '</strong></div>'
        . '<div class="stat"><label>全文补齐</label><strong>' . intval(isset($last['fulltext']) ? $last['fulltext'] : 0) . '</strong></div>'
        . '<div class="stat"><label>失败 / 跳过</label><strong>' . intval($last['failed']) . ' / ' . intval($last['skipped']) . '</strong></div>'
        . '</div>';
    echo '<div class="panel panel-tight"><div class="table-wrap"><table class="data"><thead><tr>'
        . '<th>来源</th><th>状态</th><th>抓取</th><th>新增</th><th>全文</th><th>说明</th></tr></thead><tbody>';
    foreach ($last['details'] as $d) {
        $status = $d['skipped'] ? 'muted' : ($d['status'] === 'error' ? 'bad' : ($d['status'] === 'not_modified' ? 'muted' : 'ok'));
        $label = $d['skipped'] ? '跳过' : ($d['status'] === 'error' ? '失败' : ($d['status'] === 'not_modified' ? '未更新' : '正常'));
        echo '<tr><td>' . h($d['name']) . '</td><td><span class="badge badge-' . $status . '">' . $label . '</span></td>'
            . '<td>' . intval($d['fetched']) . '</td><td>' . intval($d['added']) . '</td>'
            . '<td>' . intval(isset($d['fulltext']) ? $d['fulltext'] : 0) . '</td>'
            . '<td class="muted">' . h($d['message']) . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
} else {
    echo '<div class="panel"><div class="empty">尚未手动执行采集，点击上方按钮开始</div></div>';
}

echo '<h2>自动采集说明</h2><div class="panel"><p class="muted small">正式运行请在服务器配置定时任务，无需每次手动点击。开启全文抓取后单次耗时变长，建议间隔 30 分钟：</p>'
    . '<div class="code">*/30 * * * * /usr/bin/php ' . h(dirname(dirname(__FILE__)) . '/cron.php') . ' > /dev/null 2>&1</div>'
    . '<p class="dim small" style="margin-top:12px">定时任务会自动加文件锁防止并发，遵循每个来源的采集间隔，限制每轮补齐全文的篇数，并记录日志。</p></div>';
cn_admin_foot();
