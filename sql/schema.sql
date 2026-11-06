-- CryptoIndex 数据库结构（v2 · 全文版）
-- 目标：MySQL 5.5 / 5.6 / 5.7 / 8.0 / MariaDB 全兼容
-- 约束：仅使用 utf8(utf8mb3)、无 JSON 字段、无 DATETIME 函数默认值、
--       无 CTE / 窗口函数、所有索引长度均小于 767 字节。
-- 占位符 {{PREFIX}} 由 install.php 替换为实际表前缀。
-- 老版本（v1）升级无需手工执行 SQL，程序会在首次请求时自动迁移，
-- 详见 inc/migrate.php；sql/upgrade.sql 供 DBA 手动核对使用。

SET NAMES utf8;

CREATE TABLE IF NOT EXISTS `{{PREFIX}}settings` (
  `skey` varchar(64) NOT NULL,
  `svalue` text,
  PRIMARY KEY (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `{{PREFIX}}admin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `{{PREFIX}}sources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `site_url` varchar(255) NOT NULL DEFAULT '',
  `feed_url` varchar(500) NOT NULL,
  `category` varchar(50) NOT NULL DEFAULT 'General',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `interval_min` int(11) NOT NULL DEFAULT '30',
  `max_items` int(11) NOT NULL DEFAULT '30',
  `etag` varchar(255) NOT NULL DEFAULT '',
  `last_modified` varchar(255) NOT NULL DEFAULT '',
  `last_collect_at` datetime DEFAULT NULL,
  `last_status` varchar(20) NOT NULL DEFAULT 'never',
  `last_message` varchar(500) NOT NULL DEFAULT '',
  `fail_count` int(11) NOT NULL DEFAULT '0',
  `total_items` int(11) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `{{PREFIX}}articles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source_id` int(11) NOT NULL DEFAULT '0',
  `source_name` varchar(100) NOT NULL DEFAULT '',
  `title` varchar(255) NOT NULL,
  `url` varchar(500) NOT NULL,
  `url_hash` char(32) NOT NULL,
  `summary` text,
  `content` mediumtext,
  `content_fetched` tinyint(1) NOT NULL DEFAULT '0',
  `thumb` varchar(500) NOT NULL DEFAULT '',
  `author` varchar(100) NOT NULL DEFAULT '',
  `category` varchar(50) NOT NULL DEFAULT 'General',
  `tags` varchar(255) NOT NULL DEFAULT '',
  `published_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  `is_top` tinyint(1) NOT NULL DEFAULT '0',
  `is_recommend` tinyint(1) NOT NULL DEFAULT '0',
  `views` int(11) NOT NULL DEFAULT '0',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_url_hash` (`url_hash`),
  KEY `idx_published` (`published_at`),
  KEY `idx_category` (`category`),
  KEY `idx_top` (`is_top`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `{{PREFIX}}collect_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source_id` int(11) NOT NULL DEFAULT '0',
  `source_name` varchar(100) NOT NULL DEFAULT '',
  `status` varchar(20) NOT NULL DEFAULT 'ok',
  `fetched` int(11) NOT NULL DEFAULT '0',
  `added` int(11) NOT NULL DEFAULT '0',
  `message` varchar(500) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `{{PREFIX}}market_cache` (
  `cache_key` varchar(64) NOT NULL,
  `payload` text,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`cache_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
