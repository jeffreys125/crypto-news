-- CryptoIndex v1 -> v2（全文版）手动升级脚本
--
-- 【重要】程序已内置自动迁移：老站升级后首次访问任意页面时，inc/migrate.php
-- 会自动补列、把中文栏目/标签转成英文、更新旧默认文案并写入 db_schema=2。
-- 正常情况下【不需要】执行本文件。本文件仅供 DBA 复核或在自动迁移
-- 受权限限制（例如数据库账号没有 ALTER 权限）时手工执行。
--
-- 手工执行前：把 {{PREFIX}} 替换为实际表前缀（默认 cn_）；
-- 每条 ALTER 只能执行一次，重复执行会报"列已存在"。

SET NAMES utf8;

-- 1. 新增全文字段
ALTER TABLE `{{PREFIX}}articles`
  ADD COLUMN `content` mediumtext NULL AFTER `summary`,
  ADD COLUMN `content_fetched` tinyint(1) NOT NULL DEFAULT '0' AFTER `content`;

-- 2. 栏目默认值英文化（不影响已有数据）
ALTER TABLE `{{PREFIX}}articles` MODIFY COLUMN `category` varchar(50) NOT NULL DEFAULT 'General';
ALTER TABLE `{{PREFIX}}sources`  MODIFY COLUMN `category` varchar(50) NOT NULL DEFAULT 'General';

-- 3. 已有数据的栏目中 -> 英
UPDATE `{{PREFIX}}articles` SET `category` = CASE `category`
  WHEN '综合资讯' THEN 'General'
  WHEN '行情分析' THEN 'Markets'
  WHEN '区块链'   THEN 'Blockchain'
  WHEN '监管政策' THEN 'Regulation'
  WHEN '技术生态' THEN 'Technology'
  WHEN '安全事件' THEN 'Security'
  WHEN '行业动态' THEN 'Industry'
  ELSE `category` END;
UPDATE `{{PREFIX}}sources` SET `category` = CASE `category`
  WHEN '综合资讯' THEN 'General'
  WHEN '行情分析' THEN 'Markets'
  WHEN '区块链'   THEN 'Blockchain'
  WHEN '监管政策' THEN 'Regulation'
  WHEN '技术生态' THEN 'Technology'
  WHEN '安全事件' THEN 'Security'
  WHEN '行业动态' THEN 'Industry'
  ELSE `category` END;

-- 4. 已有标签中 -> 英
UPDATE `{{PREFIX}}articles` SET `tags` = REPLACE(`tags`, '比特币',   'Bitcoin')    WHERE `tags` LIKE '%比特币%';
UPDATE `{{PREFIX}}articles` SET `tags` = REPLACE(`tags`, '以太坊',   'Ethereum')   WHERE `tags` LIKE '%以太坊%';
UPDATE `{{PREFIX}}articles` SET `tags` = REPLACE(`tags`, '监管政策', 'Regulation') WHERE `tags` LIKE '%监管政策%';
UPDATE `{{PREFIX}}articles` SET `tags` = REPLACE(`tags`, '稳定币',   'Stablecoin') WHERE `tags` LIKE '%稳定币%';
UPDATE `{{PREFIX}}articles` SET `tags` = REPLACE(`tags`, '交易所',   'Exchange')   WHERE `tags` LIKE '%交易所%';
UPDATE `{{PREFIX}}articles` SET `tags` = REPLACE(`tags`, '安全事件', 'Security')   WHERE `tags` LIKE '%安全事件%';
UPDATE `{{PREFIX}}articles` SET `tags` = REPLACE(`tags`, '挖矿',     'Mining')     WHERE `tags` LIKE '%挖矿%';
UPDATE `{{PREFIX}}articles` SET `tags` = REPLACE(`tags`, '行情',     'Markets')    WHERE `tags` LIKE '%行情%';

-- 5. 旧默认站点名（仅当仍是出厂值时才改，避免覆盖用户自定义名称）
UPDATE `{{PREFIX}}settings` SET `svalue` = 'CryptoIndex'
  WHERE `skey` = 'site_name' AND `svalue` = '链讯 CryptoIndex';

-- 6. 标记结构版本，之后不再触发自动迁移
INSERT INTO `{{PREFIX}}settings` (`skey`, `svalue`) VALUES ('db_schema', '2')
  ON DUPLICATE KEY UPDATE `svalue` = VALUES(`svalue`);
