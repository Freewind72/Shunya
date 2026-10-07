/**
 * modules/core/inset.js —— 宿主页面底部占用探测
 *
 * 宿主站常在底部固定一条导航栏 / tab 栏（移动端最多）。播放器与歌词框都要知道
 * 「底部被占掉多少像素」，才能抬到它上面，而不是被压在底下。
 *
 * ── 对外接口 ────────────────────────────────────────────────────────
 *   MP._getOverlapBottom()        歌词框应让出的底部像素
 *   MP._getPlayerInset()          播放器本体应让出的底部像素
 *   MP._bottomInset / _playerInset  同一份值的缓存，皮肤可直接读
 *   MP._refreshBottomInset(true)  立即重测（忽略迟滞，用于刚展开面板等时机）
 *   MP._onBottomInset(fn)         值变化时回调（皮肤据此重新定位自己）
 *   MP._insetAuto()               自动探测的原始值（后台可显示，便于人工校准）
 *   MP._insetDevice()             当前判定为哪一端：'pc' / 'mobile'
 *   MP._insetDestroy()            解绑
 *
 * ── 配置来源（后台「域名」页，PC 与移动端各一套） ────────────────────
 *   window.__mszeph_config.domain = {
 *     pcAuto, pcLyrics, pcPlayer,
 *     moAuto, moLyrics, moPlayer,
 *     auto, lyricsBottom, playerBottom      // 旧字段，仅在没有分端字段时兜底
 *   }
 *   自动模式：让出 = 探测值 + 本端修正量 + 8px 间隙
 *   手动模式：让出 = 本端修正量（绝对值，探测值不参与，适合探测不准的宿主）
 *
 * ── 判定规则（放宽过一轮：实测的老规则会漏掉真实宿主底栏） ──────────
 *   ① 扫描：body 直接子元素逐层下探（≤4 层）+ 定向选择器
 *      （nav / [role=tablist] / class 含 tabbar|tab-bar|bottom-nav|dock / id 含 tabbar|tabbar）
 *   ② 位置：fixed 或 sticky，且真的贴着视口底部（允许离底 ≤24px 的悬浮胶囊）
 *   ③ 尺寸：高 28~120px、宽 ≥55% 视口
 *   ④ 内容：≥2 个可点子项（a/button/[role=tab]/[role=button]/[onclick]），
 *      或者 ≥3 个等宽子元素（很多主题用 div + JS 点击，没有 a/button）
 *   ⑤ 排除：播放器自身的节点（id 含 mapi / data-mp / data-mapi / 宿主根）
 *   ⑥ 迟滞：差值 >8px 且连续 2 次采样一致才生效；另有 resize / 转屏 /
 *      visualViewport / SPA 换页 / MutationObserver / 5s 兜底复测
 */
(function (MP) {
    if (!MP) return;

    var GUARD        = 8;      // 自动模式下的视觉间隙
    var MIN_H        = 28;     // 底栏高度下限
    var MAX_H        = 120;    // 底栏高度上限（更高的多是整屏面板）
    var MIN_W_RATIO  = 0.55;   // 宽度至少占视口宽度的比例
    var MIN_ITEMS    = 2;      // 至少这么多可点子项
    var MIN_CHILDREN = 3;      // 没有可点子项时，退而求其次要求这么多等宽子元素
    var GAP_MAX      = 24;     // 允许离视口底部多远（悬浮胶囊式底栏）
    var SCAN_DEPTH   = 4;      // body 子元素向下最多再看这么多层
    var HYSTERESIS   = 8;      // 迟滞阈值
    var CONFIRM      = 2;      // 连续两次采样一致才生效
    var IDLE_SCAN_MS = 5000;   // 兜底复测间隔（仅页面可见时）
    var MAX_RATIO    = 0.5;    // 结果上限：不超过视口高度的一半
    var REPORT_MS    = 60000;  // 探测值上报节流

    var _value = 0;            // 歌词框让出值
    var _pvalue = 0;           // 播放器本身让出值
    var _auto  = 0;            // 自动探测原始值（不含间隙 / 修正量）
    var _next  = -1;
    var _hits  = 0;
    var _raf   = 0;
    var _timer = null;
    var _mo    = null;
    var _cbs   = [];
    var _bound = false;
    var _lastReport = 0;
    var _lastReportVal = -1;

    /* ── 当前是哪一端：与 player.js / lyrics.js 用同一个断点 ── */
    function isMobile() {
        return (window.innerWidth || 0) <= 768;
    }

    /* ── 后台下发的域名配置（懒读：启动脚本可能在模块之后才注入） ── */
    function domainCfg() {
        try {
            var c = window.__mszeph_config && window.__mszeph_config.domain;
            if (c && typeof c === 'object') return c;
        } catch (e) { /* 忽略 */ }
        return null;
    }

    function num(v, d) {
        var n = parseFloat(v);
        return isFinite(n) ? n : d;
    }

    function has(c, k) {
        return c[k] !== undefined && c[k] !== null && c[k] !== '';
    }

    /* ── 本端生效的配置：自动开关 + 歌词修正 + 播放器修正 ── */
    function deviceCfg() {
        var c = domainCfg();
        if (!c) return { auto: 1, lyrics: 0, player: 0 };
        var mob = isMobile();
        var auto, lyrics, player;
        if (mob) {
            auto   = has(c, 'moAuto')   ? num(c.moAuto, 1)   : (has(c, 'auto') ? num(c.auto, 1) : 1);
            lyrics = has(c, 'moLyrics') ? num(c.moLyrics, 0) : num(c.lyricsBottom, 0);
            player = has(c, 'moPlayer') ? c.moPlayer        : (has(c, 'playerBottom') ? c.playerBottom : null);
        } else {
            auto   = has(c, 'pcAuto')   ? num(c.pcAuto, 1)   : (has(c, 'auto') ? num(c.auto, 1) : 1);
            lyrics = has(c, 'pcLyrics') ? num(c.pcLyrics, 0) : num(c.lyricsBottom, 0);
            player = has(c, 'pcPlayer') ? c.pcPlayer        : (has(c, 'playerBottom') ? c.playerBottom : null);
        }
        if (player === null || player === undefined || player === '') player = lyrics;
        return { auto: auto ? 1 : 0, lyrics: lyrics, player: num(player, lyrics) };
    }

    /* ── 是不是播放器自己的节点（它也可能贴底 + 有一堆按钮） ── */
    function isSelf(el) {
        var id = (el.id || '').toLowerCase();
        if (id && id.indexOf('mapi') >= 0) return true;
        if (el.getAttribute) {
            if (el.getAttribute('data-mp') !== null) return true;
            if (el.getAttribute('data-mapi') !== null) return true;
        }
        return el === MP.root || el === MP._root || el === MP.el || el === MP._el;
    }

    /* ── 有没有「可点子项」：很多主题用 div + JS 点击，退化成等宽子元素判断 ── */
    function itemCount(el) {
        var n = 0;
        try { n = el.querySelectorAll('a,button,[role="tab"],[role="button"],[onclick]').length; } catch (e) { n = 0; }
        if (n >= MIN_ITEMS) return n;
        // 退化判断：≥3 个子元素，宽度彼此接近（容差 30%），说明是一排按钮
        var kids = el.children;
        if (kids && kids.length >= MIN_CHILDREN) {
            var w0 = 0, same = 0;
            for (var i = 0; i < kids.length; i++) {
                var w = kids[i].getBoundingClientRect().width;
                if (w <= 0) continue;
                if (!w0) { w0 = w; same = 1; continue; }
                if (Math.abs(w - w0) <= w0 * 0.3) same++;
            }
            if (same >= MIN_CHILDREN) return same;
        }
        return n;
    }

    /* ── 单元素判定：是「一条贴底的导航栏」就返回它占用的高度（含离底间隙） ── */
    function bottomBarHeight(el, vpH, vpW) {
        if (!el || el.nodeType !== 1) return 0;
        if (isSelf(el)) return 0;

        var st;
        try { st = window.getComputedStyle(el); } catch (e) { return 0; }
        if (!st) return 0;
        if (st.position !== 'fixed' && st.position !== 'sticky') return 0;
        if (st.visibility === 'hidden' || st.display === 'none') return 0;
        if (num(st.opacity, 1) <= 0.1) return 0;

        var r = el.getBoundingClientRect();
        if (r.width <= 0 || r.height <= 0) return 0;
        if (r.height < MIN_H || r.height > MAX_H) return 0;        // 高度像一条栏
        if (r.width < vpW * MIN_W_RATIO) return 0;                 // 够宽
        var gap = vpH - r.bottom;
        if (gap < -2 || gap > GAP_MAX) return 0;                   // 贴着底（或悬浮但很近）
        if (r.top < 0) return 0;                                   // 顶部对齐的不算底栏
        if (itemCount(el) < MIN_ITEMS) return 0;                   // 里面确实有几个可点子项

        // 让出值 = 从视口底部到这条栏上沿的距离，正好把栏让开（旧公式，实测够用）
        return Math.round(vpH - r.top);
    }

    /* ── 下探扫描 ── */
    function walk(el, vpH, vpW, depth) {
        if (!el || el.nodeType !== 1) return 0;
        if (isSelf(el)) return 0;                 // 播放器自身：整棵子树都不看
        var h = bottomBarHeight(el, vpH, vpW);
        if (h > 0) return h;
        if (depth >= SCAN_DEPTH) return 0;
        var best = 0;
        var kids = el.children;
        for (var i = 0; i < kids.length; i++) {
            var v = walk(kids[i], vpH, vpW, depth + 1);
            if (v > best) best = v;
        }
        return best;
    }

    /* ── 定向补扫：底栏经常被埋在很深的容器里，靠固定深度会漏 ── */
    var TARGET_SEL = 'nav,[role="tablist"],[role="tabbar"],[class*="tabbar"],[class*="tab-bar"],' +
                     '[class*="bottom-nav"],[class*="bottomnav"],[class*="dock"],[class*="toolbar"],' +
                     '[id*="tabbar"],[id*="tab-bar"],[id*="bottomnav"],[id*="bottom-nav"]';

    function scan() {
        var vpH = window.innerHeight || 0;
        var vpW = window.innerWidth || 0;
        if (!vpH || !vpW || !document.body) return 0;

        var best = 0;
        var roots = document.body.children;
        for (var i = 0; i < roots.length; i++) {
            var v = walk(roots[i], vpH, vpW, 0);
            if (v > best) best = v;
        }

        var list = null;
        try { list = document.querySelectorAll(TARGET_SEL); } catch (e) { list = null; }
        if (list) {
            for (var j = 0; j < list.length; j++) {
                var hv = bottomBarHeight(list[j], vpH, vpW);
                if (hv > best) best = hv;
            }
        }
        return Math.min(best, Math.round(vpH * MAX_RATIO));
    }

    /* ── 发布成 CSS 变量：自定义属性会继承进 Shadow DOM，皮肤直接 var() 用 ── */
    function publish() {
        try {
            var root = document.documentElement;
            if (root && root.style && root.style.setProperty) {
                root.style.setProperty('--mapi-inset-lyrics', _value + 'px');
                root.style.setProperty('--mapi-inset-player', _pvalue + 'px');
            }
        } catch (e) { /* 忽略 */ }
    }

    function fire() {
        publish();
        for (var i = 0; i < _cbs.length; i++) {
            try { _cbs[i](_value, _pvalue); } catch (e) { /* 单个回调出错不影响其它 */ }
        }
    }

    /* ── 探测值上报（后台「域名」页据此显示「最近探测」并支持一键自动校准） ── */
    function report(raw) {
        if (!raw || raw <= 0) return;                       // 没探测到就不用上报
        var now = Date.now();
        if (raw === _lastReportVal && now - _lastReport < REPORT_MS) return;
        _lastReportVal = raw;
        _lastReport = now;
        try {
            var api = MP._ && MP._.API_BASE;
            var key = MP._ && (MP._.API_KEY || '');
            if (!api || !key) return;
            var body = 'key=' + encodeURIComponent(key) +
                       '&device=' + (isMobile() ? 'mobile' : 'pc') +
                       '&value=' + encodeURIComponent(raw);
            if (window.fetch) {
                fetch(api + '?action=inset-report', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body,
                    credentials: 'same-origin',
                    keepalive: true
                }).catch(function () {});
            } else {
                var x = new XMLHttpRequest();
                x.open('POST', api + '?action=inset-report', true);
                x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                x.send(body);
            }
        } catch (e) { /* 上报失败不影响播放器 */ }
    }

    /* ── 迟滞 + 连续确认 ── */
    function commit(force) {
        var raw = scan();
        _auto = raw;
        if (raw > 0) report(raw);

        var cfg = deviceCfg();
        var want  = cfg.auto ? Math.max(0, Math.round(raw + cfg.lyrics + GUARD))
                             : Math.max(0, Math.round(cfg.lyrics));
        var wantP = cfg.auto ? Math.max(0, Math.round(raw + cfg.player + GUARD))
                             : Math.max(0, Math.round(cfg.player));

        if (force) {
            _next = -1; _hits = 0;
            var changed = (want !== _value) || (wantP !== _pvalue);
            _value = want; _pvalue = wantP;
            if (changed) fire();
            return _value;
        }
        if (want === _value && wantP === _pvalue) { _next = -1; _hits = 0; return _value; }
        if (Math.abs(want - _value) < HYSTERESIS && Math.abs(wantP - _pvalue) < HYSTERESIS) return _value;
        if (want === _next) {
            _hits++;
            if (_hits >= CONFIRM) { _next = -1; _hits = 0; _value = want; _pvalue = wantP; fire(); }
        } else {
            _next = want; _hits = 1;
        }
        return _value;
    }

    /* ── 合并重测：一帧内多次触发只算一次 ── */
    function schedule() {
        if (_raf) return;
        var raf = window.requestAnimationFrame || function (f) { return setTimeout(f, 16); };
        _raf = raf(function () { _raf = 0; commit(false); });
    }

    /* ── 事件绑定（只做一次） ── */
    function bind() {
        if (_bound) return;
        _bound = true;

        window.addEventListener('resize', schedule, { passive: true });
        window.addEventListener('orientationchange', schedule, { passive: true });
        window.addEventListener('popstate', schedule, { passive: true });
        if (window.visualViewport && window.visualViewport.addEventListener) {
            window.visualViewport.addEventListener('resize', schedule);
        }
        // SPA 换页 / 懒加载：只看 body 直接子元素的增删，避免观察整棵树的性能开销
        if (window.MutationObserver && document.body) {
            try {
                _mo = new MutationObserver(schedule);
                _mo.observe(document.body, { childList: true, subtree: true });
            } catch (e) { _mo = null; }
        }
        _timer = setInterval(function () {
            if (document.hidden) return;
            schedule();
        }, IDLE_SCAN_MS);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) schedule();
        }, { passive: true });
    }

    /* ── 对外接口 ── */
    MP._bottomInset = 0;
    MP._playerInset = 0;

    MP._getOverlapBottom = function () {
        if (!_bound) { bind(); commit(true); MP._bottomInset = _value; MP._playerInset = _pvalue; }
        return _value;
    };

    MP._getPlayerInset = function () {
        if (!_bound) { bind(); commit(true); MP._bottomInset = _value; MP._playerInset = _pvalue; }
        return _pvalue;
    };

    MP._refreshBottomInset = function (force) {
        if (!_bound) bind();
        commit(force !== false);
        MP._bottomInset = _value; MP._playerInset = _pvalue;
        return _value;
    };

    MP._onBottomInset = function (fn) {
        if (typeof fn === 'function') _cbs.push(fn);
    };

    MP._insetAuto = function () { return _auto; };

    MP._insetDevice = function () { return isMobile() ? 'mobile' : 'pc'; };

    MP._insetDestroy = function () {
        if (_raf) {
            if (window.cancelAnimationFrame) window.cancelAnimationFrame(_raf);
            else clearTimeout(_raf);
        }
        if (_timer) clearInterval(_timer);
        if (_mo) { try { _mo.disconnect(); } catch (e) {} }
        if (window.visualViewport && window.visualViewport.removeEventListener) {
            window.visualViewport.removeEventListener('resize', schedule);
        }
        _timer = null; _mo = null; _raf = 0;
        _bound = false; _value = 0; _auto = 0;
    };


    /* 模块初始化：先把变量放出去（无底栏时就是 0px），消费者不必依赖 var() 兜底 */
    publish();
})(window.__mapiPlayer);
