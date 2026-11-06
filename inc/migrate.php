<?php
/**
 * 数据库结构自动升级（v1 -> v2 全文版）
 *
 * 设计目标：老站点直接覆盖新代码即可，无需手工执行 SQL。
 *  - 首次请求检测到 db_schema < 2 时，自动补齐 content / content_fetched 列；
 *  - 把 v1 的中文栏目、中文标签、旧出厂文案平滑转成英文；
 *  - 全部语句幂等，迁移成功后写入 db_schema=2，之后不再触发；
 *  - 任何异常都静默降级，不阻断站点访问（常见于数据库账号无 ALTER 权限）。
 */
if (!defined('CN_ROOT')) {
    die('Access denied');
}

/** 目标结构版本 */
function cn_schema_target_version()
{
    return 2;
}

/** 当前库是否已有全文字段（采集器据此降级，避免 ALTER 失败时写入报错） */
function cn_has_fulltext_column()
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    $has = false;
    try {
        $cols = cn_db_all('SHOW COLUMNS FROM ' . cn_table('articles'));
        foreach ($cols as $c) {
            if (isset($c['Field']) && $c['Field'] === 'content') {
                $has = true;
                break;
            }
        }
    } catch (Exception $e) {
        $has = false;
    }
    return $has;
}

/** 执行自动迁移 */
function cn_schema_upgrade()
{
    $target = cn_schema_target_version();
    try {
        // 直接查库，绕过 cn_setting 的请求内缓存
        $ver = intval(cn_db_value(
            'SELECT svalue FROM ' . cn_table('settings') . " WHERE skey = 'db_schema'",
            array(), 0
        ));
    } catch (Exception $e) {
        return; // 表不存在 / 库不可用：交给正常错误处理，不在此阻断
    }
    if ($ver >= $target) {
        return;
    }

    try {
        // 1. 幂等补列（先查再 ALTER，重复执行不会报错）
        $cols = cn_db_all('SHOW COLUMNS FROM ' . cn_table('articles'));
        $names = array();
        foreach ($cols as $c) {
            $names[] = isset($c['Field']) ? $c['Field'] : '';
        }
        if (!in_array('content', $names)) {
            cn_db_exec('ALTER TABLE ' . cn_table('articles')
                . ' ADD COLUMN `content` MEDIUMTEXT NULL AFTER `summary`');
        }
        if (!in_array('content_fetched', $names)) {
            cn_db_exec('ALTER TABLE ' . cn_table('articles')
                . ' ADD COLUMN `content_fetched` TINYINT(1) NOT NULL DEFAULT 0 AFTER `content`');
        }

        // 2. 中文栏目 -> 英文
        $catMap = array(
            '综合资讯' => 'General',
            '行情分析' => 'Markets',
            '区块链'   => 'Blockchain',
            '监管政策' => 'Regulation',
            '技术生态' => 'Technology',
            '安全事件' => 'Security',
            '行业动态' => 'Industry',
        );
        foreach ($catMap as $cn => $en) {
            cn_db_exec('UPDATE ' . cn_table('articles') . ' SET category = ? WHERE category = ?', array($en, $cn));
            cn_db_exec('UPDATE ' . cn_table('sources') . ' SET category = ? WHERE category = ?', array($en, $cn));
        }

        // 3. 中文标签 -> 英文（只命中包含中文标签的行，减少全表写入）
        $tagMap = array(
            '比特币'   => 'Bitcoin',
            '以太坊'   => 'Ethereum',
            '监管政策' => 'Regulation',
            '稳定币'   => 'Stablecoin',
            '交易所'   => 'Exchange',
            '安全事件' => 'Security',
            '挖矿'     => 'Mining',
            '行情'     => 'Markets',
        );
        foreach ($tagMap as $cn => $en) {
            cn_db_exec('UPDATE ' . cn_table('articles')
                . ' SET tags = REPLACE(tags, ?, ?) WHERE tags LIKE ?',
                array($cn, $en, '%' . $cn . '%'));
        }

        // 4. 旧出厂文案 -> 英文（仅当与旧默认值完全一致，绝不覆盖用户自定义内容）
        $oldDesc = '虚拟货币与区块链资讯聚合索引，自动收录公开 RSS 的标题、摘要与原文链接。';
        $oldDesc2 = '虚拟货币与区块链资讯聚合索引';
        $newDesc = 'Crypto news aggregator. Full articles are collected from public RSS/Atom feeds and mirrored for reading.';
        $oldKw = '区块链资讯,虚拟货币,加密货币,行业动态,政策监管';
        $newKw = 'crypto news, bitcoin, ethereum, blockchain, markets, regulation';
        $oldDisclaimer1 = '本站为技术性信息聚合索引，仅收录公开资讯的标题与摘要并保留原文链接，不存储、不转载全文，版权归原作者及原媒体所有。内容不构成任何投资建议，不提供交易服务，请遵守所在国家或地区的法律法规。';
        $oldDisclaimer2 = '本站为技术性信息聚合索引工具，仅收录公开 RSS 的标题、摘要与原文链接，不存储、不转载全文，版权归原作者及原媒体所有。本站不提供虚拟货币交易、兑换、理财或投资建议服务，内容不构成任何投资建议；请遵守所在国家或地区关于虚拟货币的相关法律法规。';
        $newDisclaimer = 'This site aggregates and mirrors full articles from public RSS/Atom feeds for technical indexing purposes. All content remains the property of its original authors and publishers; source attribution and the original URL are retained. This site does not provide trading, exchange, or investment advisory services, and nothing here constitutes investment advice. Please comply with the laws and regulations of your jurisdiction.';

        $pairs = array(
            'site_name'     => array('链讯 CryptoIndex', 'CryptoIndex'),
            'site_desc'     => array($oldDesc, $newDesc),
            'site_keywords' => array($oldKw, $newKw),
            'disclaimer'    => array($oldDisclaimer1, $newDisclaimer),
        );
        foreach ($pairs as $key => $pair) {
            cn_db_exec('UPDATE ' . cn_table('settings') . ' SET svalue = ? WHERE skey = ? AND svalue = ?',
                array($pair[1], $key, $pair[0]));
        }
        // 兼容另外两个历史默认变体
        cn_db_exec('UPDATE ' . cn_table('settings') . ' SET svalue = ? WHERE skey = ? AND svalue = ?',
            array($newDesc, 'site_desc', $oldDesc2));
        cn_db_exec('UPDATE ' . cn_table('settings') . ' SET svalue = ? WHERE skey = ? AND svalue = ?',
            array($newDisclaimer, 'disclaimer', $oldDisclaimer2));

        // 5. 标记版本，后续请求不再执行迁移
        cn_set_setting('db_schema', (string) $target);
    } catch (Exception $e) {
        // 常见于数据库账号没有 ALTER 权限；采集器会自动降级为 v1 行为
    }
}
