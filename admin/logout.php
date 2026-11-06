<?php
/** 退出登录 */
require_once dirname(dirname(__FILE__)) . '/admin/_inc.php';
$_SESSION = array();
if (ini_get('session.use_cookies') && isset($_COOKIE[session_name()])) {
    $p = session_get_cookie_params();
    @setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
@session_destroy();
cn_redirect('login.php');
