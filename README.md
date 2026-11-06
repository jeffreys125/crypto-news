# CryptoIndex — 虚拟货币资讯采集站

一个基于 **PHP + MySQL** 的轻量资讯聚合/采集站：定时抓取公开 RSS/Atom 源，自动去重、分类、打标签，前台以卡片列表展示，后台可管理来源、文章、设置与采集日志。

项目针对 **MySQL 5.5** 与较老的 PHP 环境做了兼容处理，可直接部署在普通虚拟主机上，无 Composer、无框架、无前端 CDN 依赖。

> **定位与合规**：本站只收录公开 RSS 的**标题、摘要、原文链接与作者署名**，不抓取、不存储、不转载正文全文，详情页明确引导跳转原文。它不提供虚拟货币交易、兑换、定价、理财或投资建议服务，也不包含交易入口。部署与运营前请确认所收录来源允许被聚合（服务条款 / robots.txt），商用或公开服务建议先取得内容授权；同时请遵守所在国家或地区关于虚拟货币资讯的相关法律法规。

---

## 一、环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | >= 5.4（推荐 5.6 / 7.x；代码兼容 8.x，已实测 PHP 8.3） |
| MySQL | **5.5 及以上**（5.5 / 5.6 / 5.7 / 8.0 / MariaDB 均可，已实测 MariaDB 10.11） |
| 必需扩展 | `pdo_mysql`、`simplexml` |
| 建议扩展 | `curl`（没有时自动回退 `allow_url_fopen`）、`mbstring`（没有时用兼容函数兜底） |
| 目录权限 | `inc/` 可写（安装时生成 `config.php`）、根目录可写（`install.lock`、`data/`） |

**MySQL 5.5 兼容要点**（代码已全部处理，无需你修改）：

- 全部表使用 `utf8`（utf8mb3）字符集，并在入库前过滤 4 字节 UTF-8 字符（emoji），避免 `Incorrect string value`；
- 所有索引长度控制在 767 字节以内（`url_hash char(32)` 做唯一键，`varchar(500)` 类字段不建索引）；
- 不使用 JSON 字段、CTE、窗口函数、`DATETIME DEFAULT CURRENT_TIMESTAMP` 等 5.6+ 特性；
- 连接时执行 `SET NAMES utf8, sql_mode=''`，规避老版本/严格模式的插入报错；
- 分页 `LIMIT` 使用已强制转换的整数拼接，避免 PDO 参数绑定在旧驱动上的类型问题。

---

## 二、快速部署

1. 把整个目录上传到站点根目录（如 `public_html/` 或 `wwwroot/`），确保 `index.php` 位于 Web 可访问位置。
2. 在主机面板创建 MySQL 数据库与账号（MySQL 5.5 建议 collation 用 `utf8_general_ci`）。若账号有建库权限，安装向导会自动建库。
3. 浏览器访问 `http://你的域名/install.php`，按向导填写数据库信息、站点名称与管理员账号。
4. 安装成功后：**删除 `install.php`**（`install.lock` 已存在，程序也会拒绝重复安装）。
5. 登录 `admin/login.php` → 「立即采集」点一次，前台即可看到内容。
6. 配置定时采集（见下节），否则内容不会自动更新。

安装向导完成的工作：建 6 张表 → 写入默认设置与随机 Web 触发密钥 → 创建管理员 → 预置 12 个公开资讯源 → 生成 `inc/config.php` → 创建 `data/` 锁目录。

### 定时采集（三选一）

**A. crontab（推荐）**

```bash
*/15 * * * * /usr/bin/php /你的绝对路径/crypto-news/cron.php > /dev/null 2>&1
```

调试时可加 `--force` 忽略来源间隔：`php /path/cron.php --force`。程序自带**文件锁**，不会并发重复采集。

**B. Web 触发（无 crontab 权限时）**

用外部监控/计划任务定时访问（密钥在后台「站点设置」中，可重新生成）：

```
https://你的域名/cron.php?key=<Web 触发密钥>
```

**C. 主机面板的「计划任务 / 定时任务」**，命令填 A 中的 php 命令或 B 中的 URL。

---

## 三、采集机制

| 特性 | 说明 |
| --- | --- |
| 支持格式 | RSS 2.0、Atom 1.0、RDF；自动识别 `content:encoded`、`dc:creator`、`media:content`、`enclosure` 等命名空间 |
| 内容范围 | 标题、纯文本摘要（最多 400 字）、缩略图、作者、发布时间、原文链接 —— **不存全文** |
| 去重 | URL 去掉 `utm_*` / `fbclid` / fragment 等追踪参数后取 MD5，数据库唯一键去重 |
| 礼貌抓取 | 支持 `ETag` / `Last-Modified` 条件请求（304 直接跳过）、每个来源独立采集间隔、失败按 2/4/8 倍指数退避 |
| 分类与标签 | 来源可指定默认栏目；按关键词自动归类到 7 个栏目，并生成比特币/以太坊/DeFi/NFT/监管等标签 |
| 版权处理 | 保留来源名称、作者与原文链接，前台「阅读原文」外链带 `nofollow noopener`，详情页注明版权归原媒体 |
| 日志 | 每次采集记录抓取数、新增数、状态与错误信息，保留 30 天 |

后台「资讯来源」可随时增删源、启停、单独「测试采集」，或一键「补回预置来源」。

---

## 四、功能清单

**前台**：首页/分类列表、文章详情、关键词搜索、对外聚合 RSS（`feed.php`）、sitemap（`sitemap.php`）、顶部可选行情条、热门/分类/来源侧栏、响应式深色主题（涨红跌绿，符合国内习惯）。

**后台**：仪表盘（统计 + 环境自检 + crontab 指引）、资讯来源、文章管理（置顶/推荐/批量删除/清空）、立即采集、采集日志、站点设置（SEO、公告、免责声明、采集参数、行情、Web 密钥、修改密码）。

**安全**：全部 SQL 预处理、输出统一 HTML 转义、后台 POST 操作带 CSRF 令牌、登录失败锁定、来源地址做内网 SSRF 校验、XML 解析禁用外部实体、`inc/` 与 `data/` 通过 `.htaccess` 禁止直接访问。

---

## 五、常见问题

**Q：国内服务器访问不了境外 RSS，采集一直失败？**
A：三种处理方式：① 在 `inc/config.php` 中配置 `'http_proxy' => 'http://host:port'`，采集请求会走代理；② 在后台停用不可达的源，换成可访问的公开源；③ 部分站点有 Cloudflare 反爬（如 CryptoSlate），换源即可。失败状态会自动标记并退避，不影响其它来源。

**Q：行情条不显示 / 接口报错？**
A：行情是可选模块，CoinGecko、Binance 接口在部分网络不可达。后台「站点设置」里可切换数据源、填自定义接口、或关闭行情条。接口不可用时沿用旧缓存，完全无数据时前台自动隐藏，不影响资讯浏览。

**Q：页面空白 / 500？**
A：确认已安装且 `inc/config.php` 存在；检查 `pdo_mysql`、`simplexml` 扩展；临时把 `inc/config.php` 中 `'debug' => true` 打开查看具体报错；查看 PHP 错误日志。

**Q：中文乱码？**
A：确认数据库/表为 `utf8_general_ci`（安装向导会自动建），且 `inc/config.php` 中数据库账号正确；程序连接时已执行 `SET NAMES utf8`。

**Q：如何换数据库账号或迁移服务器？**
A：编辑 `inc/config.php`（格式参考 `inc/config.sample.php`），或删除 `inc/config.php` 与 `install.lock` 后重新访问 `install.php`（会重建表，但 `INSERT ... ON DUPLICATE KEY` 不会破坏已有设置，文章表不会被清空）。

**Q：Nginx 怎么配置？**
A：站点默认使用 `index.php?...` 形式，**无需伪静态**即可运行。只需保护敏感目录：

```nginx
location ~ ^/(inc|data)/ { deny all; }
location ~ ^/(install\.php|cron\.php)$ { deny all; }   # 若不用 Web 触发，建议禁止
location ~ /\.(ht|git) { deny all; }
```

Apache 用户可用随附的 `.htaccess`（含可选伪静态规则，不开也能用）。

---

## 六、自检与目录结构

部署前可在服务器执行自检，验证扩展、解析器与网络连通性：

```bash
php tests/selftest.php           # 离线：解析器、URL 规范化、字符集、密码哈希
php tests/selftest.php --live    # 联网：实际抓取 12 个预置源并解析
```

```
crypto-news/
├── index.php / article.php / search.php / feed.php / sitemap.php   # 前台
├── admin/          # 后台（login / index / sources / articles / collect / logs / settings）
├── inc/            # bootstrap、db、functions、template、collector、market、config.sample
├── assets/         # style.css / admin.css / app.js（无外部依赖）
├── sql/schema.sql  # MySQL 5.5 兼容建表脚本
├── tests/selftest.php
├── install.php     # 安装向导（装完请删除）
├── cron.php        # 定时采集入口
├── .htaccess / robots.txt
└── README.md
```

---

## 七、安全与运维建议

1. 安装完成后删除 `install.php`；不要将 `inc/config.php` 提交到公开仓库。
2. 后台使用强密码，必要时通过服务器层限制 `/admin/` 访问 IP。
3. 若不需要 Web 触发，在 Nginx/Apache 中直接禁止访问 `cron.php`，仅走 crontab。
4. 定期备份数据库（重点是 `sources`、`articles`、`settings` 三张表）。
5. 采集频率建议不低于 15 分钟/源，尊重来源站点的服务条款与 `robots.txt`；如收到来源方移除请求，请及时停用对应来源或删除相关内容。
