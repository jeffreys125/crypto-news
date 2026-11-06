<?php
/**
 * 公共函数库
 * 语法兼容 PHP 5.4+（不使用短数组 []、??、标量类型声明、箭头函数等新特性）
 */
if (!defined('CN_ROOT')) {
    die('Access denied');
}

/** 读取配置项 */
function cn_config($key = null, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = CN_ROOT . '/inc/config.php';
        if (!file_exists($file)) {
            return $default;
        }
        $cfg = require $file;
        if (!is_array($cfg)) {
            $cfg = array();
        }
    }
    if ($key === null) {
        return $cfg;
    }
    return isset($cfg[$key]) ? $cfg[$key] : $default;
}

/** 友好错误页 */
function cn_fatal($message, $title = '站点暂时不可用')
{
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        if (function_exists('http_response_code')) {
            http_response_code(500);
        } else {
            header('HTTP/1.1 500 Internal Server Error');
        }
    }
    $msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $ttl = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>' . $ttl . '</title>'
        . '<style>body{margin:0;padding:60px 20px;background:#0b0f1a;color:#dbe4f5;font:15px/1.8 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}'
        . '.box{max-width:640px;margin:0 auto;background:#131c30;border:1px solid #23304d;border-radius:12px;padding:32px}'
        . 'h1{font-size:20px;margin:0 0 16px;color:#7aa2ff}p{margin:0 0 10px}code{color:#f0b90b;word-break:break-all}</style></head>'
        . '<body><div class="box"><h1>' . $ttl . '</h1><p>' . $msg . '</p>'
        . '<p style="color:#7d8aa5;margin-top:20px">如尚未安装，请先访问 <code>install.php</code> 完成安装向导。</p>'
        . '</div></body></html>';
    exit;
}

/** HTML 转义 */
function h($str)
{
    return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
}

/** 当前时间（数据库 datetime 格式） */
function cn_now()
{
    return date('Y-m-d H:i:s');
}

/** 是否命令行环境 */
function cn_is_cli()
{
    return PHP_SAPI === 'cli' || PHP_SAPI === 'cgi-fcgi' && empty($_SERVER['HTTP_HOST']);
}

/** 站点根 URL（自动识别 https、子目录、后台目录） */
function cn_base_url()
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $fixed = cn_config('base_url', '');
    if ($fixed !== '') {
        $base = rtrim($fixed, '/');
        return $base;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $dir = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])) : '';
    if (substr($dir, -6) === '/admin') {
        $dir = substr($dir, 0, -6);
    }
    $dir = rtrim($dir, '/');
    $base = $scheme . '://' . $host . ($dir !== '' && $dir !== '/' ? $dir : '');
    return $base;
}

/** 生成站内链接（传统 query string 形式，无需伪静态即可运行） */
function cn_url($page = 'index', $params = array())
{
    $map = array(
        'index'   => 'index.php',
        'home'    => 'index.php',
        'article' => 'article.php',
        'search'  => 'search.php',
        'feed'    => 'feed.php',
        'admin'   => 'admin/index.php',
        'login'   => 'admin/login.php',
    );
    $file = isset($map[$page]) ? $map[$page] : $page . '.php';
    $url = cn_base_url() . '/' . $file;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params, '', '&amp;');
    }
    return $url;
}

/** 截断字符串（mbstring 可选） */
function cn_cut($str, $len, $suffix = '…')
{
    $str = (string) $str;
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($str, 'UTF-8') <= $len) {
            return $str;
        }
        return mb_substr($str, 0, $len, 'UTF-8') . $suffix;
    }
    if (strlen($str) <= $len) {
        return $str;
    }
    return substr($str, 0, $len) . $suffix;
}

/**
 * 过滤 4 字节 UTF-8 字符（emoji 等）。
 * MySQL 5.5 的 utf8 实为 utf8mb3，无法存储 4 字节字符，
 * 入库前过滤可避免"Incorrect string value"错误或数据截断。
 */
function cn_strip_4byte($str)
{
    if (!is_string($str) || $str === '') {
        return $str;
    }
    // 优先使用正则移除 U+10000 以上的字符（需 /u 修饰符）
    $out = @preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $str);
    if ($out !== null) {
        return $out;
    }
    // 兜底：按 UTF-8 首字节扫描，丢弃 4 字节序列
    $result = '';
    $len = strlen($str);
    $i = 0;
    while ($i < $len) {
        $b = ord($str[$i]);
        if ($b >= 0xF0 && $b <= 0xF7) {
            $i += 4;
            continue;
        }
        if ($b >= 0xE0 && $b <= 0xEF) {
            $n = 3;
        } elseif ($b >= 0xC0 && $b <= 0xDF) {
            $n = 2;
        } else {
            $n = 1;
        }
        $result .= substr($str, $i, $n);
        $i += $n;
    }
    return $result;
}

/** HTML 转干净纯文本（用于摘要） */
function cn_clean_text($html, $limit = 0)
{
    $text = (string) $html;
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/<(script|style|noscript)[^>]*>.*?<\/\1>/is', ' ', $text);
    $text = strip_tags($text);
    $text = str_replace(array("\xc2\xa0", '&nbsp;'), ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);
    if ($limit > 0) {
        $text = cn_cut($text, $limit, '…');
    }
    return cn_strip_4byte($text);
}

/** 中文相对时间 */
function cn_time_ago($datetime)
{
    $ts = is_numeric($datetime) ? intval($datetime) : strtotime($datetime);
    if (!$ts) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 0) {
        $diff = 0;
    }
    if ($diff < 60) {
        return '刚刚';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' 分钟前';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' 小时前';
    }
    if ($diff < 86400 * 7) {
        return floor($diff / 86400) . ' 天前';
    }
    return date('Y-m-d', $ts);
}

/** 固定分类 */
function cn_categories()
{
    return array('综合资讯', '行情分析', '区块链', '监管政策', '技术生态', '安全事件', '行业动态');
}

/** 随机字符串 */
function cn_random_string($len = 16)
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $out = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $len; $i++) {
        if (function_exists('random_int')) {
            $out .= $chars[random_int(0, $max)];
        } else {
            $out .= $chars[mt_rand(0, $max)];
        }
    }
    return $out;
}

/** 定长安全比较（兼容 PHP 5.4 / 5.5） */
function cn_hash_equals($a, $b)
{
    if (function_exists('hash_equals')) {
        return hash_equals($a, $b);
    }
    if (!is_string($a) || !is_string($b) || strlen($a) !== strlen($b)) {
        return false;
    }
    $diff = 0;
    for ($i = 0; $i < strlen($a); $i++) {
        $diff |= ord($a[$i]) ^ ord($b[$i]);
    }
    return $diff === 0;
}

/**
 * 密码哈希：PHP >= 5.5 使用 password_hash（bcrypt）；
 * 老版本退化为加盐 SHA-256 迭代，保证在 PHP 5.4 上也能安装运行。
 */
function cn_hash_password($password)
{
    if (function_exists('password_hash') && defined('PASSWORD_DEFAULT')) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    $salt = cn_random_string(16);
    $hash = hash('sha256', $salt . '|' . $password);
    for ($i = 0; $i < 20000; $i++) {
        $hash = hash('sha256', $hash . $salt);
    }
    return 'legacy$' . $salt . '$' . $hash;
}

function cn_verify_password($password, $stored)
{
    if (strpos($stored, 'legacy$') === 0) {
        $parts = explode('$', $stored, 3);
        if (count($parts) !== 3) {
            return false;
        }
        $hash = hash('sha256', $parts[1] . '|' . $password);
        for ($i = 0; $i < 20000; $i++) {
            $hash = hash('sha256', $hash . $parts[1]);
        }
        return cn_hash_equals($hash, $parts[2]);
    }
    if (function_exists('password_verify')) {
        return password_verify($password, $stored);
    }
    return false;
}

/** CSRF 令牌 */
function cn_csrf_token()
{
    if (session_id() === '') {
        @session_start();
    }
    if (empty($_SESSION['cn_csrf'])) {
        $_SESSION['cn_csrf'] = cn_random_string(32);
    }
    return $_SESSION['cn_csrf'];
}

function cn_csrf_field()
{
    return '<input type="hidden" name="_token" value="' . h(cn_csrf_token()) . '">';
}

function cn_csrf_check($token = null)
{
    if ($token === null) {
        $token = isset($_POST['_token']) ? $_POST['_token'] : (isset($_GET['_token']) ? $_GET['_token'] : '');
    }
    return cn_hash_equals(cn_csrf_token(), (string) $token);
}

/** 客户端 IP */
function cn_client_ip()
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

/** 分页 HTML */
function cn_pagination($total, $page, $perPage, $baseUrl)
{
    $pages = (int) ceil($total / max(1, $perPage));
    if ($pages <= 1) {
        return '';
    }
    $page = max(1, min($page, $pages));
    $sep = (strpos($baseUrl, '?') === false) ? '?' : '&amp;';
    $html = '<nav class="pagination" aria-label="分页">';
    if ($page > 1) {
        $html .= '<a href="' . $baseUrl . $sep . 'page=' . ($page - 1) . '" class="page-btn">&laquo; 上一页</a>';
    }
    $start = max(1, $page - 2);
    $end = min($pages, $page + 2);
    if ($start > 1) {
        $html .= '<a href="' . $baseUrl . $sep . 'page=1" class="page-btn">1</a><span class="page-dots">…</span>';
    }
    for ($i = $start; $i <= $end; $i++) {
        if ($i == $page) {
            $html .= '<span class="page-btn current">' . $i . '</span>';
        } else {
            $html .= '<a href="' . $baseUrl . $sep . 'page=' . $i . '" class="page-btn">' . $i . '</a>';
        }
    }
    if ($end < $pages) {
        $html .= '<span class="page-dots">…</span><a href="' . $baseUrl . $sep . 'page=' . $pages . '" class="page-btn">' . $pages . '</a>';
    }
    if ($page < $pages) {
        $html .= '<a href="' . $baseUrl . $sep . 'page=' . ($page + 1) . '" class="page-btn">下一页 &raquo;</a>';
    }
    $html .= '</nav>';
    return $html;
}

/** 安全跳转 */
function cn_redirect($url)
{
    if (!headers_sent()) {
        header('Location: ' . $url);
    }
    echo '<script>location.href=' . json_encode($url) . ';</script>';
    exit;
}

/** 输出 JSON（兼容老 PHP） */
function cn_json_out($data)
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data);
    exit;
}

/** 从数组安全取值 */
function cn_arr($arr, $key, $default = '')
{
    return isset($arr[$key]) ? $arr[$key] : $default;
}
