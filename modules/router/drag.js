(function(MP){
    if (!MP) return;
    var _ = MP._;

    function _isMobile() { return window.innerWidth <= 768; }
    function _mH() { return _isMobile() ? 4 : 15; }      // 水平安全边距
    function _mV() { return _isMobile() ? 8 : 12; }      // 垂直安全边距

    function _hasTop() {
        var t = MP._hostRoot.host.style.top;
        return !!t && t !== '' && t !== 'initial' && t !== 'auto';
    }

    // 实际可见范围：面板关着时只有圆形按钮；打开时是「按钮 ∪ 面板」。
    // 宿主盒子恒等于「面板 + 按钮」（面板即使 visibility:hidden 也占布局），
    // 旧版直接拿它做夹取 —— 所以按钮永远到不了屏幕顶部，拖到底部还会被切掉一截。
    // 面板用 offsetTop/offsetHeight 而不是 getBoundingClientRect：展开动画带 scale/translate，
    // 后者会读到动画中间态，算出来的夹取量是错的。
    function _visibleBox() {
        var h = MP._hostRoot.host;
        var tog = MP.$('toggle');
        var r = (tog || h).getBoundingClientRect();
        // 按钮还没 showToggle（display:none）时 rect 全为 0，退化为用宿主盒子判断
        if (!r.width && !r.height) r = h.getBoundingClientRect();
        var box = { left: r.left, top: r.top, right: r.right, bottom: r.bottom };
        if (MP.open) {
            var pnl = MP.$('panel');
            if (pnl && pnl.offsetHeight) {
                var root = MP.$('root');
                var offTop = pnl.offsetTop + (root ? root.offsetTop : 0);
                var offLeft = pnl.offsetLeft + (root ? root.offsetLeft : 0);
                var hr = h.getBoundingClientRect();
                box.top = Math.min(box.top, hr.top + offTop);
                box.bottom = Math.max(box.bottom, hr.top + offTop + pnl.offsetHeight);
                box.left = Math.min(box.left, hr.left + offLeft);
                box.right = Math.max(box.right, hr.left + offLeft + pnl.offsetWidth);
            }
        }
        return box;
    }

    MP.enableDrag = function() {
        var tog = MP.$('toggle'), ox = 0, oy = 0, _drag = false, _moved = false;
        var _sx = 0, _sy = 0, THRESH = 5, _actionHandled = false, _dragMinTop = -Infinity;
        function _start(cx, cy) {
            _drag = true; _moved = false; _sx = cx; _sy = cy;
            MP._hostRoot.host.style.transition = 'none'; _actionHandled = false;
            var r = MP._hostRoot.host.getBoundingClientRect(); ox = cx - r.left; oy = cy - r.top;
            _dragMinTop = -Infinity;
            // 面板向上浮出时，拖动过程中不允许把面板顶出屏幕上沿（顶部停靠时面板在下方，无此约束）
            if (MP.open && !MP._dockTop) {
                var pnl = MP.$('panel');
                if (pnl) {
                    var pr = pnl.getBoundingClientRect();
                    _dragMinTop = _mV() - (pr.top - r.top);
                }
            }
        }
        function _mv(e) {
            var dx = e.clientX - _sx, dy = e.clientY - _sy;
            if (dx * dx + dy * dy < THRESH * THRESH) return;
            _moved = true;
            MP._hostRoot.host.style.left = (e.clientX - ox) + 'px'; MP._hostRoot.host.style.right = 'auto';
            var ty = e.clientY - oy;
            if (ty < _dragMinTop) ty = _dragMinTop;
            MP._hostRoot.host.style.bottom = 'auto'; MP._hostRoot.host.style.top = ty + 'px';
            MP._posTopUser = ty + 'px';                               // 只记访客自己拖的竖直位置
        }
        // 松手：按落点决定停靠侧与停靠方向，再交给 _snap 统一贴边 / 夹取。
        // 停靠方向只在这里锁定一次 —— 之后面板开合把按钮挤着挪动，也不会反过来改变浮出方向。
        function _settle() {
            var r = tog.getBoundingClientRect();
            MP._posBottom = 0;      // 只有真正拖动过才放弃后台默认的底部锚点（点击不算）
            MP._side = (r.left + r.width / 2) < window.innerWidth / 2 ? 'left' : 'right';
            MP._dockTop = _shouldOpenDown(r.top);
            MP._snap();
            _drag = false;
        }
        function _up() {
            _actionHandled = true;
            document.removeEventListener('mousemove', _mv); document.removeEventListener('mouseup', _up);
            if (!_moved) { _drag = false; MP.togglePanel(); return; }
            _settle();
        }
        function _tm(e) {
            e.preventDefault(); var t = e.touches[0];
            if (!_moved) { var dx = t.clientX - _sx, dy = t.clientY - _sy; if (dx * dx + dy * dy < THRESH * THRESH) return; _moved = true; }
            MP._hostRoot.host.style.left = (t.clientX - ox) + 'px'; MP._hostRoot.host.style.right = 'auto';
            var ty = t.clientY - oy;
            if (ty < _dragMinTop) ty = _dragMinTop;
            MP._hostRoot.host.style.bottom = 'auto'; MP._hostRoot.host.style.top = ty + 'px';
            MP._posTopUser = ty + 'px';                               // 只记访客自己拖的竖直位置
        }
        function _te() {
            _actionHandled = true;
            tog.removeEventListener('touchmove', _tm); tog.removeEventListener('touchend', _te);
            if (!_moved) { _drag = false; MP.togglePanel(); return; }
            _settle();
        }
        tog.addEventListener('touchstart', function(e) {
            MP._cancelAutoHide();
            _start(e.touches[0].clientX, e.touches[0].clientY);
            tog.addEventListener('touchmove', _tm, {passive: false});
            tog.addEventListener('touchend', _te);
        });
        tog.addEventListener('mousedown', function(e) {
            if (e.button === 0 && !('ontouchstart' in window)) {
                // 必须阻止默认行为：按钮内含 <img>/<svg>，浏览器默认会发起「原生图片拖拽」，
                // 一旦拖拽会话开始，后续 mousemove 全部被吞掉，按钮就不再跟随光标 ——
                // 结果就是松手后停在第一次移动的位置，可能超出屏幕。
                e.preventDefault();
                MP._cancelAutoHide();
                _start(e.clientX, e.clientY);
                document.addEventListener('mousemove', _mv);
                document.addEventListener('mouseup', _up);
            }
        });
        tog.addEventListener('click', function(e) {
            if (!_moved && !_actionHandled) { _actionHandled = true; MP.togglePanel(); }
        });
        tog.addEventListener('mouseenter', function() { MP._cancelAutoHide(); });
        tog.addEventListener('mouseleave', function() { if (!MP.open) MP._scheduleAutoHide(); });
    };

    // ═══ 视口尺寸变化后重新贴边 ═══
    // 旧版没有 resize 处理：窗口缩到最小时会被挤到另一侧，再放大也回不来。
    MP.enableResizeGuard = function() {
        var timer = null;
        function onViewportChange() {
            clearTimeout(timer);
            timer = setTimeout(function() {
                if (!MP._hostRoot || !MP._hostRoot.host) return;
                MP._snap(true);
            }, 120);
        }
        window.addEventListener('resize', onViewportChange);
        window.addEventListener('orientationchange', onViewportChange);
        MP._onViewportChange = onViewportChange;
    };

    // 是否需要改为「向下浮出」：只有当按钮上方剩余空间**不足以显示面板的 1/3** 时才翻转。
    // 够 1/3 就维持原来的向上浮出（此时允许按钮被挤着往下挪，见 _snap 的夹取）。
    function _shouldOpenDown(btnTop) {
        if (_isMobile()) return false;                 // 移动端一律向上浮出
        var pnl = MP.$('panel');
        var hpH = pnl ? pnl.offsetHeight : 0;
        if (!hpH) return false;
        return (btnTop - _mV()) < hpH / 3;
    }

    // 按钮是否停在屏幕上半部分 —— 决定面板向上还是向下浮出（仅 PC；移动端一律向上）
    MP._dockFromGeometry = function() {
        if (_isMobile()) return false;
        var tog = MP.$('toggle');
        if (!tog) return false;
        var r = tog.getBoundingClientRect();
        if (!r.width && !r.height) return false;      // 按钮尚未显示：不判定为顶部停靠
        return _shouldOpenDown(r.top);
    };

    // 应用停靠方向：顶部停靠时按钮翻到宿主上沿、面板改为向下浮出（见 widget.js 里的 [data-dock="top"] 规则）
    MP._applyDock = function(dockTop) {
        var root = MP.$('root');
        if (root) {
            var want = dockTop ? 'top' : 'bottom';
            if (root.getAttribute('data-dock') !== want) root.setAttribute('data-dock', want);
        }
        var pnl = MP.$('panel');
        if (pnl) {
            // 面板展开动画的锚点跟着停靠角走：左上 / 右上 / 左下 / 右下
            pnl.style.transformOrigin = (dockTop ? 'top ' : 'bottom ') + (MP._side === 'left' ? 'left' : 'right');
        }
    };

    // instant = true 时不走补间动画（视口尺寸变化时用）
    MP._snap = function(instant) {
        var h = MP._hostRoot.host;
        var isMobile = _isMobile();
        var mh = _mH(), mv = _mV();
        var W = window.innerWidth, H = window.innerHeight;
        // 宿主底部的横向 tab 栏 / 吸底条高度：播放器不许压在它上面
        var insB = (typeof MP._getPlayerInset === 'function') ? (MP._getPlayerInset() || 0) : 0;
        if (insB < 0) insB = 0;

        var hasTop = _hasTop();
        // 停靠方向只对「顶部锚定」有意义；底部锚定（后台设置的高度百分比）永远向上浮出
        if (MP._dockTop === undefined) MP._dockTop = MP._dockFromGeometry();
        var dockTop = hasTop && !isMobile && MP._dockTop === true;
        var dockChanged = MP._dockApplied !== (dockTop ? 'top' : 'bottom');

        // 翻转前后按钮在屏幕上应保持不动：先记下它当前的纵坐标
        var togEl = MP.$('toggle');
        var togTopBefore = togEl ? togEl.getBoundingClientRect().top : null;

        // ═══ 测量阶段：必须关掉补间 ═══
        // 带着 transition 写 top 后立刻 getBoundingClientRect()，读到的是动画起始的旧位置，
        // 夹取会据此算出巨大偏移（表现为松手后播放器被推到屏幕底部）。
        h.style.transition = 'none';
        MP._applyDock(dockTop);

        var finalLeft, finalTop = null, finalBottom = null;

        // ── 垂直 ──
        if (hasTop) {
            var rect = h.getBoundingClientRect();
            var cur;
            if (dockChanged && togTopBefore !== null && togEl) {
                // 按钮从宿主下沿翻到上沿（或反之）后，用它翻转前的屏幕位置反推宿主 top，避免跳位
                var offTop = togEl.getBoundingClientRect().top - rect.top;
                cur = togTopBefore - offTop;
                MP._posTopUser = Math.round(cur) + 'px';   // 翻转后竖直锚点随之更新
            } else if (MP._posTopUser && !isNaN(parseFloat(MP._posTopUser))) {
                // 从访客自己拖到的位置出发：面板展开时的临时夹取不该变成新位置，收起后要能回到原处
                cur = parseFloat(MP._posTopUser);
            } else {
                cur = parseFloat(h.style.top);
                if (isNaN(cur)) cur = rect.top;
            }
            h.style.top = Math.round(cur) + 'px';
            h.style.bottom = 'auto';

            // 以「可见范围」夹进视口：超出下沿就上移，超出上沿就下移。
            // 夹取结果直接写成新的竖直锚点 —— 面板展开把按钮挤下去之后，收起面板不再回正。
            var box = _visibleBox();
            var dy = 0;
            var limB = H - mv - insB;
            if (box.bottom > limB) dy = limB - box.bottom;
            if (box.top + dy < mv) dy = mv - box.top;
            finalTop = Math.round(cur + dy);
            if (dy !== 0) MP._posTopUser = finalTop + 'px';
        } else {
            // 底部锚定：优先用后台设置的默认位置（_applyDefaultPos 已把底栏高度算进去），
            // 仅在可见范围越界时向上修正；没有默认位置时退回安全值 + 底栏高度
            var baseB = MP._posBottom ? MP._posBottom : ((isMobile ? 35 : 50) + insB);
            h.style.top = 'auto';
            h.style.bottom = baseB + 'px';
            var box2 = _visibleBox();
            var dy2 = 0;
            var limB2 = H - mv - insB;
            if (box2.bottom > limB2) dy2 = limB2 - box2.bottom;
            finalBottom = Math.round(dy2 < 0 ? baseB - dy2 : baseB);
        }

        // ── 水平：按已记录的停靠侧贴边。不再每次按几何重算 —— 那样窗口一窄就会被挤到另一侧且再也回不来 ──
        var hostW = h.getBoundingClientRect().width;
        finalLeft = Math.round(MP._side === 'left' ? mh : (W - hostW - mh));

        // ═══ 提交阶段：带补间，两轴一起动画到位 ═══
        h.style.transition = instant
            ? 'none'
            : 'left .35s cubic-bezier(.34,1.56,.64,1), top .35s cubic-bezier(.34,1.56,.64,1), bottom .35s cubic-bezier(.34,1.56,.64,1)';
        h.style.left = finalLeft + 'px';
        h.style.right = 'auto';
        if (finalTop !== null) {
            h.style.top = finalTop + 'px';
            h.style.bottom = 'auto';
        } else {
            h.style.bottom = finalBottom + 'px';
            h.style.top = 'auto';
        }

        var root = MP.$('root');
        if (root) root.style.alignItems = MP._side === 'left' ? 'flex-start' : 'flex-end';
        if (togEl) {
            togEl.style.left = MP._side === 'left' ? '' : 'auto';
            togEl.style.right = MP._side === 'left' ? 'auto' : '';
        }
        MP._applyDock(dockTop);                 // _side 定稿后重算一次面板展开锚点
        MP._updateToggleTransform();
        MP._dockApplied = dockTop ? 'top' : 'bottom';
        clearTimeout(MP._snapTmr);
        MP._snapTmr = setTimeout(function(){ h.style.transition = 'none'; }, 400);
        if (!MP.open) MP._scheduleAutoHide();
        MP.saveState();
    };

    // 宿主底栏高度变化时（自动探测到 / 后台改了修正量 / 转屏 / SPA 换页）把播放器重新摆一次
    if (typeof MP._onBottomInset === 'function') {
        MP._onBottomInset(function () {
            if (MP._destroyed) return;
            if (typeof MP._applyDefaultPos === 'function') MP._applyDefaultPos(true);
            if (typeof MP._snap === 'function') MP._snap(true);
        });
    }

    MP.setPosition = function(pos) {
        var h = MP._hostRoot.host;
        MP._side = pos === 'left' ? 'left' : 'right';
        MP._dockTop = false;                       // 后台设置的位置一律底部停靠
        h.style.left = MP._side === 'left' ? _mH() + 'px' : 'auto';
        h.style.right = MP._side === 'right' ? _mH() + 'px' : 'auto';
        var root = MP.$('root');
        if (root) root.style.alignItems = MP._side === 'left' ? 'flex-start' : 'flex-end';
        var tog = MP.$('toggle');
        if (tog) {
            tog.style.left = MP._side === 'left' ? '' : 'auto';
            tog.style.right = MP._side === 'left' ? 'auto' : '';
        }
        MP._applyDock(false);
        MP._updateToggleTransform();
        MP._scheduleAutoHide();
    };

})(window.__mapiPlayer);
