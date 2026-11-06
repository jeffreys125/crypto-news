<?php
/**
 * 数据库访问层（PDO / MySQL）
 * 针对 MySQL 5.5 做了兼容处理：
 *  - DSN 不带 charset 参数（5.3.6 以下不识别），改用 SET NAMES utf8
 *  - 初始化时清空 sql_mode，避免老版本严格模式导致的插入失败
 *  - 所有写入均使用预处理语句
 */
if (!defined('CN_ROOT')) {
    die('Access denied');
}

/** 返回 PDO 单例 */
function cn_db()
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    if (!class_exists('PDO')) {
        cn_fatal('服务器缺少 PDO 扩展，请开启 php_pdo 与 php_pdo_mysql。');
    }
    $drivers = PDO::getAvailableDrivers();
    if (!in_array('mysql', $drivers)) {
        cn_fatal('服务器缺少 pdo_mysql 驱动，无法连接 MySQL。可用驱动：' . implode(', ', $drivers));
    }
    $c = cn_config();
    $dsn = 'mysql:host=' . cn_arr($c, 'db_host', 'localhost')
        . ';port=' . intval(cn_arr($c, 'db_port', 3306))
        . ';dbname=' . cn_arr($c, 'db_name', '');

    $options = array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    );
    if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
        // sql_mode='' 兼容 MySQL 5.5 ~ 8.0；避免 STRICT_TRANS_TABLES 截断报错
        $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8, sql_mode=''";
    }
    try {
        $pdo = new PDO($dsn, cn_arr($c, 'db_user', ''), cn_arr($c, 'db_pass', ''), $options);
        if (!defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $pdo->exec("SET NAMES utf8");
        }
    } catch (PDOException $e) {
        $msg = cn_config('debug', false) ? $e->getMessage() : '请检查 inc/config.php 中的数据库配置，以及 MySQL 服务是否运行。';
        cn_fatal('数据库连接失败：' . $msg);
    }
    return $pdo;
}

/** 完整表名（含反引号与表前缀） */
function cn_table($name)
{
    return '`' . cn_config('table_prefix', 'cn_') . preg_replace('/[^a-zA-Z0-9_]/', '', $name) . '`';
}

/** 执行预处理语句 */
function cn_db_statement($sql, $params = array())
{
    $stmt = cn_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** 查询多行 */
function cn_db_all($sql, $params = array())
{
    return cn_db_statement($sql, $params)->fetchAll();
}

/** 查询单行 */
function cn_db_one($sql, $params = array())
{
    $row = cn_db_statement($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** 查询单个值 */
function cn_db_value($sql, $params = array(), $default = null)
{
    $stmt = cn_db_statement($sql, $params);
    $val = $stmt->fetchColumn();
    return $val === false ? $default : $val;
}

/** 执行写操作，返回受影响行数 */
function cn_db_exec($sql, $params = array())
{
    return cn_db_statement($sql, $params)->rowCount();
}

/** 最后插入 ID */
function cn_db_last_id()
{
    return cn_db()->lastInsertId();
}

/**
 * 读取站点设置（带请求内缓存）
 */
function cn_setting($key, $default = '')
{
    static $cache = null;
    if ($cache === null) {
        $cache = array();
        try {
            $rows = cn_db_all('SELECT skey, svalue FROM ' . cn_table('settings'));
            foreach ($rows as $r) {
                $cache[$r['skey']] = $r['svalue'];
            }
        } catch (Exception $e) {
            $cache = array();
        }
    }
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    return $default;
}

/** 写入站点设置 */
function cn_set_setting($key, $value)
{
    $sql = 'INSERT INTO ' . cn_table('settings') . ' (skey, svalue) VALUES (?, ?) '
        . 'ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)';
    cn_db_exec($sql, array($key, (string) $value));
}

/** 一次性读取全部设置（后台用） */
function cn_settings_all()
{
    $rows = cn_db_all('SELECT skey, svalue FROM ' . cn_table('settings'));
    $out = array();
    foreach ($rows as $r) {
        $out[$r['skey']] = $r['svalue'];
    }
    return $out;
}

/** MySQL 版本（后台自检用） */
function cn_mysql_version()
{
    try {
        return cn_db()->getAttribute(PDO::ATTR_SERVER_VERSION);
    } catch (Exception $e) {
        return '未知';
    }
}
