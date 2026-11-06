<?php
/**
 * 自检脚本（命令行）
 *
 *   php tests/selftest.php          离线测试：解析器、URL 规范化、去重、字符集
 *   php tests/selftest.php --live   联网测试：实际抓取预置 RSS 源并解析
 *
 * 部署前建议先跑一次，用于确认服务器 PHP 扩展与出站网络是否正常。
 */
define('CN_ROOT', dirname(dirname(__FILE__)));
require_once CN_ROOT . '/inc/functions.php';
require_once CN_ROOT . '/inc/collector.php';
date_default_timezone_set('Asia/Shanghai');

$live = in_array('--live', $argv);
$pass = 0;
$fail = 0;

function check($name, $ok, $detail = '')
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  [OK]   $name" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    } else {
        $fail++;
        echo "  [FAIL] $name" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}

echo "CryptoIndex 自检\n";
echo "PHP " . PHP_VERSION . " | " . PHP_SAPI . "\n";
echo "扩展: " . (extension_loaded('pdo_mysql') ? 'pdo_mysql OK' : 'pdo_mysql 缺失')
    . ', ' . (extension_loaded('curl') ? 'curl OK' : 'curl 缺失')
    . ', ' . (extension_loaded('simplexml') ? 'simplexml OK' : 'simplexml 缺失')
    . ', ' . (extension_loaded('mbstring') ? 'mbstring OK' : 'mbstring 缺失(可选)') . "\n\n";

echo "[1] 环境\n";
check('PDO MySQL 驱动', extension_loaded('pdo_mysql'));
check('SimpleXML', extension_loaded('simplexml'));
check('cURL 或 allow_url_fopen', function_exists('curl_init') || ini_get('allow_url_fopen'));

$rss = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:media="http://search.yahoo.com/mrss/">
<channel><title>Sample</title>
<item>
  <title><![CDATA[Bitcoin ETF sees record inflow as regulators meet]]></title>
  <link>https://example.com/news/btc-etf?utm_source=rss&amp;id=42#comments</link>
  <guid isPermaLink="false">guid-1</guid>
  <pubDate>Thu, 05 Nov 2026 09:30:00 +0000</pubDate>
  <dc:creator><![CDATA[Jane Doe]]></dc:creator>
  <media:content url="https://example.com/img/a.jpg" medium="image"/>
  <description><![CDATA[<p>Bitcoin ETF inflows hit a <b>record</b> while regulators discussed new rules.</p>]]></description>
  <content:encoded><![CDATA[<p>Full article body that must NOT be stored.</p>]]></content:encoded>
  <category><![CDATA[Regulation]]></category>
</item>
<item>
  <title>Second item without description</title>
  <link>https://example.com/news/second</link>
  <pubDate>Thu, 05 Nov 2026 08:00:00 +0000</pubDate>
</item>
</channel></rss>
XML;

$atom = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom"><title>Sample Atom</title>
<entry>
  <title>Ethereum layer 2 rollup upgrade goes live</title>
  <link rel="alternate" href="https://example.org/atom/1"/>
  <updated>2026-11-05T10:00:00Z</updated>
  <author><name>Satoshi T.</name></author>
  <summary>Developers shipped a major rollup upgrade on testnet.</summary>
  <category term="Technology"/>
</entry></feed>
XML;

echo "\n[2] 解析器\n";
$items = cn_parse_feed($rss, 'https://example.com/rss');
check('RSS 2.0 条目数', count($items) === 2, count($items) . ' 条');
if (count($items) >= 1) {
    $i = $items[0];
    check('标题 CDATA', $i['title'] === 'Bitcoin ETF sees record inflow as regulators meet', $i['title']);
    check('URL 去 utm/fragment', $i['url'] === 'https://example.com/news/btc-etf?id=42', $i['url']);
    check('摘要为纯文本', strpos($i['summary'], '<p>') === false && strpos($i['summary'], 'record') !== false, $i['summary']);
    check('摘要不含全文', strpos($i['summary'], 'Full article body') === false);
    check('media 缩略图', $i['thumb'] === 'https://example.com/img/a.jpg', $i['thumb']);
    check('dc:creator', $i['author'] === 'Jane Doe', $i['author']);
    check('自动标签', strpos($i['tags'], 'ETF') !== false || strpos($i['tags'], '监管') !== false, $i['tags']);
    check('时间格式', preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $i['published_at']) === 1, $i['published_at']);
}
$aItems = cn_parse_feed($atom, 'https://example.org/feed');
check('Atom 1.0 条目数', count($aItems) === 1, count($aItems) . ' 条');
if (count($aItems) === 1) {
    check('Atom link[href]', $aItems[0]['url'] === 'https://example.org/atom/1', $aItems[0]['url']);
    check('Atom author>name', $aItems[0]['author'] === 'Satoshi T.', $aItems[0]['author']);
    check('非法 XML 不崩溃', is_array(cn_parse_feed('<rss><broken', 'https://x.com')));
}

echo "\n[3] 工具函数\n";
check('4 字节字符过滤', cn_strip_4byte('A😀B') === 'AB');
check('内网地址拦截', cn_validate_feed_url('http://127.0.0.1/admin')[0] === false);
check('公网地址放行', cn_validate_feed_url('https://cointelegraph.com/rss')[0] === true);
check('相对 URL 补全', cn_normalize_url('/news/1', 'https://a.com/rss/feed.xml') === 'https://a.com/news/1');
check('密码哈希与校验', cn_verify_password('p@ssw0rd', cn_hash_password('p@ssw0rd')) === true);

if ($live) {
    echo "\n[4] 联网抓取（每个源仅取前 3 条用于验证）\n";
    $sources = cn_default_sources();
    foreach ($sources as $s) {
        $resp = cn_http_get($s['feed_url'], array('timeout' => 15));
        if ($resp['code'] !== 200 || $resp['body'] === '') {
            echo '  [跳过] ' . str_pad($s['name'], 20) . ' HTTP ' . $resp['code'] . ' ' . cn_cut($resp['error'], 60) . "\n";
            continue;
        }
        $got = cn_parse_feed($resp['body'], $s['feed_url']);
        $sample = count($got) ? cn_cut($got[0]['title'], 48) : '(无标题)';
        echo '  [' . (count($got) ? 'OK' : '空') . ']   ' . str_pad($s['name'], 20) . ' ' . count($got) . ' 条 | ' . $sample . "\n";
        if (count($got) === 0) {
            $fail++;
        } else {
            $pass++;
        }
    }
}

echo "\n结果: $pass 通过, $fail 失败\n";
exit($fail > 0 ? 1 : 0);
