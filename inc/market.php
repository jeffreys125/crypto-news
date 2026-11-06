<?php
/**
 * 行情模块（可选）
 *
 * 从公开行情接口拉取少量主流币种的 24h 报价，结果缓存到数据库。
 * 接口不可达时自动沿用旧缓存；完全没有数据时返回空数组，前台会隐藏行情条，
 * 因此即使服务器无法访问境外 API，也不影响资讯采集与浏览。
 */
if (!defined('CN_ROOT')) {
    die('Access denied');
}

/** 各数据源的默认接口地址 */
function cn_market_default_api($provider)
{
    if ($provider === 'binance') {
        $symbols = array('BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'SOLUSDT', 'XRPUSDT', 'DOGEUSDT', 'ADAUSDT', 'AVAXUSDT');
        return 'https://api.binance.com/api/v3/ticker/24hr?symbols=' . rawurlencode(json_encode($symbols));
    }
    return 'https://api.coingecko.com/api/v3/coins/markets'
        . '?vs_currency=usd&order=market_cap_desc&per_page=8&page=1&sparkline=false'
        . '&price_change_percentage=24h'
        . '&ids=bitcoin,ethereum,binancecoin,solana,ripple,dogecoin,cardano,avalanche-2';
}

/** 币种名称映射（Binance 接口只有交易对代码） */
function cn_market_names()
{
    return array(
        'BTC' => 'Bitcoin', 'ETH' => 'Ethereum', 'BNB' => 'BNB', 'SOL' => 'Solana',
        'XRP' => 'XRP', 'DOGE' => 'Dogecoin', 'ADA' => 'Cardano', 'AVAX' => 'Avalanche',
        'LTC' => 'Litecoin', 'TRX' => 'TRON', 'DOT' => 'Polkadot', 'LINK' => 'Chainlink',
    );
}

/** 将不同接口返回的数据统一成内部格式 */
function cn_market_normalize($raw, $provider)
{
    $out = array();
    if (!is_array($raw)) {
        return $out;
    }
    if ($provider === 'binance') {
        if (isset($raw['symbol'])) {
            $raw = array($raw);
        }
        $names = cn_market_names();
        foreach ($raw as $row) {
            if (!is_array($row) || !isset($row['symbol'])) {
                continue;
            }
            $sym = preg_replace('/USDT$/', '', strtoupper($row['symbol']));
            $out[] = array(
                'symbol' => $sym,
                'name'   => isset($names[$sym]) ? $names[$sym] : $sym,
                'price'  => isset($row['lastPrice']) ? floatval($row['lastPrice']) : 0,
                'change' => isset($row['priceChangePercent']) ? floatval($row['priceChangePercent']) : 0,
            );
        }
        return $out;
    }
    // CoinGecko / 自定义（同结构）
    foreach ($raw as $row) {
        if (!is_array($row) || !isset($row['symbol'])) {
            continue;
        }
        $change = 0;
        if (isset($row['price_change_percentage_24h']) && is_numeric($row['price_change_percentage_24h'])) {
            $change = floatval($row['price_change_percentage_24h']);
        } elseif (isset($row['price_change_24h']) && is_numeric($row['price_change_24h'])) {
            $change = floatval($row['price_change_24h']);
        }
        $out[] = array(
            'symbol' => strtoupper((string) $row['symbol']),
            'name'   => isset($row['name']) ? (string) $row['name'] : strtoupper((string) $row['symbol']),
            'price'  => isset($row['current_price']) ? floatval($row['current_price']) : 0,
            'change' => $change,
        );
    }
    return $out;
}

/** 读取行情（优先缓存，缓存过期才请求接口） */
function cn_market_fetch($force = false)
{
    if (cn_setting('market_enabled', '1') !== '1') {
        return array();
    }
    $ttl = max(1, intval(cn_setting('market_ttl', '10'))) * 60;
    $cached = array();
    try {
        $row = cn_db_one('SELECT payload, updated_at FROM ' . cn_table('market_cache') . ' WHERE cache_key = ?', array('default'));
        if ($row && $row['payload']) {
            $decoded = json_decode($row['payload'], true);
            if (is_array($decoded)) {
                $cached = $decoded;
                if (!$force && (time() - strtotime($row['updated_at'])) < $ttl) {
                    return $cached;
                }
            }
        }
        $provider = cn_setting('market_provider', 'coingecko');
        $api = cn_setting('market_api', '');
        if ($api === '') {
            $api = cn_market_default_api($provider);
        }
        $resp = cn_http_get($api, array('timeout' => 10));
        if ($resp['error'] === '' && $resp['code'] >= 200 && $resp['code'] < 300 && $resp['body'] !== '') {
            $raw = json_decode($resp['body'], true);
            $items = cn_market_normalize($raw, $provider);
            if (!empty($items)) {
                cn_db_exec(
                    'INSERT INTO ' . cn_table('market_cache') . ' (cache_key, payload, updated_at) VALUES (?,?,?) '
                    . 'ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = VALUES(updated_at)',
                    array('default', json_encode($items), cn_now())
                );
                return $items;
            }
        }
    } catch (Exception $e) {
        // 行情属于增值模块，任何异常都不应影响主站
    }
    // 失败冷却：把空数组或旧数据写回缓存并刷新时间戳。
    // 这样在缓存周期内不会对每个页面访问都发起一次可能超时的外部请求。
    try {
        cn_db_exec(
            'INSERT INTO ' . cn_table('market_cache') . ' (cache_key, payload, updated_at) VALUES (?,?,?) '
            . 'ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = VALUES(updated_at)',
            array('default', json_encode($cached), cn_now())
        );
    } catch (Exception $e) {
        // 忽略
    }
    return $cached;
}
