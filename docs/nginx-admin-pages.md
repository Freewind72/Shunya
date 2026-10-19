# 生产 nginx 配方 + Apache 部署兼容：admin/pages/ 归纳映射与敏感路径拦截（2026-10-18）

> 本文档是**生产环境应用配方**——仓库代码（16 个页面文件已归纳至 `admin/pages/`）部署到生产后，
> 生产 nginx 需按下文补一条内部重写规则。**规则与代码文件必须同批次上线**，缺一侧全站后台 404。
> 本机（FlyEnv）站点已应用并通过 A1-A9 / B1-B6 全量断言；本文档即该配置的生产复刻说明。

## 1. 变更内容

在**站点 server 块**内、**PHP 转发 location 之前**（`include enable-php-*.conf` 之前；若站点
conf 有 `#PHP-INFO` 面板段，插在该段之外）插入以下 7 行（4 行注释 + location 块）：

```nginx
    # ── admin 页面归纳（2026-10-18）：URL 保持 /admin/<action>.php，物理文件 admin/pages/<action>.php ──
    # 白名单 = admin/includes/routes.php $pageMap 的 16 键（两侧须同步改，AGENTS.md「路径路由」节）。
    # 必须位于 include enable-php-85.conf 之前（否则被 php location 先行截走，映射永不生效）。
    # rewrite … last = 内部重写：单请求完成、无外部 301/302、POST 体与查询串保留；
    # REQUEST_URI 传原始值，尾段/大小写变体照旧进 PHP 归一链（front.php 既有机制）。
    location ~* ^/admin/(dashboard|keys|users|profile|config|domains|rooms|room-console|settings|settings-site|settings-mail|settings-security|settings-api|settings-storage|playlist-detail|login)\.php(/|$) {
        rewrite (?i)^/admin/([a-z0-9-]+)\.php(.*)$ /admin/pages/$1.php$2 last;
    }
```

规则语义要点：

| 成分 | 语义 |
|---|---|
| `~*` | location 大小写不敏感——`/admin/KEYS.php` 也进入映射，交给 PHP 归一链处理 |
| `(?i)` | rewrite 捕获保留原大小写；Linux 生产下 `KEYS` 在 `admin/pages/` 无文件可命中 → 404，与改造前行为一致 |
| `(/|$)` | 尾段形态（`/admin/keys.php/`、`/admin/keys.php/foo`）一并重写，`$2` 保留尾段进 PHP 归一链 |
| `last` | 终止 rewrite 并重搜 location；重写后 `pages` 不在白名单 → 落 php location（结构上不可能循环） |
| 查询串 | replacement 不含 `?`，nginx 自动附加原 args（`?id=` / `?code=` 透传） |

## 2. 前置检查

1. **定位站点 conf**：`nginx -T | grep -n "server_name\|root"` 找到承载该站点的 conf 文件与 server 块。
2. **确认 PHP 转发 location 形态**：应存在 `location ~ [^/]\.php(/|$)` 之类 + `fastcgi_pass` 的 location（`include enable-php-*.conf` 或内联）；映射 location 必须排在它**之前**。
3. **确认 `root`**：指向站点根（仓库根目录，`admin/pages/` 相对它存在）。
4. **确认 16 action 与 `$pageMap` 一致**：比对 conf 白名单正则里的 action 名与 `admin/includes/routes.php` 的 `$pageMap` 键——两边应恰为同 16 个（可复用断言脚本的双源提取口径：conf 正则解析 ↔ routes.php include 提取，双向 diff 为空）。

## 3. 应用步骤

```bash
# 1) 备份（沿用 .bak-<时间戳> 命名惯例）
cp <站点conf> <站点conf>.bak-$(date +%Y%m%d-%H%M%S)

# 2) 插入第 1 节的 7 行规则（PHP 转发 location 之前）

# 3) 语法校验
nginx -t

# 4) 平滑重载
nginx -s reload
```

⚠️ **规则与代码文件同批次上线**：先部署代码（16 个页面文件已在 `admin/pages/`、4 个载入链文件已改），
再插入规则并 reload。**只做一侧的窗口内后台页面 404 属预期**，低峰执行、窗口尽量短。
（顺序无强制——先 reload 规则再传代码亦可，两侧都就位即恢复；关键是同一批次内完成。）

## 4. 验证步骤

```bash
B="https://你的域名"
# 1) 16 页面逐一首响应（已登录态按需带 cookie；未登录预期 302 → login）
for p in dashboard keys users profile config domains rooms settings \
         settings-site settings-mail settings-security settings-api \
         settings-storage playlist-detail login; do
  curl -s -o /dev/null -w "$p=%{http_code}\n" "$B/admin/$p.php"
done
curl -s -o /dev/null -w "room-console=%{http_code}\n" "$B/admin/room-console.php?code=TEST"

# 2) POST 冒烟（任一表单 action，带有效会话 + CSRF；预期正常分发非 404）

# 3) 负面五项（映射不得误伤真实路径，均应与改造前行为一致）
curl -s -o /dev/null -w "%{http_code}\n" "$B/admin/api/?action=get-avatar"
curl -s -o /dev/null -w "%{http_code}\n" "$B/admin/assets/pc/css/base.css"
curl -s -o /dev/null -w "%{http_code}\n" "$B/admin/index.php"
curl -s -o /dev/null -w "%{http_code}\n" "$B/api.php?key=<嵌入密钥>"
curl -s -o /dev/null -w "%{http_code}\n" "$B/install/"

# 4) 尾段与兼容归一
curl -s -o /dev/null -w "old=%{http_code} %{redirect_url}\n" "$B/admin/?action=keys"      # 301 → /admin/keys.php
curl -s -o /dev/null -w "tail=%{http_code} %{redirect_url}\n" "$B/admin/keys.php/"        # 301 → /admin/keys.php（恰 1 跳）
```

⚠️ **Linux 大小写敏感**：`/admin/KEYS.php` 在 Linux 生产预期 **404**（改造前顶层 `admin/KEYS.php`
同样不存在——本机 Windows 的 301 归一是文件系统不敏感命中所致），**勿误判为回归**。

## 5. 回退步骤

⚠️ **双向警示：文件位置与映射规则必须同进退**——

- 只删规则（文件在 `admin/pages/`）：`/admin/<action>.php` 落 php location 找不到文件 → 全站后台 404；
- 只挪文件（规则还在）：重写到 `admin/pages/` 反而 404 → 同样全站后台 404。

正确回退**四步一体**：

```bash
# 1) 16 个页面文件恢复到 admin/ 顶层（从 git 历史或发布包的旧版本取）
# 2) 删除映射 location（或整份恢复 .bak-<时间戳> 备份——同时带回 4 个载入链文件的旧版）
# 3) nginx -t
# 4) nginx -s reload
```

回退窗口内页面 404 属预期；`git checkout` 旧版本代码 + conf 备份恢复是更完整的两级回滚（见
`.codeartsdoer/specs/admin_pages_restore/baseline/` 的快照范围，含 nginx conf 备份）。

## 6. 联动纪律

新增/删除后台页面 = **四件套同步**（同一次提交处理，缺一即断；权威表述见 AGENTS.md「路径路由」节）：

1. 页面文件 `admin/pages/<action>.php`（复制任一现有文件改引导块 `INDEX_ACTION`）；
2. `admin/includes/routes.php` 的 `$pageMap` 登记；
3. nginx 站点 conf 的白名单正则同步登记（**生产侧照本配方第 3 节流程应用**）；
4. 根 `.htaccess` 的白名单正则同步登记（Apache 侧译文，见第 7 节）。

A9 断言（conf 白名单 ↔ `$pageMap` 16 键双向 diff 为空）可随时重验双侧一致性；引导块注释纪律
（不含 action 名、不写生成器字样）见 AGENTS.md「路径路由」节。

## 7. Apache 部署（托管主机兼容）

项目根 `.htaccess` 是上述 nginx 映射的 **mod_rewrite 等价译文**（`[NC,L]` 内部重写，白名单同源
`$pageMap` 16 键），附带防泄漏与关目录列表基线。**nginx 与 php -S 均不读取该文件**——本节仅
适用于 Apache 托管主机（共享虚拟主机等）。

### 7.1 前置检查

- [ ] `apachectl -M | grep rewrite`（或 `phpinfo()` 的 Loaded Modules）确认 **mod_rewrite** 已加载
- [ ] 站点对项目根放开 **AllowOverride All**——`.htaccess` 混用三类指令，只放开 FileInfo 时防泄漏块与 Options 行会 500：

| 指令 | AllowOverride 类别 |
|---|---|
| `RewriteEngine` / `RewriteRule` | FileInfo |
| `Require all denied`（2.4）/ `Order`+`Deny from all`（2.2 兼容分支） | AuthConfig / Limit |
| `Options -Indexes` | Options |

### 7.2 部署

- [ ] `.htaccess` 随仓库发布到位即可，无额外步骤（docroot 须指向项目根；子目录部署如 `https://host/msapi/` 时按 `.htaccess` 头注提示加 RewriteBase）

### 7.3 验证（语义对照逐项勾选；全部不得出现 500）

```bash
A="https://你的域名"
# 白名单映射 + 内部重写（#1/#5/#8：无中间 301/302 跳转；未登录预期 302 → login，已登录 200）
for p in dashboard keys users profile config domains rooms settings \
         settings-site settings-mail settings-security settings-api \
         settings-storage playlist-detail login; do
  curl -s -o /dev/null -w "$p=%{http_code} %{redirect_url}\n" "$A/admin/$p.php"
done
# 查询串透传（#7：?code= 到达 $_GET）
curl -s -o /dev/null -w "room-console=%{http_code}\n" "$A/admin/room-console.php?code=TEST"
# 大小写变体进归一链（#2/#3：Windows 主机 301 归一；Linux 主机 404——大小写敏感文件系统下物理文件不存在，均属预期，勿误判）
curl -s -o /dev/null -w "KEYS=%{http_code} %{redirect_url}\n" "$A/admin/KEYS.php"
# 尾段恰 1 跳（#4/#9：GET 带尾段 301 归一；POST 带尾段不跳转照常分发）
curl -s -o /dev/null -w "tail=%{http_code} %{redirect_url}\n" "$A/admin/keys.php/"
curl -s -o /dev/null -w "tail-post=%{http_code}\n" -X POST "$A/admin/keys.php/"
# 防循环（#6：pages 直访由 PHP 侧 301 归一回 /admin/<action>.php，与映射层无关）
curl -s -o /dev/null -w "direct=%{http_code} %{redirect_url}\n" "$A/admin/pages/keys.php"
# 负面路径照旧（#10：不重写、行为与无 .htaccess 时一致）
curl -s -o /dev/null -w "api=%{http_code}\n"      "$A/admin/api/?action=get-avatar"
curl -s -o /dev/null -w "assets=%{http_code}\n"   "$A/admin/assets/pc/css/base.css"
curl -s -o /dev/null -w "index=%{http_code}\n"    "$A/admin/index.php"
curl -s -o /dev/null -w "install=%{http_code}\n"  "$A/install/"
curl -s -o /dev/null -w "embed=%{http_code}\n"    "$A/api.php?key=<嵌入密钥>"
# 防泄漏（#11：点开头路径一律 403；.well-known 除外）
curl -s -o /dev/null -w "git=%{http_code}\n"      "$A/.git/config"
curl -s -o /dev/null -w "cda=%{http_code}\n"      "$A/.codeartsdoer/specs/htaccess_deploy/spec.md"
curl -s -o /dev/null -w "ht=%{http_code}\n"       "$A/.htaccess"
# 目录列表关闭：无 index 文件的目录 403 而非文件清单
curl -s -o /dev/null -w "libdir=%{http_code}\n"   "$A/assets/lib/"
# POST 冒烟：任一表单 action 带有效会话 + CSRF，预期正常分发非 404/500
```

- [ ] 以上逐项通过（含 16 页面循环全部 302/200、无一项 500）；任一 500 → 按 7.4 排障

### 7.4 块级排障法（500 定位）

`.htaccess` 三块零耦合，**逐块注释定位**（注释掉整块再刷新页面，定位到出错块后可按需摘除该块保留其余）：

1. **rewrite 块**（`<IfModule mod_rewrite.c>…</IfModule>`）——映射 + 路径级 deny
2. **FilesMatch 块**（`<FilesMatch "^\.">…</FilesMatch>`）——文件级 deny 兜底
3. **Options 行**（`Options -Indexes`）——关目录列表

常见成因：AllowOverride 缺对应类别（对照 7.1 矩阵放开）；「仅 FileInfo」的环境可摘除第 2/3 块保留纯映射。

### 7.5 回退

- [ ] 删除项目根 `.htaccess` 即**完全回退**（单动作原子；Apache 环境外无任何影响——nginx / php -S 本来就不读取它）
## 8. 敏感路径前置拦截（点开头路径，2026-10-18）

在**站点 server 块**内、**与第 1 节映射块同区**（`#PHP-INFO` 面板段之外、PHP 转发之前）插入：

```nginx
    location ~ ^/\.(?!well-known) {
        return 404;
    }
```

- **语义与根 `.htaccess` 的路径级 deny 对齐**（`^\.(?!well-known)` → 403）：所有点开头路径一律拒绝，`.well-known` 除外（ACME 证书验证）。两侧状态码不同（nginx 404 / Apache 403），语义等价均为拒绝。
- **必须位于 PHP 转发之前**（同第 1 节的顺序约束）：正则 location 先到先得，在后则 `.codeartsdoer/**` 下的 `.php`（归档区实验脚本）会被 php location 截走**转发执行**——本块把静态可读与 php 可执行一并堵住。
- **通配语义，无枚举同步负担**：`^/\.(?!well-known)` 覆盖面板枚举敏感块（`.user.ini` / `.git` / …）的全部点开头条目，也覆盖 `.arts` / `.codeartsdoer` 及未来新增的点开头条目——**不参与**第 6 节的联动纪律（那是 admin 页面白名单的五方一致性）。
- 面板生成的枚举敏感块保留不动（两块冗余无害；面板改动自身区域不会带走手工块——`/ws/` 与第 1 节映射块即先例）。

### 验证要点

```bash
# 敏感路径全 404（含归档区 php：404 = 未被转发执行）
curl -s -o /dev/null -w '%{http_code}\n' http://<host>/.codeartsdoer/
curl -s -o /dev/null -w '%{http_code}\n' http://<host>/.arts/settings.json
curl -s -o /dev/null -w '%{http_code}\n' http://<host>/.codeartsdoer/archive/work/<任一归档脚本>.php
# .well-known 放行：放一个真实文件，期望 200（负向断言写错会打断 ACME 续期，此项必测）
# 正常路径回归：/ 200、/admin/login.php 200、未登录 /admin/keys.php 302、api.php 200
```

### 回退

- [ ] 删除该 location 块 → `nginx -t` → `nginx -s reload`（或整体恢复 `.bak-<时间戳>` 备份）
## 10. 伪静态·前端控制器回退（location / 兜底，2026-10-18）

> WordPress 形态伪静态的**本站化**：`try_files` 三段回退 + `wp-admin` 补斜杠规则（注释样板，不生效）。
> 生产上线前**必读** 10.4 的软 404 警示——与第 1/8 节的映射/拦截规则不同，本节规则会**改变不存在路径的响应语义**（404 → 200 落地页）。

### 10.1 变更内容

在**站点 server 块**内、**敏感拦截块（第 8 节）之后、`#PHP-INFO` 面板段之前**插入（手工区，与既有手工块连片）：

```nginx
    # ── 伪静态·前端控制器回退（2026-10-18，WordPress 形态本站化）：仅兜底未被正则块命中的请求 ──
    # try_files 三段：存在文件直出 → 存在目录走 index（静默带斜杠，无 301）→ 回退 /index.php（软 404，$args 透传）。
    # 既有正则块（admin 映射/敏感拦截/php 转发/expires/枚举）与 ^~ /ws/ 全部优先于本块，行为零变化；
    # 不存在的 .php 仍 404（php location 先截走，其 try_files 为注释态、由 fastcgi 判定）。
    # 放置约束：手工区（面板段之外，与上方两块连片）；面板若生成同款 location / 会 duplicate 报错，见 docs/nginx-admin-pages.md 第 10 节。
    # wp-admin 补斜杠为 WordPress 专属（本站无 /wp-admin），以注释样板保留，勿取消注释——生效会给 /wp-admin 平添 301 跳转：
    # rewrite /wp-admin$ $scheme://$host$uri/ permanent;
    location / {
        try_files $uri $uri/ /index.php?$args;
    }
```

语义要点：

| 成分 | 语义 |
|---|---|
| `$uri` | 存在文件 → nginx 静态直出（零重定向、零 PHP 参与） |
| `$uri/` | 存在目录 → **静默**带斜杠 + `index` 指令 → index.php（比改前默认行为**少一跳 301**，最终落点不变） |
| `/index.php?$args` | 兜底：一切未命中路径**内部重定向**到落地页（外部无感知、方法保留、查询串透传）——即**软 404** |
| 匹配顺序 | 普通前缀块天然让位给全部正则块与 `^~`——admin 映射 / 敏感拦截 / php 转发 / expires / `/ws/` 行为零变化 |
| wp-admin 行 | 注释样板（本站无 `/wp-admin`；未来部署 WordPress 时迁到对应 vhost 取消注释即用） |

### 10.2 前置检查

1. **确认无既有 `location /`**：`nginx -T | grep "location /"`——若面板伪静态已生成同款块，插入即 duplicate 报错（处置见 10.5）。
2. **确认 php location 的 try_files 形态**：`enable-php-*.conf` 内 `try_files` 若是**生效态**（`try_files $uri = 404;` 未被注释），不存在的 `.php` 行为与本节预期一致（仍 404）；若缺失则须实测确认。
3. **确认 `root`** 指向站点根、`index` 指令含 `index.php`（`$uri/` 段依赖它落到 PHP）。

### 10.3 应用步骤（沿用第 3 节流程）

```bash
# 1) 备份
cp <站点conf> <站点conf>.bak-$(date +%Y%m%d-%H%M%S)
# 2) 在敏感拦截块之后、#PHP-INFO 之前插入 10.1 的块（逐字）
# 3) 语法校验（失败绝不 reload，服务不受影响）
nginx -t
# 4) 平滑重载
nginx -s reload
```

### 10.4 验证要点 + 生产上线警示

```bash
# 软 404 生效（200 + 落地页 HTML；?foo=1 同为 200 = $args 透传）
curl -s -o /dev/null -w '%{http_code}\n' http://<host>/not-exist-page-zz
curl -s -o /dev/null -w '%{http_code}\n' 'http://<host>/not-exist-page-zz?foo=1'
# 非特控扩展同样软 404（.html/.svg/.json/.ico/.woff——不在 expires 块特控列表内）
curl -s -o /dev/null -w '%{http_code}\n' http://<host>/not-exist-zz.html
# 高价值排障路径必须仍 404：不存在的 .php / .js / .css / 图片、点开头路径、LICENSE / README.md
curl -s -o /dev/null -w '%{http_code}\n' http://<host>/nonexist.php   # 404（php location 截走）
curl -s -o /dev/null -w '%{http_code}\n' http://<host>/not-exist-zz.js # 404（expires 块截走）
curl -s -o /dev/null -w '%{http_code}\n' http://<host>/.git/config     # 404（敏感拦截，第 8 节）
# 既有回归：/ 200、/api.php 200、后台 16 页面 302→login（未登录）、/admin/keys.php/ 301 恰 1 跳
# POST 到不存在路径 = 200 落地页（方法保留，软 404 固有代价；排障口径见下）
```

**⚠️ 生产上线警示（msapi.bmwy72.top）**：

- **软 404 与 SEO**：爬虫会把一切不存在路径视为有效页面（200）——生产是真实流量环境，与本机开发不同，**上线前应单独拍板接受度**。
- **排障口径变化**：原来「404 = 路径不存在」变为「200 + 非常规 URI + 响应体是落地页」——access_log 中 404 条目大幅减少，属预期。
- **`/admin` 与 `/install` 少一跳 301**（`$uri/` 段静默带斜杠，最终落点不变）——书签与缓存不受影响。
- **生产侧 `/admin/KEYS.php` 为 404**（Linux 大小写敏感文件系统，非本机 Windows 的 301 归一）——A14 类断言按 404 口径。
- 低峰执行、断言先行（先跑 10.4 全清单，任何既有行为回归 → 立即按 10.6 回退）。

### 10.5 附：duplicate location 处置预案

面板将来在本站应用 WordPress 伪静态模板（恰含同款 `location /`）时，`nginx -t` 报 `"location" directive is duplicate` → reload 失败、**运行中旧配置继续服务**（不会中断）。报错后二选一：

1. **清空面板伪静态**（推荐）——面板侧删掉生成的 `location /`，手工块继续生效（存活契约不变；面板区原有的 admin 无后缀块若被一并带走，按其自身配方重贴）；
2. 删本节手工块 → 改用面板管理的 `location /`——但面板区契约是「覆盖时全丢」，注释样板与 admin 无后缀块的重贴须一并处理。

### 10.6 回退

```bash
# 级别一（推荐，精准）：仅删除 10.1 的新增块 → nginx -t → nginx -s reload
# 级别二（整体）：cp <站点conf>.bak-<时间戳> <站点conf> → nginx -t → nginx -s reload
# 回退后验证：软 404 消失（/not-exist-page-zz → 404）、/admin 恢复 301 补斜杠
```