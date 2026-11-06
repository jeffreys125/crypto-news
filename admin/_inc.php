<?php
/** 后台公共：鉴权、布局、提示 */
require_once dirname(dirname(__FILE__)) . '/inc/bootstrap.php';

function cn_admin_is_logged()
{
    return !empty($_SESSION['cn_admin_id']);
}

function cn_admin_auth()
{
    if (!cn_admin_is_logged()) {
        cn_redirect(cn_base_url() . '/admin/login.php');
    }
}

/** 写操作守卫：必须 POST 且带有效 CSRF 令牌 */
function cn_admin_guard()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !cn_csrf_check()) {
        http_response_code(403);
        cn_fatal('请求无效或已过期，请返回上一页刷新后重试。', '操作被拒绝');
    }
}

function cn_admin_flash($message, $type = 'ok')
{
    $_SESSION['cn_flash'] = array($type, $message);
}

function cn_admin_show_flash()
{
    if (empty($_SESSION['cn_flash'])) {
        return;
    }
    $f = $_SESSION['cn_flash'];
    unset($_SESSION['cn_flash']);
    echo '<div class="alert alert-' . h($f[0]) . '">' . h($f[1]) . '</div>';
}

function cn_admin_menu()
{
    return array(
        'index'    => array('仪表盘', 'index.php'),
        'sources'  => array('资讯来源', 'sources.php'),
        'articles' => array('文章管理', 'articles.php'),
        'collect'  => array('立即采集', 'collect.php'),
        'logs'     => array('采集日志', 'logs.php'),
        'settings' => array('站点设置', 'settings.php'),
    );
}

function cn_admin_head($title, $active = 'index')
{
    $site = cn_setting('site_name', '链讯 CryptoIndex');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    echo '<meta name="robots" content="noindex,nofollow">' . "\n";
    echo '<title>' . h($title) . ' - 管理后台 · ' . h($site) . '</title>' . "\n";
    echo '<link rel="stylesheet" href="' . cn_base_url() . '/assets/admin.css?v=' . CN_VERSION . '">' . "\n";
    echo '</head><body>' . "\n";
    echo '<aside class="side"><div class="side-brand"><span class="side-mark"></span><div>'
        . '<b>' . h($site) . '</b><small>管理后台 ' . CN_VERSION . '</small></div></div>';
    echo '<nav class="side-nav">';
    foreach (cn_admin_menu() as $key => $item) {
        echo '<a href="' . $item[1] . '"' . ($active === $key ? ' class="active"' : '') . '>' . h($item[0]) . '</a>';
    }
    echo '</nav><div class="side-foot">'
        . '<a href="' . cn_url('index') . '" target="_blank">查看前台 &rarr;</a>'
        . '<a href="logout.php">退出登录</a></div></aside>' . "\n";
    echo '<main class="content"><div class="content-inner">' . "\n";
    echo '<div class="page-title"><h1>' . h($title) . '</h1><span>' . h(cn_now()) . '</span></div>' . "\n";
    cn_admin_show_flash();
}

function cn_admin_foot()
{
    echo '</div></main></body></html>';
}

/** 状态标签 */
function cn_admin_badge($status)
{
    $map = array(
        'ok' => array('正常', 'ok'),
        'never' => array('未采集', 'muted'),
        'not_modified' => array('未更新', 'muted'),
        'error' => array('失败', 'bad'),
    );
    $s = isset($map[$status]) ? $map[$status] : array($status, 'muted');
    return '<span class="badge badge-' . $s[1] . '">' . h($s[0]) . '</span>';
}
