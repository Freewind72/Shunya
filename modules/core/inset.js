/**
 * modules/core/inset.js —— 宿主页面底部占用探测
 *
 * 宿主站常有固定在底部的导航栏 / 工具条（移动端 tab 栏最多）。播放器与歌词框
 * 都需要知道「底部被占掉多少像素」，才能把自己抬到它上方，而不是压在上面。
 *
 * ── 对外接口（旧签名保持不变，lyrics.js 原先就是调这个） ─────────────
 *   MP._getOverlapBottom()        当前应让出的底部像素（已含 8px 视觉间隙）
 *   MP._bottomInset               同一份值的缓存，皮肤可直接读
 *   MP._refreshBottomInset(true)  立即重测（忽略迟滞，用于刚展开面板等时机）
 *   MP._onBottomInset(fn)         值变化时回调（皮肤据此重新定位自己）
 *   MP._insetAuto()               自动探测原始值（后台可上报，便于人工校准）
 *
 * ── 数据来源优先级 ────────────────────────────────────────────────
 *   ① 后台按域名下发的覆盖值 window.__mszeph_config.domain
 *        auto=1 → 自动探测值 + lyricsBottom 修正量
 *        auto=0 → 只用修正量（最稳，零抖动）
 *   ② 没有下发时 → 纯自动探测（与旧行为一致）
 *
 * ── 相对旧实现（原 lyrics.js 里的 _getOverlapBottom）修掉的五点 ──────
 *   ① 扫描范围：body 直接子元素 + 深度 <=2，不再 querySelectorAll('body *')
 *   ② 判定收紧：必须真贴底 + 高度 40~96px + 宽度 >=60% 视口 + 可见 + 含 >=3 个可点子项
 *   ③ 偏移算法：只对「真贴底」的元素计算，杜绝悬浮球 / 横幅被当成很高的底栏
 *   ④ 重测时机：首屏 / resize / 转屏 / visualViewport / SPA 换页 / MutationObserver / 5s 兜底
 *   ⑤ 迟滞：差值 > 8px 才改，且连续 2 次采样一致才生效
 *   另外：显式排除播放器自身（它是 fixed + 贴底 + 一堆按钮，不排除就会被自己骗到）
 */
(function (MP) {

    var GUARD        = 8;      // 视觉间隙：播放器底边与宿主底栏之间至少留这么多
    var MIN_H        = 40;     // 底栏高度下限（低于此值多为分割线）
    var MAX_H        = 96;     // 底栏高度上限（高于此值多为整屏面板）
    var MIN_W_RATIO  = 0.6;    // 宽度至少占视口宽度的比例
    var MIN_ITEMS    = 3;      // 至少这么多可点子项，用来排除 cookie 横幅 / 单个悬浮球
    var SCAN_DEPTH   = 2;      // body 子元素向下最多再看两层
    var HYSTERESIS   = 8;      // 迟滞阈值
    var CONFIRM      = 2;      // 连续两次采样一致才生效
    var IDLE_SCAN_MS = 5000;   // 兜底复测间隔（仅页面可见时）
    var MAX_RATIO    = 0.5;    // 结果上限：不超过视口高度的一半

    var _value = 0;            // 歌词框应让出的底部像素（含 GUARD 与修正量）
    var _pvalue = 0;           // 播放器自身 UI 应让出的底部像素（可被 playerBottom 单独调）
    var _auto  = 0;            // 自动探测原始值（不含 GUARD / 修正量）
    var _next  = -1;           // 待确认的候选值
    var _hits  = 0;            // 候选值连续命中次数
    var _raf   = 0;            // 重测合并句柄
    var _timer = null;         // 兜底轮询
    var _mo    = null;         // MutationObserver
    var _cbs   = [];           // 值变化回调
    var _bound = false;        // 是否已绑定事件

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

    /* ── 是不是播放器自己的节点（它是 fixed + 贴底 + 一堆按钮，必须排除） ── */
    function isSelf(el) {
        var id = (el.id || '').toLowerCase();
        if (id && id.indexOf('mapi') >= 0) return true;
        if (el.getAttribute) {
            if (el.getAttribute('data-mp') !== null) return true;
            if (el.getAttribute('data-mapi') !== null) return true;
        }
        return el === MP.root || el === MP._root || el === MP.el || el === MP._el;
    }

    /* ── 单元素判定：它是不是「一条贴底的导航栏」 ── */
    function isBottomBar(el, vpH, vpW) {
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
        if (Math.abs(r.bottom - vpH) > 4) return 0;              // 必须真贴底
        if (r.height < MIN_H || r.height > MAX_H) return 0;       // 高度像一条栏
        if (r.width < vpW * MIN_W_RATIO) return 0;                // 宽度够宽
        var items = el.querySelectorAll('a,button,[role="tab"],[role="button"]');
        if (items.length < MIN_ITEMS) return 0;                   // 里面确实有几个可点子项

        return Math.round(r.height);
    }

    /* ── 扫描：body 直接子元素 + 深度 <= SCAN_DEPTH ── */
    function walk(el, vpH, vpW, depth) {
        if (isSelf(el)) return 0;                 // 播放器自身：整棵子树都不往下看
        var h = isBottomBar(el, vpH, vpW);
        if (h > 0) return h;                    // 命中即整体取值，避免把栏内部的行重复计算
        if (depth >= SCAN_DEPTH) return 0;
        var best = 0;
        var kids = el.children;
        for (var i = 0; i < kids.length; i++) {
            var v = walk(kids[i], vpH, vpW, depth + 1);
            if (v > best) best = v;
        }
        return best;
    }

    function scan() {
        var vpH = window.innerHeight || 0;
        var vpW = window.innerWidth || 0;
        if (!vpH || !vpW || !document.body) return 0;
        var roots = document.body.children;
        var best = 0;
        for (var i = 0; i < roots.length; i++) {
            var v = walk(roots[i], vpH, vpW, 0);
            if (v > best) best = v;
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
        } catch (e) { /* 忽略：非标准环境 */ }
    }

    function fire() {
        publish();
        for (var i = 0; i < _cbs.length; i++) {
            try { _cbs[i](_value); } catch (e) { /* 单个回调出错不影响其它 */ }
        }
    }

    /* ── 迟滞 + 连续确认 ── */
    function commit(force) {
        var raw = scan();
        _auto = raw;

        var cfg = domainCfg();
        var manual = 0;
        var auto = 1;
        if (cfg) {
            manual = num(cfg.lyricsBottom, 0);
            auto = num(cfg.auto, 1) ? 1 : 0;
        }
        var manualP = cfg ? num(cfg.playerBottom, manual) : 0;   // 没单独配就跟随歌词那份
        var want = Math.max(0, Math.round((auto ? raw : 0) + manual + GUARD));
        var wantP = Math.max(0, Math.round((auto ? raw : 0) + manualP + GUARD));
        // auto=0 且没有手动修正量时不要把已有值抹成 0
        if (!auto && !manual) want = _value;
        if (!auto && !manualP) wantP = _pvalue;

        if (force) {
            _next = -1; _hits = 0;
            var changed = (want !== _value) || (wantP !== _pvalue);
            _value = want; _pvalue = wantP;
            if (changed) fire();
            return _value;
        }
        if (want === _value) { _next = -1; _hits = 0; return _value; }
        if (Math.abs(want - _value) < HYSTERESIS) return _value;   // 小抖动直接忽略
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
                _mo.observe(document.body, { childList: true });
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
        if (!_bound) { bind(); commit(true); MP._bottomInset = _value; }
        return _value;
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

    MP._getPlayerInset = function () {
        if (!_bound) { bind(); commit(true); MP._playerInset = _pvalue; }
        return _pvalue;
    };

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