<?php
/** 资讯来源管理 */
require_once dirname(dirname(__FILE__)) . '/admin/_inc.php';
cn_admin_auth();

$errors = array();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cn_admin_guard();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'save') {
        $id = intval(isset($_POST['id']) ? $_POST['id'] : 0);
        $name = trim(isset($_POST['name']) ? (string) $_POST['name'] : '');
        $feedUrl = trim(isset($_POST['feed_url']) ? (string) $_POST['feed_url'] : '');
        $siteUrl = trim(isset($_POST['site_url']) ? (string) $_POST['site_url'] : '');
        $category = isset($_POST['category']) ? (string) $_POST['category'] : 'General';
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $interval = max(5, min(1440, intval(isset($_POST['interval_min']) ? $_POST['interval_min'] : 30)));
        $maxItems = max(1, min(100, intval(isset($_POST['max_items']) ? $_POST['max_items'] : 30)));

        if ($name === '' || $feedUrl === '') {
            $errors[] = '来源名称与 RSS 地址不能为空';
        }
        if (!in_array($category, cn_categories())) {
            $category = 'General';
        }
        $valid = cn_validate_feed_url($feedUrl);
        if (!$valid[0]) {
            $errors[] = $valid[1];
        }
        if ($siteUrl !== '' && !preg_match('#^https?://#i', $siteUrl)) {
            $siteUrl = 'https://' . $siteUrl;
        }
        if (empty($errors)) {
            $params = array($name, $siteUrl, $feedUrl, $category, $enabled, $interval, $maxItems);
            if ($id > 0) {
                cn_db_exec('UPDATE ' . cn_table('sources') . ' SET name=?, site_url=?, feed_url=?, category=?, enabled=?, interval_min=?, max_items=? WHERE id=?',
                    array_merge($params, array($id)));
            } else {
                cn_db_exec('INSERT INTO ' . cn_table('sources')
                    . ' (name, site_url, feed_url, category, enabled, interval_min, max_items, created_at) VALUES (?,?,?,?,?,?,?,?)',
                    array_merge($params, array(cn_now())));
            }
            cn_admin_flash($id > 0 ? '来源已更新' : '来源已添加');
            cn_redirect('sources.php');
        }
    } elseif ($action === 'toggle') {
        $id = intval($_POST['id']);
        cn_db_exec('UPDATE ' . cn_table('sources') . ' SET enabled = 1 - enabled WHERE id = ?', array($id));
        cn_admin_flash('已切换启用状态');
        cn_redirect('sources.php');
    } elseif ($action === 'delete') {
        $id = intval($_POST['id']);
        cn_db_exec('DELETE FROM ' . cn_table('sources') . ' WHERE id = ?', array($id));
        cn_admin_flash('来源已删除（已采集的资讯会保留）');
        cn_redirect('sources.php');
    } elseif ($action === 'collect_one') {
        $id = intval($_POST['id']);
        $s = cn_db_one('SELECT * FROM ' . cn_table('sources') . ' WHERE id = ?', array($id));
        if ($s) {
            $r = cn_collect_source($s, array('force' => true));
            cn_admin_flash($s['name'] . '：' . ($r['status'] === 'error' ? '采集失败 - ' : '') . $r['message'],
                $r['status'] === 'error' ? 'error' : 'ok');
        }
        cn_redirect('sources.php');
    } elseif ($action === 'reset_defaults') {
        $n = 0;
        foreach (cn_default_sources() as $d) {
            $exists = cn_db_value('SELECT COUNT(*) FROM ' . cn_table('sources') . ' WHERE feed_url = ?', array($d['feed_url']), 0);
            if (!$exists) {
                cn_db_exec('INSERT INTO ' . cn_table('sources')
                    . ' (name, site_url, feed_url, category, enabled, interval_min, max_items, created_at) VALUES (?,?,?,?,1,30,30,?)',
                    array($d['name'], $d['site_url'], $d['feed_url'], $d['category'], cn_now()));
                $n++;
            }
        }
        cn_admin_flash('已补回 ' . $n . ' 个预置来源');
        cn_redirect('sources.php');
    }
}

$editId = intval(isset($_GET['edit']) ? $_GET['edit'] : 0);
$edit = $editId > 0 ? cn_db_one('SELECT * FROM ' . cn_table('sources') . ' WHERE id = ?', array($editId)) : null;
$sources = cn_db_all('SELECT * FROM ' . cn_table('sources') . ' ORDER BY id ASC');

cn_admin_head('资讯来源', 'sources');
foreach ($errors as $e) {
    echo '<div class="alert alert-error">' . h($e) . '</div>';
}

echo '<div class="panel"><div class="panel-head"><h3>' . ($edit ? '编辑来源 #' . intval($edit['id']) : '添加来源') . '</h3>'
    . ($edit ? '<a class="btn btn-sm" href="sources.php">取消编辑</a>' : '') . '</div>';
echo '<form method="post" action="sources.php">' . cn_csrf_field()
    . '<input type="hidden" name="action" value="save">'
    . '<input type="hidden" name="id" value="' . ($edit ? intval($edit['id']) : 0) . '">';
echo '<div class="form-grid">';
$vName = $edit ? $edit['name'] : (isset($_POST['name']) ? $_POST['name'] : '');
$vFeed = $edit ? $edit['feed_url'] : (isset($_POST['feed_url']) ? $_POST['feed_url'] : '');
$vSite = $edit ? $edit['site_url'] : (isset($_POST['site_url']) ? $_POST['site_url'] : '');
$vCat = $edit ? $edit['category'] : 'General';
$vInt = $edit ? $edit['interval_min'] : cn_setting('default_interval', '30');
$vMax = $edit ? $edit['max_items'] : cn_setting('default_max_items', '30');
echo '<div class="field"><label><span class="req">*</span> 来源名称</label><input type="text" name="name" value="' . h($vName) . '" required placeholder="例如 Cointelegraph"></div>';
echo '<div class="field"><label>站点首页</label><input type="text" name="site_url" value="' . h($vSite) . '" placeholder="https://example.com"></div>';
echo '<div class="field full"><label><span class="req">*</span> RSS / Atom 地址</label><input type="text" name="feed_url" value="' . h($vFeed) . '" required placeholder="https://example.com/rss"></div>';
echo '<div class="field"><label>默认栏目</label><select name="category">';
foreach (cn_categories() as $cat) {
    echo '<option value="' . h($cat) . '"' . ($vCat === $cat ? ' selected' : '') . '>' . h($cat) . '</option>';
}
echo '</select></div>';
echo '<div class="field"><label>采集间隔（分钟，5-1440）</label><input type="number" name="interval_min" value="' . intval($vInt) . '" min="5" max="1440">'
    . '<div class="help">连续失败时会自动按 2/4/8 倍退避，避免频繁请求</div></div>';
echo '<div class="field"><label>每次最多抓取条数（1-100）</label><input type="number" name="max_items" value="' . intval($vMax) . '" min="1" max="100"></div>';
echo '<div class="field"><label>启用状态</label><label style="display:inline-flex;align-items:center;gap:6px;color:var(--text);margin-top:8px">'
    . '<input type="checkbox" name="enabled" value="1"' . ((!$edit || $edit['enabled']) ? ' checked' : '') . '> 启用该来源</label></div>';
echo '</div><div class="form-actions"><button class="btn btn-primary" type="submit">' . ($edit ? '保存修改' : '添加来源') . '</button>'
    . '<span class="dim small">会抓取 RSS 全文（或原文页正文），请确认来源的服务条款与 robots.txt 允许被聚合收录</span></div></form></div>';

echo '<div class="panel panel-tight"><div class="panel-head" style="padding:16px 18px 0"><h3>已配置来源（' . count($sources) . '）</h3>'
    . '<form method="post" action="sources.php" style="display:inline" onsubmit="return confirm(\'补回所有预置来源？\')">'
    . cn_csrf_field() . '<input type="hidden" name="action" value="reset_defaults">'
    . '<button type="submit" class="btn btn-sm">补回预置来源</button></form></div>';
echo '<div class="table-wrap"><table class="data"><thead><tr><th>ID</th><th>名称 / 地址</th><th>栏目</th><th>间隔</th>'
    . '<th>状态</th><th>上次采集</th><th>累计</th><th>操作</th></tr></thead><tbody>';
if (empty($sources)) {
    echo '<tr><td colspan="8" class="empty">还没有来源，请先添加或补回预置来源</td></tr>';
}
foreach ($sources as $s) {
    echo '<tr' . (!$s['enabled'] ? ' style="opacity:.6"' : '') . '><td class="dim">' . intval($s['id']) . '</td>';
    echo '<td><b>' . h($s['name']) . '</b>' . (!$s['enabled'] ? ' <span class="badge badge-muted">已停用</span>' : '')
        . '<div class="dim small ellipsis" title="' . h($s['feed_url']) . '">' . h($s['feed_url']) . '</div>'
        . ($s['last_status'] === 'error' ? '<div class="small" style="color:var(--bad)">' . h(cn_cut($s['last_message'], 70)) . '</div>' : '')
        . '</td>';
    echo '<td>' . h($s['category']) . '</td><td class="dim">' . intval($s['interval_min']) . ' 分</td>';
    echo '<td>' . cn_admin_badge($s['last_status']) . ($s['fail_count'] > 0 ? ' <span class="dim small">×' . intval($s['fail_count']) . '</span>' : '') . '</td>';
    echo '<td class="dim small nowrap">' . ($s['last_collect_at'] ? h(cn_time_ago($s['last_collect_at'])) : '—') . '</td>';
    echo '<td class="dim">' . intval($s['total_items']) . '</td><td class="actions">';
    echo '<a class="btn btn-sm" href="sources.php?edit=' . intval($s['id']) . '">编辑</a> ';
    echo '<form class="inline-form" method="post" action="sources.php">' . cn_csrf_field()
        . '<input type="hidden" name="action" value="collect_one"><input type="hidden" name="id" value="' . intval($s['id']) . '">'
        . '<button type="submit" title="忽略间隔，立即测试采集">测试采集</button></form> ';
    echo '<form class="inline-form" method="post" action="sources.php">' . cn_csrf_field()
        . '<input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="' . intval($s['id']) . '">'
        . '<button type="submit">' . ($s['enabled'] ? '停用' : '启用') . '</button></form> ';
    echo '<form class="inline-form" method="post" action="sources.php" onsubmit="return confirm(\'确认删除该来源？已采集资讯会保留\')">'
        . cn_csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . intval($s['id']) . '">'
        . '<button type="submit" class="danger">删除</button></form>';
    echo '</td></tr>';
}
echo '</tbody></table></div></div>';
echo '<p class="dim small">提示：部分媒体对 RSS 有反爬或地域限制，状态显示「失败」时可停用该来源或改用其它可访问地址。' . h(cn_base_url()) . '/feed.php 为本站对外聚合 RSS。</p>';
cn_admin_foot();
