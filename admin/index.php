<?php
/** 仪表盘 */
require_once dirname(dirname(__FILE__)) . '/admin/_inc.php';
cn_admin_auth();

$total = intval(cn_db_value('SELECT COUNT(*) FROM ' . cn_table('articles') . ' WHERE status = 1', array(), 0));
$today = intval(cn_db_value('SELECT COUNT(*) FROM ' . cn_table('articles') . ' WHERE created_at >= ?',
    array(date('Y-m-d') . ' 00:00:00'), 0));
$srcTotal = intval(cn_db_value('SELECT COUNT(*) FROM ' . cn_table('sources'), array(), 0));
$srcOn = intval(cn_db_value('SELECT COUNT(*) FROM ' . cn_table('sources') . ' WHERE enabled = 1', array(), 0));
$lastCollect = cn_db_value('SELECT MAX(last_collect_at) FROM ' . cn_table('sources'), array(), '');
$logs = cn_db_all('SELECT * FROM ' . cn_table('collect_log') . ' ORDER BY id DESC LIMIT 8');
$cronPath = dirname(dirname(__FILE__)) . '/cron.php';
$cronKey = cn_setting('cron_key', '');

$checks = array(
    'PHP 版本' => PHP_VERSION . (version_compare(PHP_VERSION, '5.4.0', '>=') ? ' (满足)' : ' (过低，需 >= 5.4)'),
    'MySQL 版本' => cn_mysql_version(),
    'pdo_mysql' => extension_loaded('pdo_mysql') ? '已启用' : '缺失（必须）',
    'cURL' => function_exists('curl_init') ? '已启用' : '未启用（将回退 file_get_contents）',
    'SimpleXML' => extension_loaded('simplexml') ? '已启用' : '缺失（必须）',
    'mbstring' => extension_loaded('mbstring') ? '已启用' : '未启用（可选）',
    '数据/锁目录可写' => is_writable(CN_ROOT . '/data') ? '是' : '否（请创建 data 目录并给写权限）',
    'curl 出站网络' => '可在「立即采集」页测试各来源连通性',
);

cn_admin_head('仪表盘', 'index');
echo '<div class="stats">';
echo '<div class="stat"><label>收录资讯</label><strong>' . $total . '</strong><small>已上线展示</small></div>';
echo '<div class="stat"><label>今日新增</label><strong>' . $today . '</strong><small>' . date('Y-m-d') . '</small></div>';
echo '<div class="stat"><label>资讯来源</label><strong>' . $srcOn . ' / ' . $srcTotal . '</strong><small>启用 / 总数</small></div>';
echo '<div class="stat"><label>最近采集</label><strong style="font-size:16px">' . ($lastCollect ? h(cn_time_ago($lastCollect)) : '尚未执行') . '</strong><small>' . ($lastCollect ? h($lastCollect) : '请配置定时任务') . '</small></div>';
echo '</div>';

echo '<div class="panel"><div class="panel-head"><h3>快速操作</h3><span class="dim small">强制采集会忽略采集间隔</span></div>';
echo '<form method="post" action="collect.php" style="display:inline">' . cn_csrf_field()
    . '<input type="hidden" name="action" value="run_all">'
    . '<button class="btn btn-primary" type="submit" onclick="return confirm(\'立即采集全部已启用来源？\')">立即采集全部来源</button></form> ';
echo '<a class="btn" href="sources.php">管理来源</a> <a class="btn" href="settings.php">站点设置</a>';
echo '</div>';

echo '<h2>定时采集配置</h2>';
echo '<div class="panel"><p class="muted small">推荐在服务器 crontab 中加入以下任务（每 15 分钟一次）。栏目间隔可在「资讯来源」中单独设置，程序会自动跳过未到间隔的来源。</p>';
echo '<div class="code">*/15 * * * * /usr/bin/php ' . h($cronPath) . ' > /dev/null 2>&1</div>';
echo '<p class="muted small" style="margin:12px 0 6px">没有 crontab 权限时，可用外部监控/计划任务访问以下 Web 触发地址（请保密该密钥）：</p>';
echo '<div class="code">' . h(cn_base_url() . '/cron.php?key=' . $cronKey) . '</div>';
echo '<p class="dim small" style="margin-top:10px">也可在命令行手动执行：<b>php ' . h($cronPath) . ' --force</b></p></div>';

echo '<h2>运行环境自检</h2><div class="panel"><div class="kv">';
foreach ($checks as $k => $v) {
    echo '<div><span>' . h($k) . '：</span>' . h($v) . '</div>';
}
echo '</div></div>';

echo '<h2>最近采集日志</h2>';
echo '<div class="panel panel-tight"><div class="table-wrap"><table class="data"><thead><tr>'
    . '<th>时间</th><th>来源</th><th>状态</th><th>抓取</th><th>新增</th><th>说明</th></tr></thead><tbody>';
if (empty($logs)) {
    echo '<tr><td colspan="6" class="empty">暂无采集记录</td></tr>';
}
foreach ($logs as $l) {
    echo '<tr><td class="nowrap dim">' . h($l['created_at']) . '</td><td>' . h($l['source_name']) . '</td>'
        . '<td>' . cn_admin_badge($l['status']) . '</td><td>' . intval($l['fetched']) . '</td>'
        . '<td>' . intval($l['added']) . '</td><td class="muted">' . h(cn_cut($l['message'], 60)) . '</td></tr>';
}
echo '</tbody></table></div></div>';
echo '<p class="dim small"><a href="logs.php">查看全部日志 &rarr;</a></p>';
cn_admin_foot();
