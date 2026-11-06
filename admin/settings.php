<?php
/** 站点设置 */
require_once dirname(dirname(__FILE__)) . '/admin/_inc.php';
cn_admin_auth();

$defaults = array(
    'site_name' => '链讯 CryptoIndex',
    'site_desc' => '虚拟货币与区块链资讯聚合索引，自动收录公开 RSS 的标题、摘要与原文链接。',
    'site_keywords' => '区块链资讯,虚拟货币,加密货币,行业动态,政策监管',
    'site_notice' => '',
    'per_page' => '20',
    'disclaimer' => '本站为技术性信息聚合索引工具，仅收录公开 RSS 的标题、摘要与原文链接，不存储、不转载全文，版权归原作者及原媒体所有。本站不提供虚拟货币交易、兑换、理财或投资建议服务，内容不构成任何投资建议；请遵守所在国家或地区关于虚拟货币的相关法律法规。',
    'default_interval' => '30',
    'default_max_items' => '30',
    'market_enabled' => '1',
    'market_provider' => 'coingecko',
    'market_api' => '',
    'market_ttl' => '10',
);
$current = cn_settings_all();
foreach ($defaults as $k => $v) {
    if (!isset($current[$k])) {
        $current[$k] = $v;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cn_admin_guard();
    $action = isset($_POST['action']) ? $_POST['action'] : 'save';

    if ($action === 'save') {
        $fields = array(
            'site_name', 'site_desc', 'site_keywords', 'site_notice', 'disclaimer',
            'market_provider', 'market_api', 'market_ttl',
        );
        foreach ($fields as $f) {
            cn_set_setting($f, trim(isset($_POST[$f]) ? (string) $_POST[$f] : ''));
        }
        cn_set_setting('per_page', max(5, min(50, intval(isset($_POST['per_page']) ? $_POST['per_page'] : 20))));
        cn_set_setting('default_interval', max(5, min(1440, intval(isset($_POST['default_interval']) ? $_POST['default_interval'] : 30))));
        cn_set_setting('default_max_items', max(1, min(100, intval(isset($_POST['default_max_items']) ? $_POST['default_max_items'] : 30))));
        cn_set_setting('market_enabled', isset($_POST['market_enabled']) ? '1' : '0');
        cn_admin_flash('设置已保存');
        cn_redirect('settings.php');
    } elseif ($action === 'password') {
        $old = isset($_POST['old_password']) ? (string) $_POST['old_password'] : '';
        $new = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
        $again = isset($_POST['new_password2']) ? (string) $_POST['new_password2'] : '';
        $row = cn_db_one('SELECT * FROM ' . cn_table('admin') . ' WHERE id = ?', array(intval($_SESSION['cn_admin_id'])));
        if (!$row || !cn_verify_password($old, $row['password_hash'])) {
            cn_admin_flash('原密码不正确', 'error');
        } elseif (strlen($new) < 8) {
            cn_admin_flash('新密码长度至少 8 位', 'error');
        } elseif ($new !== $again) {
            cn_admin_flash('两次输入的新密码不一致', 'error');
        } else {
            cn_db_exec('UPDATE ' . cn_table('admin') . ' SET password_hash = ? WHERE id = ?',
                array(cn_hash_password($new), $row['id']));
            cn_admin_flash('密码已修改，下次登录请使用新密码');
        }
        cn_redirect('settings.php');
    } elseif ($action === 'regen_key') {
        cn_set_setting('cron_key', cn_random_string(32));
        cn_admin_flash('Web 触发密钥已重新生成，请同步更新外部计划任务');
        cn_redirect('settings.php');
    } elseif ($action === 'refresh_market') {
        cn_db_exec('DELETE FROM ' . cn_table('market_cache') . ' WHERE cache_key = ?', array('default'));
        $data = cn_market_fetch(true);
        cn_admin_flash(count($data) > 0 ? '行情已刷新，获取 ' . count($data) . ' 个币种' : '行情接口未返回数据，已保留空缓存（前台将隐藏行情条）',
            count($data) > 0 ? 'ok' : 'error');
        cn_redirect('settings.php');
    }
}

$cronKey = cn_setting('cron_key', '');
cn_admin_head('站点设置', 'settings');

echo '<form method="post" action="settings.php">' . cn_csrf_field() . '<input type="hidden" name="action" value="save">';
echo '<h2>站点信息</h2><div class="panel"><div class="form-grid">';
echo '<div class="field"><label>站点名称</label><input type="text" name="site_name" value="' . h($current['site_name']) . '"></div>';
echo '<div class="field"><label>每页显示条数（5-50）</label><input type="number" name="per_page" value="' . intval($current['per_page']) . '" min="5" max="50"></div>';
echo '<div class="field full"><label>站点描述（SEO）</label><textarea name="site_desc">' . h($current['site_desc']) . '</textarea></div>';
echo '<div class="field full"><label>关键词（SEO，逗号分隔）</label><input type="text" name="site_keywords" value="' . h($current['site_keywords']) . '"></div>';
echo '<div class="field full"><label>首页公告（留空则不显示）</label><textarea name="site_notice" style="min-height:56px">' . h($current['site_notice']) . '</textarea></div>';
echo '<div class="field full"><label>底部合规与免责声明</label><textarea name="disclaimer" style="min-height:100px">' . h($current['disclaimer']) . '</textarea>'
    . '<div class="help">建议保留原始声明：说明仅收录摘要、不构成投资建议、不提供交易服务，并提示遵守所在地法规。</div></div>';
echo '</div></div>';

echo '<h2>采集参数</h2><div class="panel"><div class="form-grid">';
echo '<div class="field"><label>新增来源的默认间隔（分钟）</label><input type="number" name="default_interval" value="' . intval($current['default_interval']) . '" min="5" max="1440"></div>';
echo '<div class="field"><label>新增来源的默认抓取上限</label><input type="number" name="default_max_items" value="' . intval($current['default_max_items']) . '" min="1" max="100"></div>';
echo '</div><p class="dim small" style="margin:12px 0 0">单个来源可在「资讯来源」中单独覆盖这两个值。程序使用 ETag 条件请求与失败退避，不会对来源造成频繁压力。</p></div>';

echo '<h2>行情模块（可选）</h2><div class="panel"><div class="form-grid">';
echo '<div class="field"><label>启用行情条</label><label style="display:inline-flex;align-items:center;gap:6px;color:var(--text);margin-top:8px">'
    . '<input type="checkbox" name="market_enabled" value="1"' . ($current['market_enabled'] === '1' ? ' checked' : '') . '> 在页面顶部显示 24h 行情</label>'
    . '<div class="help">接口不可达时自动沿用旧缓存；完全没有数据时前台自动隐藏行情条，不影响资讯浏览。</div></div>';
echo '<div class="field"><label>数据源</label><select name="market_provider">'
    . '<option value="coingecko"' . ($current['market_provider'] === 'coingecko' ? ' selected' : '') . '>CoinGecko 公开接口</option>'
    . '<option value="binance"' . ($current['market_provider'] === 'binance' ? ' selected' : '') . '>Binance 24hr 接口</option>'
    . '<option value="custom"' . ($current['market_provider'] === 'custom' ? ' selected' : '') . '>自定义（返回 CoinGecko 同结构 JSON）</option>'
    . '</select></div>';
echo '<div class="field full"><label>自定义接口地址（留空则使用所选数据源的默认地址）</label>'
    . '<input type="text" name="market_api" value="' . h($current['market_api']) . '" placeholder="https://...">'
    . '<div class="help">若服务器无法访问境外接口，可在 inc/config.php 中配置 http_proxy，或直接关闭行情模块。</div></div>';
echo '<div class="field"><label>缓存时间（分钟）</label><input type="number" name="market_ttl" value="' . intval($current['market_ttl']) . '" min="1" max="1440"></div>';
echo '</div></div>';
echo '<div class="form-actions"><button class="btn btn-primary" type="submit">保存设置</button></div>';
echo '</form>';

echo '<h2>安全</h2><div class="panel"><div class="form-grid">';
echo '<div class="field full"><label>Web 触发密钥（定时任务用）</label>'
    . '<div class="code" style="white-space:normal;word-break:break-all">' . h($cronKey) . '</div>'
    . '<div class="help">调用地址：' . h(cn_base_url() . '/cron.php?key=' . $cronKey) . '</div></div>';
echo '</div><div class="form-actions">'
    . '<form method="post" action="settings.php" class="inline-form" onsubmit="return confirm(\'重新生成密钥后，原 Web 触发地址将失效，确认？\')">'
    . cn_csrf_field() . '<input type="hidden" name="action" value="regen_key">'
    . '<button class="btn btn-sm" type="submit">重新生成密钥</button></form>'
    . '<form method="post" action="settings.php" class="inline-form">' . cn_csrf_field()
    . '<input type="hidden" name="action" value="refresh_market">'
    . '<button class="btn btn-sm" type="submit">立即刷新行情缓存</button></form></div></div>';

echo '<div class="panel"><h3 class="panel-title">修改管理员密码</h3>'
    . '<form method="post" action="settings.php">' . cn_csrf_field() . '<input type="hidden" name="action" value="password">'
    . '<div class="form-grid">'
    . '<div class="field"><label>原密码</label><input type="password" name="old_password" required></div>'
    . '<div class="field"><label>新密码（至少 8 位）</label><input type="password" name="new_password" required></div>'
    . '<div class="field"><label>确认新密码</label><input type="password" name="new_password2" required></div>'
    . '</div><div class="form-actions"><button class="btn btn-primary" type="submit">修改密码</button></div></form></div>';
cn_admin_foot();
