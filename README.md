# CryptoIndex — 虚拟货币资讯采集站（v2 全文版）

一个基于 **PHP + MySQL** 的轻量资讯聚合/采集站：定时抓取公开 RSS/Atom 源，**抓取并展示文章全文**，自动去重、分类、打标签；前台为英文界面，后台提供来源、文章、设置与采集日志管理。

项目针对 **MySQL 5.5** 与较老的 PHP 环境做了兼容处理，可直接部署在普通虚拟主机上，无 Composer、无框架、无前端 CDN 依赖。

> **定位与合规（重要）**：v2 会存储并展示文章全文，优先使用 RSS 的 `content:encoded`，没有全文时访问原文页提取正文，因此属于**全文转载/镜像**。上线前务必确认所收录来源允许被聚合与全文转载（服务条款 / robots.txt / 版权声明），商用或公开服务**建议先取得内容授权**；如收到来源方移除请求，请立即停用对应来源或删除相关内容。若不需要全文，可在后台「站点设置 → 全文抓取」一键关闭，程序会退回只显示摘要。本站仍不提供虚拟货币交易、兑换、定价、理财或投资建议服务，也不包含交易入口；请遵守所在国家或地区关于虚拟货币资讯的相关法律法规。

---

## 一、环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | >= 5.4（推荐 5.6 / 7.x；代码兼容 8.x，已实测 PHP 8.3） |
| MySQL | **5.5 及以上**（5.5 / 5.6 / 5.7 / 8.0 / MariaDB 均可，已实测 MariaDB 10.11） |
| 必需扩展 | `pdo_mysql`、`simplexml` |
| 建议扩展 | `dom`（正文提取效果最佳，缺失时自动降级为正则）、`curl` + `curl_multi`（全文并行抓取；缺失时串行回退 `allow_url_fopen`）、`mbstring`（缺失时有兼容函数兜底） |
| 目录权限 | `inc/` 可写（安装时生成 `config.php`）、根目录可写（`install.lock`、`data/`） |
| 数据库权限 | v1 老站升级时，账号最好有 `ALTER` 权限以便自动补列（无权限时会自动降级为 v1 行为，见第六节） |

**MySQL 5.5 兼容要点**（代码已全部处理，无需你修改）：

- 全部表使用 `utf8`（utf8mb3）字符集，并在入库前过滤 4 字节 UTF-8 字符（emoji），避免 `Incorrect string value`；
- 所有索引长度控制在 767 字节以内（`url_hash char(32)` 做唯一键，`varchar(500)` 类字段不建索引）；
- 不使用 JSON 字段、CTE、窗口函数、`DATETIME DEFAULT CURRENT_TIMESTAMP` 等 5.6+ 特性；
- 连接时执行 `SET NAMES utf8, sql_mode=''`，规避老版本/严格模式的插入报错；
- 分页 `LIMIT` 使用已强制转换的整数拼接，避免 PDO 参数绑定在旧驱动上的类型问题；
- 正文使用 `MEDIUMTEXT` 存储，入库前限长 20 万字符。

---

## 二、快速部署

1. 把整个目录上传到站点根目录（如 `public_html/` 或 `wwwroot/`），确保 `index.php` 位于 Web 可访问位置。
2. 在主机面板创建 MySQL 数据库与账号（MySQL 5.5 建议 collation 用 `utf8_general_ci`）。若账号有建库权限，安装向导会自动建库。
3. 浏览器访问 `http://你的域名/install.php`，按向导填写数据库信息、站点名称（默认 `CryptoIndex`）与管理员账号。
4. 安装成功后：**删除 `install.php`**（`install.lock` 已存在，程序也会拒绝重复安装）。
5. 登录 `admin/login.php` → 「立即采集」点一次，前台即可看到内容。首次采集会额外抓取全文，耗时比 v1 长。
6. 配置定时采集（见下节），否则内容不会自动更新。

安装向导完成的工作：建 6 张表（含 `content` / `content_fetched` 字段）→ 写入默认设置（含 `db_schema=2`）与随机 Web 触发密钥 → 创建管理员 → 预置 12 个公开资讯源 → 生成 `inc/config.php` → 创建 `data/` 锁目录。

### 定时采集（三选一）

**A. crontab（推荐）**

```bash
*/30 * * * * /usr/bin/php /你的绝对路径/crypto-news/cron.php > /dev/null 2>&1
```

开启全文抓取后单次执行时间变长，建议间隔不低于 30 分钟。调试时可加 `--force` 忽略来源间隔：`php /path/cron.php --force`。程序自带**文件锁**，不会并发重复采集。

**B. Web 触发（无 crontab 权限时）**

用外部监控/计划任务定时访问（密钥在后台「站点设置」中，可重新生成）。注意全文抓取可能超过部分监控/虚拟主机的请求超时时间，优先用 crontab：

```
https://你的域名/cron.php?key=<Web 触发密钥>
```

**C. 主机面板的「计划任务 / 定时任务」**，命令填 A 中的 php 命令或 B 中的 URL。

---

## 三、采集机制

| 特性 | 说明 |
| --- | --- |
| 支持格式 | RSS 2.0、Atom 1.0、RDF；自动识别 `content:encoded`、`content`、`dc:creator`、`media:content`、`enclosure` 等命名空间 |
| 内容范围 | 标题、纯文本摘要（≤400 字）、**清洗后的文章全文 HTML**、缩略图、作者、发布时间、原文链接与来源署名 |
| 全文来源 | ① RSS 自带全文（`content:encoded` / Atom `content`）直接使用；② 否则访问原文页，用 DOM 从 `article` / `articleBody` / `post-content` 等常见正文容器中提取（评分：文本长度 + 段落数 + 图片数 − 链接密度）；无 DOM 扩展时降为正则提取 |
| 全文清洗 | 白名单标签（段落/标题/列表/引用/表格/图片/链接等），移除 `script`、`iframe`、`on*` 事件、`style/class/id`、`javascript:` / `data:` 协议；相对链接转绝对；外链加 `nofollow noopener`；图片加 `no-referrer` 与懒加载。**清洗后的 HTML 才入库**，杜绝存储型 XSS |
| 抓取限量 | 本轮**新入库**文章优先，额度有剩余时自动补抓历史「待补」文章（不会让超限文章永远缺全文）；每个来源每次最多 N 篇（后台「站点设置 → 全文抓取」配置，默认 30，可设 0 关闭）；`curl_multi` 每批 8 个并发、单请求 10 秒超时；失败时降级为摘要并标记，避免反复重试压垮来源 |
| 去重 | URL 去掉 `utm_*` / `fbclid` / fragment 等追踪参数后取 MD5，数据库唯一键去重 |
| 礼貌抓取 | 支持 `ETag` / `Last-Modified` 条件请求（304 直接跳过）、每个来源独立采集间隔、失败按 2/4/8 倍指数退避；采集地址与文章页地址均做内网 SSRF 校验 |
| 分类与标签 | 7 个英文栏目（General / Markets / Blockchain / Regulation / Technology / Security / Industry），自动生成 Bitcoin / Ethereum / DeFi / Regulation 等英文标签 |
| 版权处理 | 保留来源名称、作者与原文出处；详情页底部以中性文字标注 Source 与 Original URL（带 `nofollow`），不再放置「前往原文阅读」跳转按钮 |
| 日志 | 每次采集记录抓取数、新增数、**全文补齐数**、状态与错误信息，保留 30 天 |

后台「资讯来源」可随时增删源、启停、单独「测试采集」，或一键「补回预置来源」。

---

## 四、功能清单

**前台（英文界面）**：首页/分类列表、文章详情（**站内直接阅读全文**）、关键词搜索、对外聚合 RSS（`feed.php`，含 `<content:encoded>` 全文）、sitemap、顶部可选行情条、热门/分类/来源侧栏、响应式深色主题（涨红跌绿）。

**后台（中文界面）**：仪表盘（统计含全文覆盖率 + 环境自检 + crontab 指引）、资讯来源、文章管理（含全文状态列、置顶/推荐/批量删除/清空）、立即采集（含全文补齐统计）、采集日志、站点设置（SEO、公告、免责声明、**全文抓取开关与限量**、采集参数、行情、Web 密钥、修改密码）。

**安全**：全部 SQL 预处理、输出统一 HTML 转义（正文走白名单清洗）、后台 POST 操作带 CSRF 令牌、登录失败锁定、来源地址与文章页地址做内网 SSRF 校验、XML 解析禁用外部实体、`inc/` 与 `data/` 通过 `.htaccess` 禁止直接访问。

---

## 五、从 v1 升级到 v2（全文版）

**直接覆盖代码即可，无需手工执行 SQL，也不会丢失已有文章。** 首次访问任意页面时 `inc/migrate.php` 会自动：

1. 检测 `db_schema` 版本，补齐 `articles.content` (`MEDIUMTEXT`) 与 `articles.content_fetched` 字段；
2. 把 v1 的中文栏目、中文标签批量转成英文（后台下拉、前台导航、分类筛选随之统一）；
3. 仅当站点名/描述/关键词/免责声明仍是 v1 出厂值时，替换为英文默认文案（**不会覆盖你的自定义内容**）；
4. 写入 `db_schema=2`，之后不再触发迁移。

升级后执行一次「立即采集」，新文章会带全文；**v1 已入库的旧文章不会自动补全文**（可在后台清空后重采，或按需自行处理）。

如果你的数据库账号没有 `ALTER` 权限，自动迁移会静默失败：程序自动降级为 v1 行为（只存标题/摘要），站点仍可正常访问。此时请 DBA 手工执行 `sql/upgrade.sql`（把 `{{PREFIX}}` 替换为实际表前缀），或临时提升权限后刷新一次页面。

---

## 六、常见问题

**Q：国内服务器访问不了境外 RSS，采集一直失败？**
A：三种处理方式：① 在 `inc/config.php` 中配置 `'http_proxy' => 'http://host:port'`，采集与全文请求都会走代理；② 在后台停用不可达的源，换成可访问的公开源；③ 部分站点有 Cloudflare 反爬，换源即可。失败状态会自动标记并退避，不影响其它来源。

**Q：有些文章没有全文，只显示一段摘要？**
A：常见原因：该源 RSS 不带 `content:encoded`，且原文页被反爬拦截、为 JS 渲染、或正文容器不在识别列表里。程序会降级为摘要并标记 `content_fetched=2`，详情页显示提示。超过每源限量的文章会标记 `content_fetched=0`，后续采集会自动补抓。可：在后台把「每源每次补齐全文篇数」调大后重采、把该源换成提供全文的 feed（如 Bitcoin Magazine 的 `.rss/full/`）、或在 `inc/fulltext.php` 的 `cn_content_hints()` 增加该站的正文容器 class。

**Q：开启全文后采集变慢 / Web 触发超时？**
A：正常。程序用 `curl_multi` 分批并行、单请求 10 秒超时、每源限量，但仍会拉长单次采集。建议只用 crontab（间隔 ≥30 分钟）、把每源限量调到 10–20；不需要全文时可在「站点设置」直接关闭。

**Q：全文里有广告、导航或排版错乱？**
A：正文提取是启发式的。可以调 `inc/fulltext.php` 的 `cn_noise_tags()`（去除更多噪声标签）与 `cn_content_hints()`（增加该站正文容器关键词）；入库 HTML 已经过白名单清洗，不会执行脚本。

**Q：行情条不显示 / 接口报错？**
A：行情是可选模块，CoinGecko、Binance 接口在部分网络不可达。后台「站点设置」里可切换数据源、填自定义接口、或关闭行情条。接口不可用时沿用旧缓存，完全无数据时前台自动隐藏，不影响资讯浏览。

**Q：页面空白 / 500？**
A：确认已安装且 `inc/config.php` 存在；检查 `pdo_mysql`、`simplexml` 扩展；临时把 `inc/config.php` 中 `'debug' => true` 打开查看具体报错；查看 PHP 错误日志。

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

## 七、自检与目录结构

部署前可在服务器执行自检，验证扩展、解析器、清洗器与网络连通性：

```bash
php tests/selftest.php              # 离线：解析器、全文清洗/XSS、正文提取、字符集
php tests/selftest.php --live       # 联网：抓取 12 个预置源，并统计其中自带全文的条目数
php tests/selftest.php --fulltext   # 联网：抓取 2 个真实文章页并验证正文提取
```

```
crypto-news/
├── index.php / article.php / search.php / feed.php / sitemap.php   # 前台（英文界面）
├── admin/          # 后台（中文界面）
├── inc/            # bootstrap、db、functions、template、collector、fulltext、migrate、market、config.sample
├── assets/         # style.css / admin.css / app.js（无外部依赖）
├── sql/            # schema.sql（建表）、upgrade.sql（手动升级参考）
├── tests/selftest.php
├── install.php     # 安装向导（装完请删除）
├── cron.php        # 定时采集入口
├── .htaccess / robots.txt
└── README.md
```

---

## 八、安全与运维建议

1. 安装完成后删除 `install.php`；不要将 `inc/config.php` 提交到公开仓库。
2. 后台使用强密码，必要时通过服务器层限制 `/admin/` 访问 IP。
3. 若不需要 Web 触发，在 Nginx/Apache 中直接禁止访问 `cron.php`，仅走 crontab。
4. 定期备份数据库（重点是 `sources`、`articles`、`settings` 三张表）；正文体积较大，注意磁盘与备份空间。
5. 采集频率建议不低于 30 分钟/源，尊重来源站点的服务条款与 `robots.txt`；**全文转载的版权风险显著高于仅收录摘要**，如收到来源方移除请求，请及时停用对应来源或删除相关内容。
