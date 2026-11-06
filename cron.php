<?php
/**
 * 定时采集入口
 *
 * 方式一（推荐，crontab，每 15 分钟执行一次）：
 *   0,15,30,45 * * * * /usr/bin/php /path/to/crypto-news/cron.php > /dev/null 2>&1
 *   追加 --force 可忽略来源间隔（调试用）
 *
 * 方式二（无 crontab 权限时，由外部监控定时访问）：
 *   https://your-domain/cron.php?key=<后台「站点设置」中的 Web 触发密钥>
 */
require_once dirname(__FILE__) . '/inc/bootstrap.php';

$isCli = cn_is_cli();
$argvLocal = isset($argv) && is_array($argv) ? $argv : array();

if (!$isCli) {
    $key = isset($_GET['key']) ? (string) $_GET['key'] : '';
    $stored = cn_setting('cron_key', '');
    if ($stored === '' || !cn_hash_equals($stored, $key)) {
        if (function_exists('http_response_code')) {
            http_response_code(403);
        } else {
            header('HTTP/1.1 403 Forbidden');
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden: invalid cron key\n";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$lock = cn_acquire_lock('collect');
if ($lock === false) {
    echo "[" . cn_now() . "] 已有采集任务正在运行，本次跳过。\n";
    exit(0);
}

$force = in_array('--force', $argvLocal) || (isset($_GET['force']) && $_GET['force'] === '1');
$summary = cn_collect_all(array('force' => $force));

echo "[" . cn_now() . "] CryptoIndex 采集完成 | 来源 " . $summary['sources']
    . " | 抓取 " . $summary['fetched'] . " | 新增 " . $summary['added']
    . " | 失败 " . $summary['failed'] . " | 跳过 " . $summary['skipped'] . "\n";
foreach ($summary['details'] as $d) {
    echo "  - " . $d['name'] . " : " . ($d['skipped'] ? 'SKIP' : strtoupper($d['status']))
        . " fetch=" . $d['fetched'] . " added=" . $d['added'] . " " . $d['message'] . "\n";
}

if (is_resource($lock)) {
    flock($lock, LOCK_UN);
    fclose($lock);
}
