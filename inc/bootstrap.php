<?php
/**
 * 应用引导文件：所有前台 / 后台 / 命令行入口都应先引入本文件
 */
define('CN_ROOT', dirname(dirname(__FILE__)));
define('CN_VERSION', '2.0.0');

require_once CN_ROOT . '/inc/functions.php';

$cn_config_file = CN_ROOT . '/inc/config.php';
if (!file_exists($cn_config_file)) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "尚未安装：请先访问 install.php 完成安装向导。\n");
        exit(1);
    }
    cn_redirect('install.php');
}

require_once CN_ROOT . '/inc/db.php';
require_once CN_ROOT . '/inc/fulltext.php';
require_once CN_ROOT . '/inc/collector.php';
require_once CN_ROOT . '/inc/market.php';
require_once CN_ROOT . '/inc/migrate.php';

$cn_timezone = cn_config('timezone', 'Asia/Shanghai');
if (in_array($cn_timezone, timezone_identifiers_list())) {
    date_default_timezone_set($cn_timezone);
} else {
    date_default_timezone_set('Asia/Shanghai');
}
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}
if (session_id() === '') {
    @session_start();
}

// 老版本覆盖升级后，首次请求自动补列并迁移中文栏目/标签/默认文案
cn_schema_upgrade();

// 兼容极老版本 PHP（<=5.3）的魔术引号，PHP 5.4+ 不会进入此分支
if (function_exists('get_magic_quotes_gpc') && @get_magic_quotes_gpc()) {
    function cn_stripslashes_deep($value)
    {
        return is_array($value) ? array_map('cn_stripslashes_deep', $value) : stripslashes($value);
    }
    $_GET = cn_stripslashes_deep($_GET);
    $_POST = cn_stripslashes_deep($_POST);
    $_COOKIE = cn_stripslashes_deep($_COOKIE);
    $_REQUEST = cn_stripslashes_deep($_REQUEST);
}
