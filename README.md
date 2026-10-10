<div align="center">

# 顺雅 Shunya

**你的网站，自带旋律。**

基于 Shadow DOM 隔离的轻量级嵌入式音乐播放器 —— 一行 `<script>` 接入，与宿主页面零 CSS 冲突

![PHP](https://img.shields.io/badge/PHP-8.5%2B-777BB4?style=flat-square&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?style=flat-square&logo=mysql&logoColor=white)
![SQLite](https://img.shields.io/badge/SQLite-3-003B57?style=flat-square&logo=sqlite&logoColor=white)
![APlayer](https://img.shields.io/badge/APlayer-1.10.1-946CE6?style=flat-square)
![Shadow DOM](https://img.shields.io/badge/Shadow_DOM-closed-000000?style=flat-square)

[特性](#特性) · [界面预览](#界面预览) · [快速开始](#快速开始) · [使用方式](#使用方式) · [项目结构](#项目结构) · [API 端点](#api-端点)

</div>

---

![顺雅播放器](docs/images/player.jpg)

## 特性

- **Shadow DOM 隔离** — 全部 UI 与样式封装在 `attachShadow({ mode: 'closed' })` 中，宿主页面的 CSS 无法穿透，零冲突嵌入任意网站。
- **一行嵌入** — 只需一行 `<script>`，播放器以悬浮音符按钮出现在页面右下角。
- **双音源** — 同时支持 QQ 音乐与网易云音乐歌单，一键切换。
- **歌词同步** — 实时解析 LRC 时间轴并逐行高亮，自动识别纯音乐。
- **沉浸模式** — 全屏播放，大尺寸封面、滚动歌词与完整控制栏。
- **暗色主题** — 按北京时间（18:00–06:00）自动切换深浅色，也可手动固定。
- **自由拖拽** — 悬浮按钮可拖至屏幕任意位置，松手自动吸附屏幕边缘；**严格限制在视口内**，不会跑到屏幕外。
- **智能停靠** — 按钮上方剩余空间**不足以显示面板 1/3** 时，面板改为**向下浮出**（左上 / 右上角各自对齐）；
  够 1/3 就维持向上浮出，放不下时把按钮往下挤，且面板顶部不会越过屏幕。
  浮出方向只在拖拽松手时锁定一次，面板开合造成的按钮挪动不会反过来改变方向；
  **挤压后的位置就是新位置，收起面板不回正**。窗口尺寸变化后按当前停靠侧重排，不会跑到另一侧。移动端固定向上浮出。
- **音量增强** — 基于 Web Audio API 的三档增益：1× / 2× / 3×。
- **播放模式** — 列表循环、单曲循环、随机播放。
- **Cookie 持久化** — 跨会话记忆音量、歌单、播放进度与主题偏好。
- **隐私合规** — 内置 Cookie 授权弹窗，用户明确同意后才写入数据。
- **管理后台** — 在 `/admin/` 中管理 API 密钥、歌单、公告与调用统计。

---

## 界面预览

### 落地页

站点首页「顺雅 · 声波宇宙」，深空暗色主题，实时展示接口调用统计。

![落地页](docs/images/hero.jpg)

### 播放器面板

悬浮按钮展开后的控制面板：封面、进度、播放模式、歌词开关、音量增强与歌单列表。

![播放器面板](docs/images/player.jpg)

### 沉浸模式

全屏大封面配合滚动歌词，宛如原生播放器。

![沉浸模式](docs/images/immersive.jpg)

### 移动端

面板在移动端自动收窄，触控拖拽与边缘吸附保持一致。

<p align="center">
  <img src="docs/images/mobile.jpg" width="330" alt="移动端" />
</p>

### 管理后台

`/admin/` 后台登录页，支持账号密码与 WebAuthn 通行密钥两种登录方式。

![管理后台](docs/images/admin-login.jpg)

---

## 快速开始

### 环境要求

| 组件       | 最低版本                        |
|------------|---------------------------------|
| PHP        | 8.5+                            |
| 数据库     | MySQL 5.7+ 或 SQLite 3          |
| Web 服务器 | Apache / Nginx / PHP 内置服务器 |

### 安装步骤

1. 将项目**克隆或解压**到 Web 服务器目录（如 `/var/www/msapi` 或 `C:\xampp\htdocs\msapi`）。
2. 将 Web 服务器的站点根**指向**项目目录。
3. 浏览器访问 `/install/`，安装向导会引导完成：
   - 数据库配置（MySQL 或 SQLite）
   - 管理员账户创建
   - 初始 API 密钥设置
4. 安装完成后，建议**删除或限制** `/install/` 目录的访问权限。

> 如果 `config/config.php` 已存在且配置正确，安装程序会自动跳过。

### 生产环境必需的 PHP 设置

安装完成后，请在 php.ini / php-fpm 池配置中确认以下两项。**开发机上开着它们很方便，直接搬到线上则是信息泄漏与性能问题。**

| 设置 | 生产值 | 为什么 |
|---|---|---|
| `display_errors` | `0`（并配 `log_errors = 1`） | 报错会把绝对路径、SQL 片段、连接信息直接回给访客；只允许写日志 |
| `xdebug.mode` | `off` | Xdebug 会显著拖慢执行，其错误输出还会暴露本地路径与源码行 |

```ini
; php.ini（生产）
display_errors = 0
log_errors     = 1
xdebug.mode    = off
```

> `install/` 目录下的安装向导是**唯一**按需开启报错显示的地方：未安装时便于排错，
> 安装完成后自动关闭（见 `install/includes/guard.php`）。这不替代上面的全局设置。

### PHP 内置服务器（快速测试）

```bash
php -S 127.0.0.1:8080 -t /path/to/Msapi
```

然后打开 `http://127.0.0.1:8080/install/` 开始配置。

---

## 使用方式

### 嵌入播放器

在任意页面的 `</body>` 之前添加一行 `<script>`：

```html
<script src="https://your-domain.com/api.php?key=YOUR_API_KEY" defer></script>
```

播放器随即出现，所有访客无需注册即可收听。

- **密钥走 URL 查询参数**，不再放在自定义属性上 —— 有些平台的编辑器或 HTML 净化器会剥掉
  非标准属性（`key="..."`），剥不掉查询参数。
- 服务端按密钥解析到归属用户，用**该用户后台配置的皮肤**渲染。
- 想临时换一套皮肤，在同一个 URL 上加 `&route=<皮肤名>` 即可覆盖。

> **旧写法已停用**：`<script src=".../modules/api.php?route=router" key="...">`。
> 原因：密钥放在标签属性上时**服务端读不到**，只有 URL 查询参数才可靠。
> 老代码请改成上面那一行 —— 旧入口现在只会在控制台打印一句升级提示，不再提供播放器。

#### 同页放多个播放器（必须显式区分）

播放器的「重复执行保护」按**配置**去重，而不是按标签位置：同一个 `key` + 同一个 `api`
（未指定 `data-player-id` 时）会派生出**同一个去重键**，于是同页第二个完全相同的
`<script>` 会被判定为「这个播放器已经在跑了」而**静默跳过** —— 结果就是「第二个播放器不出现」。

要让同页两个播放器共存，**必须给它们不同的 `data-player-id`**：

```html
<script src="https://your-domain.com/api.php?key=YOUR_API_KEY" data-player-id="bgm-a" defer></script>
<script src="https://your-domain.com/api.php?key=YOUR_API_KEY" data-player-id="bgm-b" defer></script>
```

`data-player-id` 同时决定各自的 Cookie 命名空间，两个播放器的音量/进度/歌单记忆互不干扰。

> 被去重跳过时，控制台会打印一条 `[Msapi]` 开头的 `console.info` 说明原因 ——
> 这个行为本身是必要的（Swup / Pjax / Turbo 这类无刷新框架会重执行脚本，不能重建播放器），
> 所以才用显式 `data-player-id` 来表达「我确实要两个」。

### 皮肤

皮肤在后台「音乐配置 → 播放器皮肤」里选择，对该账号下所有密钥生效；
URL 上的 `?route=` 可临时覆盖。

| route | 形态 | 特点 |
|---|---|---|
| `router` | 悬浮圆钮 + 展开式玻璃面板 | 可拖拽、边缘吸附、顶部停靠、沉浸模式 |
| `rose` | 玫瑰玻璃**侧边常驻卡片** | **不可拖动**，只贴左右边；贴边橙色箭头收起/展开；自带歌单列表 |

```html
<!-- 这一处嵌入强制用侧边卡片，不受后台设置影响 -->
<script src="https://your-domain.com/api.php?key=YOUR_API_KEY&route=rose" defer></script>
```

皮肤只负责 DOM 与样式；鉴权、播放内核、状态、歌词、歌单渲染由 `modules/core/` 统一提供。
**新增皮肤 = 在 `modules/<name>/` 下建目录 + 写 `skin.json` + 写 `widget.js`（DOM 与 `MP._css`）
与皮肤行为文件**，分发器与后台都会自动读到，不需要改任何代码。

> **请务必加上 `defer`。** 这是一个第三方域名的脚本，不加 `defer` 时浏览器会停下来等它下载并执行完
> 才继续解析页面，直接拖慢首屏；加上 `defer` 后它会在文档解析完成后执行，效果完全一致。
> 如果宿主平台支持把代码注入到页脚，放在 `</body>` 前同样可以。

### 对宿主页面的侵入性

播放器按"可能嵌在任何人博客上"的前提设计，不会改动宿主页面：

- **不锁滚动** — cookie 授权提示是一个停在左下角的小卡片，页面照常可读可滚，不铺全屏遮罩。
- **不改滚动条** — 不注入任何影响 `html` / `body` 滚动条的样式；播放器自身的滚动区域都在 shadow root 内。
- **可还原的临时样式** — 沉浸模式会临时锁滚动，退出时还原宿主原本的内联 `overflow` 值，而不是清空。

### 自定义参数

嵌入脚本支持在 `<script>` 标签上设置以下属性：

| 属性               | 说明                             | 默认值       |
|--------------------|----------------------------------|--------------|
| `key`              | API 密钥（必填）                 | —            |
| `token`            | 预验证的授权令牌（跳过密钥校验） | —            |
| `api`              | 自定义 API 端点 URL              | 自动检测     |
| `data-player-id`   | 多实例标识（同页跑两个播放器时用于区分各自的 Cookie 记忆） | 由 `key` 派生 |
| `cdn-aplayer-js`   | 自定义 APlayer 内核地址          | 本站自托管   |

> APlayer 内核（JS）已随项目自带（`assets/lib/aplayer/`），默认走本站，**不依赖任何第三方 CDN**；
> 只有需要换成别的版本或别的源时，才用上面的属性覆盖。

> **播放器界面完全自绘，不加载 APlayer 自带样式表。** APlayer 在这里只当音频内核用，
> 它的 DOM 被放进 closed shadow root 里一个 `display:none` 的容器，库自带的 `.aplayer*` 皮肤
> 既进不来也用不上。因此项目不分发、也不请求 `APlayer.min.css` ——
> 界面样式全部来自 `widget.js` 中内联进 shadow root 的 `MP._css`。

> **跨域说明**：`<script src>` 属于普通资源，跨域加载**不受 CORS 限制**（只要不加 `crossorigin` 属性，
> 本项目没有加）。真正需要 CORS 的是播放器的 XHR 请求（`verify-key` / `get-config` / `playlist`）
> 与音频流，接口侧已统一返回 `Access-Control-Allow-Origin: *`。

> **无刷新换页框架（Swup / Pjax / Turbo）下是安全的**：这类框架会重执行 `<head>` 里的脚本，
> 播放器据此做了幂等处理——识别到同一个播放器仍在运行时直接退出，不会重建、不会重复出声、播放进度不中断。
> 重复执行时只会创建**一个**实例；只有宿主已被移除或启动失败时才会重建。
> 若要在同一页面放两个播放器，请为它们分别指定不同的 `data-player-id`。

### 通过管理后台配置

登录 `/admin/` 后可配置以下项：

- **歌单** — 添加 QQ 音乐或网易云音乐歌单
- **主题** — 自动（按时间）或强制浅色 / 深色
- **自动播放** — 页面加载时是否自动播放
- **歌词** — 默认歌词显示开关
- **服务器** — 默认音乐源（腾讯 / 网易）
- **公告** — 向所有嵌入播放器推送广播消息

---

## 项目结构

```
Msapi/
├── index.php                  # 落地页（顺雅 · 声波宇宙）
├── favicon.ico
├── LICENSE                    # 许可全文
├── config/
│   └── config.php             # 数据库与站点配置（安装向导生成）
├── api.php                    # 播放器总入口：?key=密钥 → 解析皮肤 → 输出启动脚本
├── modules/                   # 播放器前端（内核 + 皮肤）
│   ├── api.php                # 旧入口转发壳（兼容存量嵌入，存量迁完可删）
│   ├── registry.php           # 皮肤清单装载：分发器与后台共用同一份清单
│   ├── core/                  # 引擎：全站唯一一份，皮肤不得复制
│   │   ├── auth.js            # 密钥校验与 Cookie 授权
│   │   ├── state.js           # 状态与 Cookie 持久化（含播放模式菜单）
│   │   ├── player.js          # APlayer 播放内核 + 生命周期
│   │   ├── ui.js              # 按 data-mp 契约渲染面板与歌单（与外观无关）
│   │   ├── lyrics.js          # LRC 解析与歌词同步
│   │   └── theme.js           # 主题、公告与问候
│   ├── router/                # 皮肤①：悬浮圆钮 + 玻璃面板（可拖拽停靠）
│   │   ├── widget.js          # DOM 结构 + shadow root 内联样式
│   │   ├── drag.js            # 拖拽、边缘吸附、顶部停靠
│   │   └── immersive.js       # 沉浸全屏模式
│   └── rose/                  # 皮肤②：玫瑰玻璃侧边卡片（常驻、不可拖动）
│       ├── widget.js          # DOM 结构 + shadow root 内联样式
│       └── skin.js            # 侧边定位、收起/展开、皮肤专属交互
├── assets/
│   ├── css/                   # 落地页样式（pc / mobile）
│   ├── js/                    # 落地页交互（pc / mobile）
│   └── lib/
│       ├── aplayer/               # APlayer 1.10.1 内核（自托管，免第三方 CDN）
│       ├── api_config.php     # API 配置读取
│       ├── db.php             # 数据库抽象层（MySQL / SQLite）
│       ├── helpers.php        # 通用工具函数
│       ├── jwt.php            # HS256 JWT 签发与校验
│       └── widget.php         # 第三方页面嵌入脚本
├── admin/                     # 管理后台（单入口）
│   ├── index.php              # 后台入口
│   ├── api/                   # api.php / qq_api.php / wy_api.php / relay.php
│   ├── assets/                # pc / mobile 两套资源与 CodeMirror
│   ├── handlers/              # 各功能处理端点
│   ├── includes/              # bootstrap / guard / routes / user_init
│   ├── layout/                # header / footer
│   ├── lib/                   # auth / mail / pusher / s3 / webauthn / cover_cache
│   └── pages/                 # 页面视图
├── install/                   # 安装向导
│   ├── index.php              # 向导入口
│   ├── init.php               # 安装逻辑
│   ├── upgrade.php            # 数据库迁移
│   ├── handlers/ includes/ lib/
│   └── sql/                   # mysql.sql / sqlite.sql
└── docs/images/               # README 截图
```

---

## API 端点

| 端点                                         | 方法 | 说明                                             |
|----------------------------------------------|------|--------------------------------------------------|
| `/admin/api/api.php?action=verify-key`       | POST | 校验 API 密钥，返回 Token                        |
| `/admin/api/api.php?action=get-config`       | GET  | 获取歌单与主题配置                               |
| `/admin/api/api.php?action=get-announcement` | GET  | 获取当前公告                                     |
| `/admin/api/api.php?action=playlist`         | GET  | 获取歌单歌曲（参数：`id`、`server`、`limit`）    |

---

## 技术栈

- **前端**：原生 JavaScript（ES5+）、Shadow DOM、APlayer 1.10.1
- **后端**：PHP、MySQL / SQLite
- **音乐 API**：QQ 音乐与网易云音乐代理转发
- **依赖**：嵌入播放器不依赖第三方 CDN——APlayer 1.10.1 只作音频内核，随包自托管（`assets/lib/aplayer/`），可用 `cdn-aplayer-js` 覆盖；界面样式自带，不加载 APlayer 的 CSS
- **后台资源**：管理端地址集中在 `admin/api/relay.php`（Bootstrap 与后台测试播放器用的 APlayer 走 CDN）

---

## 许可协议

[LICENSE](LICENSE)

---

<div align="center">

*献给每一张值得拥有背景音乐的网页。*

</div>
