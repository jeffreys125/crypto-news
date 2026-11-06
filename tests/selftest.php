<?php
/**
 * Self-test (CLI)
 *
 *   php tests/selftest.php              Offline: parser, full-text sanitizer, URL, charset
 *   php tests/selftest.php --live       Online: fetch every preset RSS feed and parse it
 *   php tests/selftest.php --fulltext   Online: fetch 2 real article pages and extract the body
 *
 * Run once on your server before deployment to verify extensions and outbound network.
 */
define('CN_ROOT', dirname(dirname(__FILE__)));
require_once CN_ROOT . '/inc/functions.php';
require_once CN_ROOT . '/inc/collector.php';
require_once CN_ROOT . '/inc/fulltext.php';
date_default_timezone_set('Asia/Shanghai');

$argvLocal = isset($argv) && is_array($argv) ? $argv : array();
$live = in_array('--live', $argvLocal);
$fulltext = in_array('--fulltext', $argvLocal);
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

echo "CryptoIndex self-test (v2 full-text)\n";
echo "PHP " . PHP_VERSION . " | " . PHP_SAPI . "\n";
echo "Extensions: " . (extension_loaded('pdo_mysql') ? 'pdo_mysql OK' : 'pdo_mysql MISSING')
    . ', ' . (extension_loaded('curl') ? 'curl OK' : 'curl MISSING')
    . ', ' . (extension_loaded('simplexml') ? 'simplexml OK' : 'simplexml MISSING')
    . ', ' . (extension_loaded('mbstring') ? 'mbstring OK' : 'mbstring MISSING(optional)')
    . ', ' . (class_exists('DOMDocument') ? 'DOM OK' : 'DOM MISSING(regex fallback)') . "\n\n";

echo "[1] Environment\n";
check('PDO MySQL driver', extension_loaded('pdo_mysql'));
check('SimpleXML', extension_loaded('simplexml'));
check('cURL or allow_url_fopen', function_exists('curl_init') || ini_get('allow_url_fopen'));

$longBody = '<p>Bitcoin ETF inflows hit a <b>record</b> while regulators discussed new rules in Washington.</p>'
    . '<p>Analysts said institutional demand continued to accelerate through the quarter, with several large asset managers filing amendments to their spot bitcoin exchange traded fund applications. The inflows were broad based across both new and existing products.</p>'
    . '<p>Market participants also pointed to improving liquidity and a tighter regulatory framework as reasons for the sustained appetite. Trading volumes on major exchanges rose sharply during the session, and derivatives positioning turned more constructive.</p>'
    . '<script>alert("xss")</script>'
    . '<img src="/images/chart.png" onerror="alert(1)" alt="Chart">'
    . '<a href="javascript:alert(1)">bad link</a> <a href="/news/followup">Follow-up</a>';

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
  <description><![CDATA[<p>Bitcoin ETF inflows hit a record while regulators discussed new rules.</p>]]></description>
  <content:encoded><![CDATA[{$longBody}]]></content:encoded>
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

echo "\n[2] Feed parser\n";
$items = cn_parse_feed($rss, 'https://example.com/rss');
check('RSS 2.0 item count', count($items) === 2, count($items) . ' items');
if (count($items) >= 1) {
    $i = $items[0];
    check('Title from CDATA', $i['title'] === 'Bitcoin ETF sees record inflow as regulators meet', $i['title']);
    check('URL strips utm/fragment', $i['url'] === 'https://example.com/news/btc-etf?id=42', $i['url']);
    check('Summary is plain text', strpos($i['summary'], '<p>') === false && strpos($i['summary'], 'record') !== false, $i['summary']);
    check('Summary stays short', strpos($i['summary'], 'institutional demand') === false);
    check('Full HTML extracted from content:encoded', isset($i['content_html']) && strpos($i['content_html'], 'institutional demand') !== false);
    check('Full HTML recognized as full text', cn_is_full_html($i['content_html']));
    check('Short excerpt is NOT full text', cn_is_full_html('<p>short</p>') === false);
    check('media thumbnail', $i['thumb'] === 'https://example.com/img/a.jpg', $i['thumb']);
    check('dc:creator', $i['author'] === 'Jane Doe', $i['author']);
    check('English tags', strpos($i['tags'], 'ETF') !== false || strpos($i['tags'], 'Regulation') !== false, $i['tags']);
    check('Date format', preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $i['published_at']) === 1, $i['published_at']);
}
$aItems = cn_parse_feed($atom, 'https://example.org/feed');
check('Atom 1.0 item count', count($aItems) === 1, count($aItems) . ' item');
if (count($aItems) === 1) {
    check('Atom link[href]', $aItems[0]['url'] === 'https://example.org/atom/1', $aItems[0]['url']);
    check('Atom author>name', $aItems[0]['author'] === 'Satoshi T.', $aItems[0]['author']);
    check('Malformed XML does not crash', is_array(cn_parse_feed('<rss><broken', 'https://x.com')));
}

echo "\n[3] Utilities\n";
check('4-byte chars stripped', cn_strip_4byte('A😀B') === 'AB');
check('Private feed URL rejected', cn_validate_feed_url('http://127.0.0.1/admin')[0] === false);
check('Public feed URL allowed', cn_validate_feed_url('https://cointelegraph.com/rss')[0] === true);
check('Relative URL resolved', cn_normalize_url('/news/1', 'https://a.com/rss/feed.xml') === 'https://a.com/news/1');
check('Password hash & verify', cn_verify_password('p@ssw0rd', cn_hash_password('p@ssw0rd')) === true);
check('English categories', cn_categories() === array('General', 'Markets', 'Blockchain', 'Regulation', 'Technology', 'Security', 'Industry'));

echo "\n[4] Full-text sanitizer (XSS / URL safety)\n";
$dirty = '<div class="wrap" onclick="bad()"><p style="color:red" onclick="bad()">Hello <b>world</b></p>'
    . '<script>alert("xss")</script><img src="/img/a.png" onerror="alert(1)" alt="A chart">'
    . '<a href="javascript:alert(1)">bad</a><a href="/next">Next</a>'
    . '<iframe src="https://evil.example/x"></iframe></div>';
$clean = cn_sanitize_html($dirty, 'https://example.com/news/post.html');
check('script content removed', strpos($clean, 'alert("xss")') === false, cn_cut($clean, 90));
check('event handlers removed', stripos($clean, 'onerror') === false && stripos($clean, 'onclick') === false);
check('javascript: href removed', stripos($clean, 'javascript:') === false);
check('iframe removed', stripos($clean, '<iframe') === false);
check('class / style removed', strpos($clean, 'class=') === false && strpos($clean, 'style=') === false);
check('paragraph kept', strpos($clean, '<p') !== false && strpos($clean, 'Hello') !== false);
check('relative image URL absolutized', strpos($clean, 'https://example.com/img/a.png') !== false);
check('image gets no-referrer', strpos($clean, 'no-referrer') !== false);
check('relative link absolutized', strpos($clean, 'https://example.com/next') !== false);
check('external links nofollow', strpos($clean, 'nofollow') !== false);

echo "\n[5] Main content extraction\n";
$page = '<html><head><title>T</title></head><body>'
    . '<nav><a href="/">Home Menu Navigation</a></nav>'
    . '<aside>Sidebar advertisement text goes here</aside>'
    . '<article class="post-content"><h1>Real Headline</h1>'
    . str_repeat('<p>This is the real article paragraph with enough words to be selected as the main content block.</p> ', 12)
    . '</article><footer>Footer links and copyright</footer></body></html>';
$main = cn_extract_main_content($page, 'https://example.com/a.html');
check('article body selected', strpos($main, 'Real Headline') !== false && strpos($main, 'real article paragraph') !== false);
check('navigation excluded', strpos($main, 'Home Menu Navigation') === false);
check('sidebar excluded', strpos($main, 'Sidebar advertisement') === false);
check('private article URL rejected', cn_safe_article_url('http://127.0.0.1/admin') === false);
check('public article URL allowed', cn_safe_article_url('https://example.com/a.html') === true);
check('prepared content strips emoji', strpos(cn_prepare_content('<p>A😀B</p>'), '😀') === false);

if ($live) {
    echo "\n[6] Live feed check (preset sources)\n";
    foreach (cn_default_sources() as $s) {
        $resp = cn_http_get($s['feed_url'], array('timeout' => 15));
        if ($resp['code'] !== 200 || $resp['body'] === '') {
            echo '  [SKIP] ' . str_pad($s['name'], 20) . ' HTTP ' . $resp['code'] . ' ' . cn_cut($resp['error'], 60) . "\n";
            continue;
        }
        $got = cn_parse_feed($resp['body'], $s['feed_url']);
        $sample = count($got) ? cn_cut($got[0]['title'], 48) : '(no title)';
        $fullCount = 0;
        foreach ($got as $g) {
            if (cn_is_full_html($g['content_html'])) {
                $fullCount++;
            }
        }
        echo '  [' . (count($got) ? 'OK' : 'EMPTY') . ']   ' . str_pad($s['name'], 20) . ' '
            . count($got) . ' items | full-text in feed: ' . $fullCount . ' | ' . $sample . "\n";
        if (count($got) === 0) {
            $fail++;
        } else {
            $pass++;
        }
    }
}

if ($fulltext) {
    echo "\n[7] Live article-page extraction (first 2 sources, 1 article each)\n";
    foreach (array_slice(cn_default_sources(), 0, 2) as $s) {
        $resp = cn_http_get($s['feed_url'], array('timeout' => 15));
        $got = $resp['body'] !== '' ? cn_parse_feed($resp['body'], $s['feed_url']) : array();
        if (empty($got)) {
            echo "  [SKIP] " . $s['name'] . " (feed empty)\n";
            continue;
        }
        $url = $got[0]['url'];
        $r = cn_http_get_multi(array(0 => $url), array('timeout' => 12));
        $pageHtml = isset($r[0]) ? $r[0]['body'] : '';
        $mainHtml = $pageHtml !== '' ? cn_extract_main_content(cn_to_utf8($pageHtml, $r[0]['content_type']), $r[0]['final_url']) : '';
        $cleanHtml = $mainHtml !== '' ? cn_sanitize_html($mainHtml, $r[0]['final_url']) : '';
        $len = cn_text_length($cleanHtml);
        echo '  [' . ($len >= 120 ? 'OK' : 'MISS') . ']   ' . str_pad($s['name'], 20)
            . ' HTTP ' . (isset($r[0]) ? $r[0]['code'] : 0) . ' | clean text: ' . $len . ' chars | ' . cn_cut($url, 60) . "\n";
        if ($len >= 120) {
            $pass++;
        }
    }
}

echo "\nResult: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
