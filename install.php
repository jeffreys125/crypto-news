<?php
/**
 * 安装向导
 * 访问一次即可完成：建表 -> 写入默认设置 -> 创建管理员 -> 预置来源 -> 生成配置。
 * 安装完成后请务必删除本文件（或保留 install.lock，程序会拒绝重复安装）。
 */
define('CN_ROOT', dirname(__FILE__));
require_once CN_ROOT . '/inc/functions.php';
date_default_timezone_set('Asia/Shanghai');
if (session_id() === '') {
    @session_start();
}

$lockFile = CN_ROOT . '/install.lock';
$configFile = CN_ROOT . '/inc/config.php';
$done = false;
$errors = array();
$successInfo = array();

$env = array(
    'PHP 版本 >= 5.4' => version_compare(PHP_VERSION, '5.4.0', '>='),
    'PDO MySQL 驱动' => extension_loaded('pdo_mysql'),
    'SimpleXML' => extension_loaded('simplexml'),
    'cURL 或 allow_url_fopen' => function_exists('curl_init') || ini_get('allow_url_fopen'),
    'inc/ 目录可写' => is_writable(CN_ROOT . '/inc'),
    '根目录可写' => is_writable(CN_ROOT),
);
$blocking = !($env['PDO MySQL 驱动'] && $env['SimpleXML'] && $env['inc/ 目录可写']);

function inst_h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function inst_default($key, $fallback = '')
{
    return isset($_POST[$key]) ? inst_h($_POST[$key]) : $fallback;
}

if (file_exists($lockFile)) {
    $done = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_install'])) {
    if (!cn_csrf_check()) {
        $errors[] = '页面已过期，请刷新后重试';
    } else {
        $dbHost = trim(isset($_POST['db_host']) ? $_POST['db_host'] : 'localhost');
        $dbPort = intval(isset($_POST['db_port']) ? $_POST['db_port'] : 3306);
        $dbName = trim(isset($_POST['db_name']) ? $_POST['db_name'] : '');
        $dbUser = trim(isset($_POST['db_user']) ? $_POST['db_user'] : '');
        $dbPass = isset($_POST['db_pass']) ? (string) $_POST['db_pass'] : '';
        $prefix = trim(isset($_POST['table_prefix']) ? $_POST['table_prefix'] : 'cn_');
        $adminUser = trim(isset($_POST['admin_user']) ? $_POST['admin_user'] : '');
        $adminPass = isset($_POST['admin_pass']) ? (string) $_POST['admin_pass'] : '';
        $adminPass2 = isset($_POST['admin_pass2']) ? (string) $_POST['admin_pass2'] : '';
        $siteName = trim(isset($_POST['site_name']) ? $_POST['site_name'] : '链讯 CryptoIndex');
        $timezone = isset($_POST['timezone']) ? $_POST['timezone'] : 'Asia/Shanghai';

        if ($dbName === '' || $dbUser === '' || $adminUser === '') {
            $errors[] = '数据库名、数据库账号、管理员账号均不能为空';
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
            $errors[] = '数据库名只能包含字母、数字与下划线';
        }
        if (!preg_match('/^[a-zA-Z0-9_]{1,20}$/', $prefix)) {
            $errors[] = '表前缀只能包含 1-20 位字母、数字与下划线';
        }
        if (strlen($adminPass) < 8) {
            $errors[] = '管理员密码至少 8 位';
        }
        if ($adminPass !== $adminPass2) {
            $errors[] = '两次输入的管理员密码不一致';
        }
        if (!in_array($timezone, timezone_identifiers_list())) {
            $timezone = 'Asia/Shanghai';
        }

        if (empty($errors)) {
            try {
                $dsn = 'mysql:host=' . $dbHost . ';port=' . $dbPort;
                $pdo = new PDO($dsn, $dbUser, $dbPass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
                if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                    // 连接后统一设置字符集与宽松 sql_mode
                    $pdo->exec("SET NAMES utf8, sql_mode=''");
                } else {
                    $pdo->exec("SET NAMES utf8");
                }
                // 有建库权限时自动创建；无权限则尝试使用已存在的库
                try {
                    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $dbName . '` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci');
                } catch (Exception $e) {
                    // 虚拟主机通常不允许建库，忽略即可
                }
                $pdo->exec('USE `' . $dbName . '`');

                // 建表
                $schema = file_get_contents(CN_ROOT . '/sql/schema.sql');
                if ($schema === false) {
                    throw new Exception('无法读取 sql/schema.sql');
                }
                $schema = str_replace('{{PREFIX}}', $prefix, $schema);
                $lines = array();
                foreach (explode("\n", $schema) as $line) {
                    $t = trim($line);
                    if ($t === '' || substr($t, 0, 2) === '--' || substr($t, 0, 1) === '#') {
                        continue;
                    }
                    $lines[] = $line;
                }
                foreach (explode(';', implode("\n", $lines)) as $stmt) {
                    $stmt = trim($stmt);
                    if ($stmt !== '') {
                        $pdo->exec($stmt);
                    }
                }

                $t = function ($name) use ($prefix) {
                    return '`' . $prefix . $name . '`';
                };

                // 默认设置
                $settings = array(
                    'site_name' => $siteName !== '' ? $siteName : '链讯 CryptoIndex',
                    'site_desc' => '虚拟货币与区块链资讯聚合索引，自动收录公开 RSS 的标题、摘要与原文链接。',
                    'site_keywords' => '区块链资讯,虚拟货币,加密货币,行业动态,政策监管',
                    'site_notice' => '',
                    'per_page' => '20',
                    'disclaimer' => '本站为技术性信息聚合索引工具，仅收录公开 RSS 的标题、摘要与原文链接，不存储、不转载全文，版权归原作者及原媒体所有。本站不提供虚拟货币交易、兑换、理财或投资建议服务，内容不构成任何投资建议；请遵守所在国家或地区关于虚拟货币的相关法律法规。',
                    'default_interval' => '30',
                    'default_max_items' => '30',
                    'cron_key' => cn_random_string(32),
                    'market_enabled' => '1',
                    'market_provider' => 'coingecko',
                    'market_api' => '',
                    'market_ttl' => '10',
                );
                $stmt = $pdo->prepare('INSERT INTO ' . $t('settings') . ' (skey, svalue) VALUES (?,?) '
                    . 'ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
                foreach ($settings as $k => $v) {
                    $stmt->execute(array($k, $v));
                }

                // 管理员（重复安装时覆盖）
                $pdo->exec('DELETE FROM ' . $t('admin'));
                $stmt = $pdo->prepare('INSERT INTO ' . $t('admin') . ' (username, password_hash, created_at, last_login_ip) VALUES (?,?,?,?)');
                $stmt->execute(array($adminUser, cn_hash_password($adminPass), cn_now(), ''));

                // 预置来源（按 feed_url 去重，避免重复安装产生重复行）
                require_once CN_ROOT . '/inc/collector.php';
                // PHP 层查重后再插入，避免 MySQL 5.5 对 INSERT...SELECT 同表的限制
                $check = $pdo->prepare('SELECT COUNT(*) FROM ' . $t('sources') . ' WHERE feed_url = ?');
                $insert = $pdo->prepare('INSERT INTO ' . $t('sources')
                    . ' (name, site_url, feed_url, category, enabled, interval_min, max_items, created_at) VALUES (?,?,?,?,1,?,?,?)');
                foreach (cn_default_sources() as $d) {
                    $check->execute(array($d['feed_url']));
                    if (intval($check->fetchColumn()) > 0) {
                        continue;
                    }
                    $insert->execute(array($d['name'], $d['site_url'], $d['feed_url'], $d['category'],
                        $settings['default_interval'], $settings['default_max_items'], cn_now()));
                }

                // 生成配置文件
                $cfg = array(
                    'db_host' => $dbHost, 'db_port' => (string) $dbPort, 'db_name' => $dbName,
                    'db_user' => $dbUser, 'db_pass' => $dbPass, 'table_prefix' => $prefix,
                    'base_url' => '', 'timezone' => $timezone, 'debug' => false,
                    'ssl_verify' => false, 'http_proxy' => '',
                );
                $content = "<?php\n// 由安装向导生成于 " . cn_now() . "，请勿公开此文件。\nreturn " . var_export($cfg, true) . ";\n";
                if (file_put_contents($configFile, $content) === false) {
                    throw new Exception('无法写入 inc/config.php，请检查目录权限');
                }
                @chmod($configFile, 0644);

                // 数据/锁目录
                $dataDir = CN_ROOT . '/data';
                if (!is_dir($dataDir)) {
                    @mkdir($dataDir, 0755, true);
                }
                @file_put_contents($dataDir . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
                @file_put_contents($dataDir . '/index.html', '');
                @file_put_contents(CN_ROOT . '/install.lock', 'installed at ' . cn_now() . ' version 1.0.0');

                $successInfo = array(
                    'admin_url' => 'admin/login.php',
                    'cron' => '*/15 * * * * /usr/bin/php ' . CN_ROOT . '/cron.php > /dev/null 2>&1',
                    'prefix' => $prefix,
                    'db' => $dbName,
                );
                $done = true;
            } catch (Exception $e) {
                $errors[] = '安装失败：' . $e->getMessage();
                @unlink($configFile);
            }
        }
    }
}

echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<meta name="robots" content="noindex,nofollow"><title>安装向导 · CryptoIndex</title>'
    . '<link rel="stylesheet" href="assets/admin.css"></head><body>'
    . '<div style="max-width:760px;margin:40px auto 60px;padding:0 16px">'
    . '<div style="margin-bottom:22px"><div class="side-mark" style="width:36px;height:36px;display:inline-block;vertical-align:middle"></div>'
    . '<b style="font-size:20px;vertical-align:middle;margin-left:10px">CryptoIndex 安装向导</b>'
    . '<div class="dim small" style="margin-top:4px">虚拟货币资讯聚合采集站 · 支持 MySQL 5.5 / PHP 5.4+</div></div>';

if ($done && !empty($successInfo)) {
    echo '<div class="alert alert-ok">安装完成，数据库结构与默认配置已写入。</div>';
    echo '<div class="panel"><h3 class="panel-title">接下来请完成 3 件事</h3>';
    echo '<p><b>1. 登录后台并采集首批资讯</b><br><span class="muted small">后台账号为安装时填写的管理员账号。进入「立即采集」点击一次即可看到内容。</span></p>';
    echo '<p><a class="btn btn-primary" href="' . inst_h($successInfo['admin_url']) . '">前往管理后台</a> '
        . '<a class="btn" href="index.php">查看前台</a></p>';
    echo '<p style="margin-top:18px"><b>2. 配置定时采集</b><br><span class="muted small">在服务器 crontab 中加入（若不配置，只能手动采集）：</span></p>';
    echo '<div class="code">' . inst_h($successInfo['cron']) . '</div>';
    echo '<p style="margin-top:18px"><b>3. 安全收尾</b><br><span class="muted small">删除 <b>install.php</b>；确认 <b>inc/</b>、<b>data/</b> 禁止 Web 直接访问；数据库表前缀为 <b>'
        . inst_h($successInfo['prefix']) . '</b>。</span></p></div>';
    echo '<div class="panel"><p class="muted small" style="margin:0">合规提醒：本站默认只收录公开 RSS 的标题、摘要与原文链接，请确认所收录来源允许被聚合、遵守其服务条款与 robots.txt，并遵守所在国家或地区关于虚拟货币资讯的法律法规。商用前建议取得内容授权。</p></div>';
} else {
    echo '<div class="panel"><h3 class="panel-title">环境检查</h3><div class="kv">';
    foreach ($env as $k => $ok) {
        echo '<div><span>' . inst_h($k) . '：</span>'
            . '<span style="color:' . ($ok ? 'var(--ok)' : 'var(--bad)') . '">' . ($ok ? '通过' : '未通过') . '</span></div>';
    }
    echo '</div>';
    if ($blocking) {
        echo '<div class="alert alert-error" style="margin-top:14px;margin-bottom:0">关键条件未满足：请开启 pdo_mysql、simplexml 扩展，并确保 inc/ 目录可写。</div>';
    }
    echo '</div>';

    if (file_exists($lockFile)) {
        echo '<div class="alert alert-info">检测到 install.lock，站点已经安装。如需重新安装，请先删除 install.lock 与 inc/config.php。</div>'
            . '<a class="btn btn-primary" href="admin/login.php">前往管理后台</a>';
    } else {
        foreach ($errors as $e) {
            echo '<div class="alert alert-error">' . inst_h($e) . '</div>';
        }
        echo '<form method="post" action="install.php">' . cn_csrf_field() . '<input type="hidden" name="do_install" value="1">';
        echo '<div class="panel"><h3 class="panel-title">数据库配置（MySQL 5.5 兼容）</h3><div class="form-grid">';
        echo '<div class="field"><label>数据库主机</label><input type="text" name="db_host" value="' . inst_default('db_host', 'localhost') . '" required></div>';
        echo '<div class="field"><label>端口</label><input type="number" name="db_port" value="' . inst_default('db_port', '3306') . '" required></div>';
        echo '<div class="field"><label>数据库名</label><input type="text" name="db_name" value="' . inst_default('db_name') . '" required placeholder="crypto_news"><div class="help">若账号有建库权限会自动创建，否则请先在主机面板创建</div></div>';
        echo '<div class="field"><label>表前缀</label><input type="text" name="table_prefix" value="' . inst_default('table_prefix', 'cn_') . '" required></div>';
        echo '<div class="field"><label>数据库账号</label><input type="text" name="db_user" value="' . inst_default('db_user') . '" required></div>';
        echo '<div class="field"><label>数据库密码</label><input type="password" name="db_pass" value=""></div>';
        echo '</div></div>';
        echo '<div class="panel"><h3 class="panel-title">站点与管理员</h3><div class="form-grid">';
        echo '<div class="field"><label>站点名称</label><input type="text" name="site_name" value="' . inst_default('site_name', '链讯 CryptoIndex') . '"></div>';
        echo '<div class="field"><label>时区</label><select name="timezone">';
        foreach (array('Asia/Shanghai', 'Asia/Hong_Kong', 'Asia/Taipei', 'Asia/Singapore', 'Asia/Tokyo', 'UTC') as $tz) {
            echo '<option value="' . inst_h($tz) . '"' . (inst_default('timezone', 'Asia/Shanghai') === $tz ? ' selected' : '') . '>' . inst_h($tz) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="field"><label>管理员账号</label><input type="text" name="admin_user" value="' . inst_default('admin_user', 'admin') . '" required></div>';
        echo '<div class="field"><label>管理员密码（至少 8 位）</label><input type="password" name="admin_pass" required></div>';
        echo '<div class="field"><label>确认密码</label><input type="password" name="admin_pass2" required></div>';
        echo '</div></div>';
        echo '<button class="btn btn-primary" type="submit"' . ($blocking ? ' disabled' : '') . ' style="padding:11px 26px">开始安装</button>';
        echo '<p class="dim small" style="margin-top:14px">安装过程只写入数据库与 inc/config.php，不会修改任何系统文件。详细部署说明见 README.md。</p>';
        echo '</form>';
    }
}
echo '</div></body></html>';
