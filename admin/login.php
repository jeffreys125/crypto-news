<?php
/** 后台登录 */
require_once dirname(dirname(__FILE__)) . '/admin/_inc.php';

if (cn_admin_is_logged()) {
    cn_redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cn_csrf_check()) {
        $error = '页面已过期，请刷新后重试';
    } elseif (!empty($_SESSION['cn_login_lock']) && $_SESSION['cn_login_lock'] > time()) {
        $error = '尝试次数过多，请在 ' . ceil(($_SESSION['cn_login_lock'] - time()) / 60) . ' 分钟后重试';
    } else {
        $username = trim(isset($_POST['username']) ? (string) $_POST['username'] : '');
        $password = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $row = cn_db_one('SELECT * FROM ' . cn_table('admin') . ' WHERE username = ?', array($username));
        if ($row && cn_verify_password($password, $row['password_hash'])) {
            $_SESSION['cn_admin_id'] = intval($row['id']);
            $_SESSION['cn_admin_name'] = $row['username'];
            unset($_SESSION['cn_login_fail'], $_SESSION['cn_login_lock']);
            cn_db_exec('UPDATE ' . cn_table('admin') . ' SET last_login_at = ?, last_login_ip = ? WHERE id = ?',
                array(cn_now(), cn_client_ip(), $row['id']));
            cn_admin_flash('登录成功，欢迎回来');
            cn_redirect('index.php');
        }
        $_SESSION['cn_login_fail'] = intval(isset($_SESSION['cn_login_fail']) ? $_SESSION['cn_login_fail'] : 0) + 1;
        if ($_SESSION['cn_login_fail'] >= 5) {
            $_SESSION['cn_login_lock'] = time() + 600;
            $_SESSION['cn_login_fail'] = 0;
            $error = '连续失败 5 次，已锁定 10 分钟';
        } else {
            $error = '账号或密码错误（还可尝试 ' . (5 - $_SESSION['cn_login_fail']) . ' 次）';
        }
    }
}

$site = cn_setting('site_name', '链讯 CryptoIndex');
echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<meta name="robots" content="noindex,nofollow"><title>登录 - ' . h($site) . ' 管理后台</title>'
    . '<link rel="stylesheet" href="' . cn_base_url() . '/assets/admin.css?v=' . CN_VERSION . '"></head>'
    . '<body><div style="max-width:400px;margin:9vh auto;padding:0 16px">'
    . '<div class="panel"><div style="text-align:center;margin-bottom:22px">'
    . '<div class="side-mark" style="width:38px;height:38px;margin:0 auto 10px"></div>'
    . '<b style="font-size:17px">' . h($site) . '</b><div class="dim small">管理后台登录</div></div>';
if ($error !== '') {
    echo '<div class="alert alert-error">' . h($error) . '</div>';
}
echo '<form method="post" action="login.php">' . cn_csrf_field()
    . '<div class="field" style="margin-bottom:14px"><label>管理员账号</label>'
    . '<input type="text" name="username" required autocomplete="username" autofocus></div>'
    . '<div class="field" style="margin-bottom:18px"><label>密码</label>'
    . '<input type="password" name="password" required autocomplete="current-password"></div>'
    . '<button class="btn btn-primary" type="submit" style="width:100%">登 录</button></form>'
    . '<p class="dim small" style="text-align:center;margin:16px 0 0">'
    . '<a href="' . cn_url('index') . '">返回前台</a></p></div>'
    . '<p class="dim small" style="text-align:center">请妥善保管账号，勿使用弱密码</p>'
    . '</div></body></html>';
