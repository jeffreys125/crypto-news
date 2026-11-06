<?php
/**
 * 配置文件模板。
 *
 * 正常部署请直接访问 install.php，向导会自动生成 inc/config.php。
 * 只有在需要手工修改数据库账号、或迁移服务器时，才需要对照本文件编辑。
 * 注意：inc/config.php 由安装向导生成，请勿提交到公开代码仓库。
 */
return array(
    // 数据库连接
    'db_host'     => 'localhost',
    'db_port'     => '3306',
    'db_name'     => 'crypto_news',
    'db_user'     => 'crypto_user',
    'db_pass'     => 'change-me',
    'table_prefix'=> 'cn_',

    // 站点基础地址，留空则自动探测；形如 https://news.example.com
    'base_url'    => '',
    'timezone'    => 'Asia/Shanghai',

    // 调试模式：上线请改为 false
    'debug'       => false,

    // 出站 HTTPS 证书校验。部分老服务器 CA 证书过旧会导致采集失败，
    // 默认关闭；若服务器证书环境正常，建议改为 true 提升安全性。
    'ssl_verify'  => false,

    // 可选：采集与行情请求使用的 HTTP 代理，留空表示直连。
    // 服务器无法直接访问境外 RSS 时可填 'http://host:port'。
    'http_proxy'  => '',
);
