<?php
// api.php — 播放器总入口（项目根目录）
//
//   GET /api.php?key=密钥[&route=皮肤]       → 启动脚本（key → 用户 → 皮肤）
//   GET /api.php?skin=皮肤&asset=文件名.js   → 模块文件（静态输出 + ETag，不查库）
//
// 兼容：旧写法（把密钥写在 <script key="..."> 属性上）已停用 —— 服务端读不到标签属性，
// 必须用 ?key= 查询参数。旧入口 modules/api.php 已删除。
header('Content-Type: application/javascript; charset=utf-8');
header('Access-Control-Allow-Origin: *');

/** 输出 JS：统一处理 ETag / Last-Modified / 304 */
function msapi_send_js(string $body, ?int $mtime = null): void {
    $etag = '"' . md5($body) . '"';
    header('Cache-Control: no-cache, must-revalidate, max-age=0');
    header('ETag: ' . $etag);
    if ($mtime !== null) {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    }

    $inm = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    $ims = (int)strtotime((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
    $unchanged = ($inm !== '' && ($inm === $etag || $inm === 'W/' . $etag))
        || ($inm === '' && $mtime !== null && $ims > 0 && $mtime <= $ims);

    if ($unchanged) {
        http_response_code(304);
        exit;
    }
    echo $body;
    exit;
}

/** 出错时输出一行 console.error（错误响应不做缓存） */
function msapi_send_error(string $msg, int $code = 404): void {
    http_response_code($code);
    header('Cache-Control: no-store');
    echo 'console.error("[Msapi] ' . addslashes($msg) . '");' . "\n";
    exit;
}

require_once __DIR__ . '/modules/registry.php';

/**
 * 密钥 → 皮肤：key → mapi_keys.user_id → mapi_users.player_skin
 *
 * 只在这里查库（api_key 上有索引，一次查询），且只在「没写 route」时才走到。
 * 模块文件请求走 ?skin=&asset=、不带 key，所以一次页面加载最多一次查询。
 * 任何异常都返回 null（fail-open），由调用方回落默认皮肤 —— 数据库抖动不该让嵌入站白屏。
 */
function msapi_skin_for_key(string $key): ?string {
    if ($key === '' || !preg_match('/^[A-Za-z0-9]{8,64}$/', $key)) return null;

    // 这里吐出的任何字节都会混进启动 JS 里 —— 一句 PHP 警告就足以让整段脚本语法报废、
    // 播放器彻底不启动。所以整个查库过程兜在输出缓冲里，并把 display_errors 关掉。
    $obLevel = ob_get_level();
    $prevDisplay = ini_get('display_errors');
    ob_start();
    ini_set('display_errors', '0');

    try {
        global $CFG;                      // db_connect() 里用的是 global $CFG，必须写在全局
        $cfgFile = __DIR__ . '/config/config.php';
        if (!is_file($cfgFile)) return null;
        $CFG = require $cfgFile;
        if (!is_array($CFG)) return null;
        require_once __DIR__ . '/assets/lib/db.php';
        $db = db_connect();
        if (!$db) return null;
        $stmt = $db->prepare('SELECT u.player_skin FROM mapi_keys k JOIN mapi_users u ON u.id = k.user_id WHERE k.api_key = ? AND k.status = 1 LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('s', $key);
        if (!$stmt->execute()) return null;
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $skin = trim((string)($row['player_skin'] ?? ''));
        return $skin !== '' ? $skin : null;
    } catch (Throwable $e) {
        error_log('[msapi] skin lookup failed: ' . $e->getMessage());
        return null;
    } finally {
        ini_set('display_errors', $prevDisplay === false ? '0' : $prevDisplay);
        while (ob_get_level() > $obLevel) { ob_end_clean(); }
    }
}

// ═══ 皮肤清单 ═══
// 一个皮肤 = modules/<skin>/ 下的皮肤层（DOM + 样式 + 交互），由 skin.json 声明；
// 引擎（鉴权 / 状态 / 音频内核 / 歌词 / 主题）全站只有一份，见 modules/core/core.json。
// 清单不再硬编码在本文件里 —— 后台皮肤选择器读的是同一份清单。
// ═══ 皮肤解析 ═══
// 模块文件请求：皮肤由 ?skin= 带过来（bootstrap 解析一次后写进 URL），不查库；
//                同时兼容旧的 ?route=。
// 启动脚本：显式 route 优先 → 否则按 key 解析（key → 用户 → player_skin）→ 否则默认皮肤。
$API_KEY   = trim((string)($_GET['key'] ?? ''));
$skinParam = (string)($_GET['skin'] ?? ($_GET['route'] ?? ''));

if ($skinParam === '' && $API_KEY !== '' && !isset($_GET['asset'])) {
    $byKey = msapi_skin_for_key($API_KEY);
    if ($byKey !== null && msapi_skin($byKey)) $skinParam = $byKey;
}
if ($skinParam === '') $skinParam = msapi_default_skin();

$skin = msapi_skin($skinParam);
if (!$skin) {
    // 按 key 解析出的皮肤可能已被删除：回落默认皮肤，而不是让整个嵌入站报错
    error_log('[msapi] unknown skin "' . $skinParam . '"，回落默认皮肤');
    $skin = msapi_skin(msapi_default_skin());
    if (!$skin) msapi_send_error('No skin available', 500);
}
$SKIN_NAME = $skin['name'];
$route     = $SKIN_NAME;   // 后续（单体分支 / 模块 URL）沿用

// ═══ 模块资源输出: 经 PHP 读出并做 ETag 校验 ═══
if (isset($_GET['asset'])) {
    $asset = basename((string)$_GET['asset']);   // basename 防目录穿越
    // 白名单来自清单（内核清单 ∪ 皮肤清单），不再硬编码文件名数组
    if ($SKIN_NAME === '' || !in_array($asset, msapi_skin_assets($SKIN_NAME), true)) {
        msapi_send_error('module not found: ' . $asset);
    }
    // 解析顺序：皮肤目录优先（同名可覆盖引擎），找不到再落到 modules/core/
    $root     = __DIR__;
    $assetAbs = $root . '/modules/' . $SKIN_NAME . '/' . $asset;
    if (!is_file($assetAbs)) $assetAbs = $root . '/modules/core/' . $asset;
    if (!is_file($assetAbs)) {
        msapi_send_error('module not found: ' . $asset);
    }
    msapi_send_js((string)file_get_contents($assetAbs), (int)filemtime($assetAbs));
}

// compute site root URL (strip subdirectories so _SCRIPT_BASE points to root)
// 注意：不能用 dirname() —— Windows 上 dirname('/api.php') 返回的是反斜杠 "\"，
// 会让 _SCRIPT_BASE 变成 https://msapi\/ 并拼出 https://msapi//api.php 这种双斜杠地址。
$selfDir = preg_replace('#/[^/]*$#', '', (string)($_SERVER['SCRIPT_NAME'] ?? '/api.php'));
$script_base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . rtrim($selfDir, '/') . '/';
$script_base = preg_replace('#/modules/$#', '/', $script_base);

// 本文件相对站点根目录的路径（兼容子目录部署），用于把模块请求也导回本文件
$basePath = rtrim((string)parse_url($script_base, PHP_URL_PATH), '/');
$selfRel  = ltrim(substr((string)$_SERVER['SCRIPT_NAME'], strlen($basePath)), '/');

// legacy-compat: embed.js variants served via GET param (e.g. api.php?embed=1)
$embed_mode = isset($_GET['embed']);

// ═══ 单体皮肤（mode=monolith）：直接加载它的入口文件，不走模块机制 ═══
if ($skin && $skin['mode'] === 'monolith') {
    $chiropractic_wrapper = <<<'JS'
(function(){
'use strict';
var cur = document.currentScript;
var s = document.createElement('script');
s.src = _PHP_EMBED_SRC_;
if (cur) {
  var key = cur.getAttribute('key');
  var token = cur.getAttribute('token');
  var api = cur.getAttribute('api');
  var cdn_aplayer_css = cur.getAttribute('cdn-aplayer-css');
  var cdn_aplayer_js = cur.getAttribute('cdn-aplayer-js');
  if (key) s.setAttribute('key', key);
  if (token) s.setAttribute('token', token);
  if (api) s.setAttribute('api', api);
  if (cdn_aplayer_css) s.setAttribute('cdn-aplayer-css', cdn_aplayer_css);
  if (cdn_aplayer_js) s.setAttribute('cdn-aplayer-js', cdn_aplayer_js);
}
document.head.appendChild(s);
})();
JS;
    msapi_send_js(str_replace('_PHP_EMBED_SRC_', json_encode($script_base . $selfRel . '?route=' . rawurlencode($skin['name']) . '&asset=' . rawurlencode($skin['entry'])), $chiropractic_wrapper));
}

// ═══ Router 路由：模块化播放器启动脚本 ═══
$esc_script_base = json_encode($script_base);

$router_js = <<<'JS'
(function(){
    'use strict';

    // ═══ 实例身份（必须最先算，且只依赖本脚本自身的属性） ═══
    // swup / Pjax / Turbo 这类无刷新换页框架会重执行 head 里的脚本。
    // 因此身份绝不能依赖"它是第几个 route=router 脚本"——模块脚本自身的 URL 也含 route=router，
    // 重执行时匹配数量必然变化，算出的 ID 每次都不同，去重随即失效（表现为两个播放器同时出声）。
    var _selfTag = document.currentScript;

    // 配置来源：优先标签属性（旧写法 key="..."），其次脚本 URL 的查询参数（新写法 /api.php?key=xxx）。
    // 转发壳会把旧标签上的属性原样搬进查询参数，所以两种写法都能读到同样的值。
    var _qs = null;
    try { _qs = (_selfTag && _selfTag.src) ? new URL(_selfTag.src, location.href).searchParams : null; } catch(e) { _qs = null; }
    function _param(name) { try { return _qs ? (_qs.get(name) || '') : ''; } catch(e) { return ''; } }
    function _attr(name, alt) {
        if (!_selfTag) return '';
        var v = _selfTag.getAttribute(name) || (alt ? (_selfTag.getAttribute(alt) || '') : '');
        return v || _param(name);
    }

    function _hash36(s) {
        var h = 5381;
        for (var i = 0; i < s.length; i++) h = ((h << 5) + h + s.charCodeAt(i)) | 0;
        return (h >>> 0).toString(36);
    }

    // 显式 data-player-id / player-id / id 优先; 否则由 key + api 端点派生稳定键
    // (同一份配置 → 同一个键; 作者显式给 id 才能同页跑两个"同配置"播放器)
    var EXPLICIT_ID = String(_attr('data-player-id', 'player-id') || (_selfTag && _selfTag.id) || '')
        .replace(/[^A-Za-z0-9_-]/g, '');
    var DEDUP_KEY = EXPLICIT_ID
        ? 'id:' + EXPLICIT_ID
        : 'k:' + _hash36(_attr('key') + '\u0000' + _attr('api'));

    var _ALREADY_BOOTED = false;

    // ═══ 幂等启动: 同一播放器被重复执行时不得重建 ═══
    // 还活着 → 本次执行整体退出（播放不中断，这是换页框架下的正确行为）;
    // 已是僵尸（宿主被移除 / 启动失败）→ 才销毁重建。不同播放器（不同 DEDUP_KEY）互不影响。
    (function(){
        var all = window.__mapiPlayers || [];
        for (var i = all.length - 1; i >= 0; i--) {
            var inst = all[i];
            if (!inst || !inst._ || inst._.DEDUP_KEY !== DEDUP_KEY) continue;   // 别的播放器不动
            var host = inst._hostRoot && inst._hostRoot.host;
            if (!inst._destroyed && !inst._bootFailed && host && host.isConnected) {
                _ALREADY_BOOTED = true;
                return;
            }
            all.splice(i, 1);
            try { inst._destroyed = true; } catch(e) {}
            if (typeof inst.destroy === 'function') { try { inst.destroy(); } catch(e) {} }
            try { if (host && host.parentNode) host.parentNode.removeChild(host); } catch(e) {}
        }
    })();

    if (_ALREADY_BOOTED) return;   // 同一个播放器已在运行: 静默退出, 不重复建 UI / 不重复起音频

    // ═══ 强制 UTF-8 编码 ═══
    (function(){
        var m = document.createElement('meta');
        m.setAttribute('charset', 'utf-8');
        document.head.appendChild(m);
    })();

    // ═══ 不再改动宿主页面的滚动条 ═══
    // 旧版本会往宿主页 head 注入 `html::-webkit-scrollbar{display:none}` + `scrollbar-width:none`，
    // 这会强行改掉整站外观，并与 OverlayScrollbars 之类的主题滚动条方案互相干扰。
    // 播放器自身的滚动区域都在 closed shadow root 内，样式已在组件内部处理，无需污染宿主页面。

    // ═══ 站点根路径（PHP 注入，避免因 api.php 位于子目录导致路径偏移） ═══
    var _SCRIPT_BASE = _PHP_SCRIPT_BASE_;
    var API_BASE = (function(){
        var a = _attr('api');
        if (a) {
            if (/^https?:\/\//i.test(a)) return a.replace(/\/?$/, '/api.php');
            return _SCRIPT_BASE + a.replace(/\/?$/, '/api.php');
        }
        return _SCRIPT_BASE + 'admin/api/api.php';
    })();
    var API_KEY = _attr('key');
    var API_TOKEN = _attr('token');
    // 显式指定的皮肤（?route=xxx）。要带给 get-config：位置是按皮肤存的，
    // 不传的话「按 rose 渲染、却拿到 router 的位置」就会对不上。
    var API_ROUTE = _attr('route');

    // ═══ APlayer 内核（默认自托管，支持外部覆盖） ═══
    // 只加载 JS：APlayer 在这里仅作音频内核 —— 它的 UI 容器是 display:none、且位于 closed shadow root 内，
    // 自带样式表（.aplayer*）既进不来也用不上，播放器界面全部是自己画的，所以不加载它的 CSS。
    // 默认走本站自带文件而不依赖 jsDelivr：第三方 CDN 在部分网络下不可达时，
    // 内核会永远停在加载态（按钮一直转圈），而且访客侧无从排查。
    var CDN = {
        aplayer_js: _attr('cdn-aplayer-js') || _SCRIPT_BASE + 'assets/lib/aplayer/APlayer.min.js',
    };

    // ═══ Cookie 持久化 ═══
    function setCookie(n, v) { try { document.cookie = n + '=' + encodeURIComponent(v) + ';path=/;max-age=31536000;SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : ''); } catch(e) {} }
    function getCookie(n) { try { var m = document.cookie.match('(^| )' + n + '=([^;]+)'); return m ? decodeURIComponent(m[2]) : ''; } catch(e) { return ''; } }
    // 播放器实例标识：同页多个播放器各自独立记忆（Cookie 命名空间）
    // 只认作者显式声明的 data-player-id / player-id / id，不再按"脚本顺序"编号——
    // 模块脚本的 URL 同样含 route=router，顺序在重执行时必然漂移，编号会连累 Cookie 记忆错位
    var INSTANCE_ID = EXPLICIT_ID;

    function delCookie(n) { try { document.cookie = n + '=;path=/;max-age=0'; } catch(e) {} }

    // ═══ 工具函数 ═══
    function escapeHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function fmt(s) {
        var m = Math.floor(s / 60), sec = Math.floor(s % 60);
        return (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
    }

    // ═══ 创建共享命名空间 ═══
    window.__mapiPlayers = window.__mapiPlayers || [];
    var MP = {
        ap: null, songs: [], playlists: [], currentPlaylistIndex: 0, mode: 'list',
        open: false, lrcLines: [], _side: 'right', _showLrc: true, _lrcAnimating: false,
        _retracted: false, _retractTimer: null, _imOpen: false, _autoTheme: true,
        _themeMode: 'light', _autoplayDefault: false, _autoplayTried: false, _server: 'netease',
        _loading: false, _css: '',
        _hostRoot: null,
        _allSongs: {}, _playlistCovers: {}, _loadingPlaylists: {}, _loadFailed: {}, _timeoutFailed: {},
        _destroyed: false, _bootFailed: false, _abort: null, _bootXhr: null, _configXhr: null,
        _annTimer: null, _loadFallbackTimer: null,
    };

    // ═══ 共享工具引用（所有模块通过 MP._ 访问） ═══
    window.__mapiPlayers.push(MP);

    MP._ = {
        SCRIPT_BASE: _SCRIPT_BASE,
        INSTANCE_ID: INSTANCE_ID,
        DEDUP_KEY: DEDUP_KEY,
        API_BASE: API_BASE,
        API_KEY: API_KEY,
        API_TOKEN: API_TOKEN,
        CDN: CDN,
        setCookie: setCookie,
        getCookie: getCookie,
        delCookie: delCookie,
        escapeHtml: escapeHtml,
        fmt: fmt,
    };
    // 皮肤模块以自己的 IIFE 接入：(function(MP){ ... })(window.MP)
    // 所以命名空间必须挂到全局，否则皮肤代码会整段空跑（宿主建不出来）。
    window.MP = MP;

    // ═══ 脚本加载器（标记为播放器资源，卸载时统一清理，避免 head 无限增长） ═══
    function loadScript(url, isAsset) {
        return new Promise(function(resolve, reject) {
            var s = document.createElement('script');
            s.src = url; s.onload = resolve; s.onerror = reject;
            if (isAsset) s.setAttribute('data-mapi-asset', '1');
            document.head.appendChild(s);
        });
    }

    // ═══ 模块列表（PHP 注入，每项带 ?v=文件修改时间，避免浏览器命中旧缓存） ═══
    var MODULES = [
_PHP_MODULES_
    ];

    // ═══ 模块加载器（顺序加载，失败跳过） ═══
    function loadModules(urls, cb) {
        var i = 0, base = _SCRIPT_BASE;
        function next() {
            if (i >= urls.length) return cb();
            loadScript(base + urls[i], true).then(function() { i++; next(); }).catch(function() { i++; next(); });
        }
        next();
    }

    // ═══ 启动 ═══
    function _bootAlive() {
        if (MP._destroyed) return false;
        if (_selfTag && !_selfTag.parentNode) return false;
        return true;
    }

    // 注：这里刻意不注入 APlayer 自带样式表。
    // 它只在「把 APlayer 原生 UI 显示出来」时才有意义，而本项目的界面完全自绘 ——
    // 注进去只会让宿主博客多一个「跨域 + 阻塞渲染」的第三方请求。

    // 多实例：模块加载期间 window.__mapiPlayer 必须指向本实例，故各实例启动串行
    window.__mapiBootChain = (window.__mapiBootChain || Promise.resolve()).then(function(){
        window.__mapiPlayer = MP;
        return new Promise(function(bootDone) {
            loadModules(MODULES, function() { bootDone(); });
        });
    }).then(function() {
        window.__mapiPlayer = MP;               // 兼容旧宿主页：指向最近加载完模块的实例
        if (!_bootAlive()) return;                      // 加载中途被卸载
        MP.verifyKey(function(ok, code) {
            if (!_bootAlive()) return;
            if (!ok) {                                      // 密钥无效/停用/限流/连不上：按原因码各自提示
                MP._bootFailed = true;
                if (typeof MP.notice === 'function') MP.notice(code || 'server_error');
                return;
            }
            MP.showConsentBanner(function(consented) {
                if (!_bootAlive()) return;
                MP._cookieConsented = consented;
                (MP.loadCSS || function (cb) { cb(); })(function() {
                    if (!_bootAlive()) return;
                    var _bootXhr = new XMLHttpRequest();
                    MP._bootXhr = _bootXhr;
                    _bootXhr.open('GET', API_BASE + '?action=get-config&token=' + encodeURIComponent(API_TOKEN || API_KEY) + (API_ROUTE ? '&route=' + encodeURIComponent(API_ROUTE) : ''), true);
                    _bootXhr.timeout = 20000;
                    _bootXhr.ontimeout = function() {
                        MP._bootFailed = true;                 // 配置请求超时：播放器无法启动
                        if (typeof MP.notice === 'function') MP.notice('offline');
                        MP._loading = false;
                        if (typeof MP.$ === 'function') { var t2 = MP.$('toggle'); if (t2) t2.classList.remove('loading'); }
                    };
                    _bootXhr.onerror = function() {
                        MP._bootFailed = true;                 // 配置拉取失败：播放器无法启动
                        if (typeof MP.notice === 'function') MP.notice('offline');
                        MP._loading = false;
                        if (typeof MP.$ === 'function') { var t0 = MP.$('toggle'); if (t0) t0.classList.remove('loading'); }
                    };
                    _bootXhr.onload = function() {
                        if (!_bootAlive()) return;
                        MP._bootXhr = null;
                        var _hasPlaylist = false;
                        try {
                            var _d = JSON.parse(_bootXhr.responseText);
                            if (_d.ok && _d.config && !(_d.config.domain && _d.config.domain.blocked)) {
                                // 复用启动配置：避免播放器再发一次 get-config（单线程服务器上每个请求都会拖慢页面切换）
                                window.__mszeph_config = _d.config;
                                if (_d.config.playlists && _d.config.playlists.length) _hasPlaylist = true;
                            } else if (_d.config && _d.config.domain && _d.config.domain.blocked) {
                                MP._bootFailed = true;             // 域名未授权 / 密钥与域名不匹配：最容易被当成“配置坏了”
                                if (typeof MP.notice === 'function') {
                                    if (_d.config.domain.reason === 'key_domain') {
                                        MP.notice('key_domain', { detail: '这条密钥只授权给 ' + (_d.config.domain.keyDomain || '它绑定的域名') });
                                    } else {
                                        MP.notice('domain_blocked');
                                    }
                                }
                            } else {
                                MP._bootFailed = true;
                                if (typeof MP.notice === 'function') MP.notice(_d.code || 'server_error');
                            }
                        } catch(e) {
                            MP._bootFailed = true;
                            if (typeof MP.notice === 'function') MP.notice('server_error');
                        }
                        MP._hostRoot = MP.createWidget();
                        MP.root = MP._hostRoot;
                        MP.$ = function(id) { return MP._hostRoot ? MP._hostRoot.querySelector('[data-mp="' + id + '"]') : null; };

                        // 加载前先还原本实例的位置记忆
                        if (consented) {
                            if (typeof MP._applyDefaultPos === 'function') MP._applyDefaultPos(true, MP._readPos());
                        }
                        if (!_hasPlaylist) {
                            MP._loading = false;
                            // 启动已经因为域名/密钥/网络失败时不再补一条“没有歌单”，
                            // 否则会同时弹两条提示，把真正的原因挤到后面
                            if (!MP._bootFailed) MP._showNoPlaylistNotice();
                            var loadingEl = MP.$('toggleLoading');
                            if (loadingEl) loadingEl.style.display = 'none';
                            var toggleBtn = MP.$('toggle');
                            if (toggleBtn) toggleBtn.style.display = 'none';
                        } else {
                            MP.showToggle();
                            MP._loading = true;
                            var toggleBtn2 = MP.$('toggle');
                            if (toggleBtn2) toggleBtn2.classList.add('loading');
                            loadScript(CDN.aplayer_js, true).then(function() {
                                if (!_bootAlive()) return;      // 加载中途被卸载
                                MP.init();
                                MP.bindUI();
                                // 兜底：不打断“呼吸动效”，只有后台预加载停下来后才允许清除加载态
                                if (MP._loadFallbackTimer) clearTimeout(MP._loadFallbackTimer);
                                MP._loadFallbackTimer = setTimeout(function tick() {
                                    MP._loadFallbackTimer = null;
                                    if (MP._destroyed) return;
                                    MP._fallbackRounds = (MP._fallbackRounds || 0) + 1;
                                    if (MP._preloadRunning && MP._fallbackRounds < 6) {
                                        MP._loadFallbackTimer = setTimeout(tick, 10000);
                                        return;
                                    }
                                    MP._loading = false;
                                    var t = MP.$('toggle');
                                    if (t) t.classList.remove('loading');
                                }, 10000);
                            }).catch(function() {
                                // APlayer 资源加载失败（CDN 不可达等）：清掉加载态，让按钮回到“加载”可重试
                                MP._bootFailed = true;
                                if (typeof MP.notice === 'function') MP.notice('cdn_failed');
                                var t = MP.$('toggle');
                                if (t) t.classList.remove('loading');
                                MP._loading = false;
                                console.warn('[Msapi] APlayer 资源加载失败，播放器未启动');
                            });
                        }
                    };
                    _bootXhr.send();
                });
            });
        });
    });

})();
JS;

// 模块清单 = 引擎（core.json 声明，顺序固定）+ 皮肤（skin.json 声明）。
// 统一经本文件输出（no-store 强制不缓存），因此不再需要 ?v= 版本号。
$moduleFiles = array_merge(msapi_core()['modules'], $skin['modules']);
$moduleLines = [];
foreach ($moduleFiles as $mf) {
    $moduleLines[] = "        '" . $selfRel . '?skin=' . rawurlencode($SKIN_NAME) . '&asset=' . $mf . "',";
}
$esc_modules = implode("\n", $moduleLines);

msapi_send_js(str_replace(
    ['_PHP_SCRIPT_BASE_', '_PHP_MODULES_'],
    [$esc_script_base, $esc_modules],
    $router_js
));