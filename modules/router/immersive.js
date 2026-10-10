(function(MP){
    if (!MP) return;
    var _ = MP._;

    // 沉浸模式要临时锁住宿主页滚动；退出时必须还原宿主原本的值。
    // 一律清成 '' 会抹掉主题写在 body / documentElement 上的内联 overflow。
    var _prevOverflow = null;
    function _lockHostScroll() {
        if (_prevOverflow === null) {
            _prevOverflow = {
                body: document.body.style.overflow,
                html: document.documentElement.style.overflow
            };
        }
        document.body.style.overflow = 'hidden';
        document.documentElement.style.overflow = 'hidden';
    }
    function _restoreHostScroll() {
        if (_prevOverflow === null) return;
        document.body.style.overflow = _prevOverflow.body;
        document.documentElement.style.overflow = _prevOverflow.html;
        _prevOverflow = null;
    }

    MP.toggleImmersive = function() {
        var ov = MP.$('immersiveOverlay');
        var tog = MP.$('toggle');
        if (!ov) return;
        var btn = MP.$('immersiveBtn');
        if (btn) {
            var br = btn.getBoundingClientRect();
            ov.style.transformOrigin = (br.left + br.width/2) + 'px ' + (br.top + br.height/2) + 'px';
        }
        MP._imOpen = !MP._imOpen;
        if (MP._imOpen) {
            ov.classList.add('open');
            if (tog) { tog.style.opacity = '0'; tog.style.pointerEvents = 'none'; }
            _lockHostScroll();
            if (!ov._touchHandler) {
                ov._touchHandler = function(e){
                    var t = e.target;
                    if (t && t.closest && t.closest('[data-mp="imSlist"]')) return;
                    e.preventDefault();
                };
                ov.addEventListener('touchmove', ov._touchHandler, {passive: false});
            }
            var lrcPill = document.querySelector('[data-mp="lrc"]');
            if (lrcPill) lrcPill.style.display = 'none';
            MP.updateImmersiveUI(); MP.updateImmersivePlayBtn(!MP.ap || !MP.ap.audio || MP.ap.audio.paused ? false : true);
            MP.updateImmersiveLrc();
            MP.updateImmersiveModeBtn();
            var imVol = MP.$('imVol');
            if (imVol && MP.ap && MP.ap.audio) imVol.value = MP.ap.audio.volume;
            MP.renderSonglist();
        } else {
            ov.classList.remove('open');
            if (tog) { tog.style.opacity = ''; tog.style.pointerEvents = ''; }
            _restoreHostScroll();
            if (ov._touchHandler) {
                ov.removeEventListener('touchmove', ov._touchHandler);
                ov._touchHandler = null;
            }
            var lrcPill2 = document.querySelector('[data-mp="lrc"]');
            if (lrcPill2) lrcPill2.style.display = '';
            // 藏起来这段时间引擎不量宽度（量了会把歌词条写成 42px 的小格），
            // 显示回来必须立刻按当前行重排 —— 暂停时没有 timeupdate 可以等。
            if (typeof MP.refreshLrc === 'function') MP.refreshLrc();
        }
    };

    MP.closeImmersive = function() {
        var ov = MP.$('immersiveOverlay');
        var tog = MP.$('toggle');
        if (!ov) return;
        var btn = MP.$('immersiveBtn');
        if (btn) {
            var br = btn.getBoundingClientRect();
            ov.style.transformOrigin = (br.left + br.width/2) + 'px ' + (br.top + br.height/2) + 'px';
        }
        ov.classList.remove('open');
        MP._imOpen = false;
        if (tog) { tog.style.opacity = ''; tog.style.pointerEvents = ''; }
        var lrcPill = document.querySelector('[data-mp="lrc"]');
        if (lrcPill) lrcPill.style.display = '';
        // 同 toggleImmersive 的退出分支：显示回来立刻重排歌词条宽度
        if (typeof MP.refreshLrc === 'function') MP.refreshLrc();
        _restoreHostScroll();
        if (ov._touchHandler) {
            ov.removeEventListener('touchmove', ov._touchHandler);
            ov._touchHandler = null;
        }
    };

    MP.updateImmersiveUI = function() {
        if (!MP.ap || !MP.ap.list) return;
        var idx = MP.ap.list.index;
        var info = MP.ap.list.audios[idx];
        var titleEl = MP.$('imTitle');
        var artistEl = MP.$('imArtist');
        var coverEl = MP.$('imCover');
        if (titleEl) titleEl.textContent = info ? info.name : '\u672a\u77e5';
        if (artistEl) artistEl.textContent = info ? (info.artist || '') : '';
        if (coverEl) { coverEl.src = info && info.cover ? info.cover : ''; }
        MP.updateImmersiveProgress();
    };

    MP.updateImmersivePlayBtn = function(playing) {
        var svg = MP.$('imPlaySvg');
        if (!svg) return;
        svg.innerHTML = playing ? '<rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>' : '<polygon points="6,4 20,12 6,20"/>';
    };

    MP.updateImmersiveProgress = function() {
        if (!MP.ap || !MP.ap.audio) return;
        var a = MP.ap.audio;
        var cur = a.currentTime||0, dur = a.duration||0;
        var pct = dur > 0 ? (cur/dur*100) : 0;
        var pfill = MP.$('imPfill');
        if (pfill) pfill.style.width = pct + '%';
        var curEl = MP.$('imCur');
        var durEl = MP.$('imDur');
        if (curEl) curEl.textContent = _.fmt(cur);
        if (durEl) durEl.textContent = dur ? _.fmt(dur) : '00:00';
    };

    MP.updateImmersiveModeBtn = function() {
        var svg = MP.$('imModeSvg');
        if (!svg) return;
        var icons = {
            list: '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
            single: '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/><text x="12" y="15" text-anchor="middle" font-size="10" font-weight="700">1</text>',
            random: '<polyline points="16 3 21 7 16 11"/><polyline points="8 13 3 17 8 21"/><line x1="3" y1="3" x2="21" y2="21"/>'
        };
        svg.innerHTML = icons[MP.mode] || icons.list;
    };

    // 歌词字体（后台「音乐配置」里设的）。字号只有后台确实设了才覆盖（返回 0 = 没设，
    // 保留皮肤自己的响应式字号），当前行比其它行大 2px —— 和皮肤原本 14 / 16 的字号节奏一致。
    //
    // 字体家族必须写到每一行上，不能只写容器：皮肤自带的 MP._css 里有一条
    //   *{…font-family:-apple-system,"PingFang SC","Microsoft YaHei","Noto Sans SC",sans-serif}
    // 它是注入在 shadow root 里的通配规则，等于把字体「直接声明」在了每个元素上 —— 元素自己
    // 有声明的字体时，父级的字体根本不会被继承（继承值只在该属性没有任何声明时才生效），
    // 所以只设容器的话沉浸歌词一辈子都是那条通配规则的字体（实测行元素 computed
    // font-family 一直是 -apple-system…，与此前「沉浸式字体不生效」的反馈完全一致）。
    // 悬浮歌词条在 light DOM 里、没有这条通配规则，所以只设它自己就够了。
    function _applyLrcFont(container) {
        if (!container || typeof MP.lrcFontFamilyCss !== 'function') return;
        var fam = MP.lrcFontFamilyCss();
        if (container.style.fontFamily !== fam) container.style.fontFamily = fam;
        var size = (typeof MP.lrcFontSize === 'function') ? MP.lrcFontSize() : 0;
        for (var i = 0; i < container.children.length; i++) {
            var el = container.children[i];
            if (!el || !el.style) continue;
            if (el.style.fontFamily !== fam) el.style.fontFamily = fam;
            var isActive = (' ' + el.className + ' ').indexOf(' active ') >= 0;
            var want = size > 0 ? (size + (isActive ? 2 : 0)) + 'px' : '';
            if (el.style.fontSize !== want) el.style.fontSize = want;   // 每次 timeupdate 都会调到这里，值没变就别写
        }
    }

    // 引擎在字体设置变化时调用（皮肤可选实现）
    MP.onLrcFontChange = function() {
        _applyLrcFont(MP.$('imLrc'));
    };

    MP.updateImmersiveLrc = function() {
        var container = MP.$('imLrc');
        if (!container) return;
        var wrap = container.parentElement;
        if (!MP.lrcLines || MP.lrcLines.length === 0) {
            try {
                var _a = MP.ap.list.audios[MP.ap.list.index];
                if (_a && _a._lrc && _a._lrc.indexOf('\u6b64\u6b4c\u66f2\u4e3a\u6ca1\u6709\u586b\u8bcd\u7684\u7eaf\u97f3\u4e50') >= 0) {
                    if (wrap) wrap.style.display = '';
                    container.innerHTML = '<div class="im-lrc-line active">\u6b64\u6b4c\u66f2\u4e3a\u6ca1\u6709\u586b\u8bcd\u7684\u7eaf\u97f3\u4e50\uff0c\u8bf7\u60a8\u6b23\u8d4f</div>';
                    container._lrcSig = '__placeholder__';
                    _applyLrcFont(container);
                    return;
                }
            } catch(e) {}
            container.innerHTML = '';
            container._lrcSig = '';
            if (wrap) wrap.style.display = 'none';
            return;
        }
        if (wrap) wrap.style.display = '';
        var ct = MP.ap ? MP.ap.audio.currentTime : 0;
        var activeIdx = -1;
        for (var i = MP.lrcLines.length - 1; i >= 0; i--) {
            if (ct >= MP.lrcLines[i].time) { activeIdx = i; break; }
        }
        if (activeIdx < 0 && MP.lrcLines.length > 0) activeIdx = 0;

        // 换歌后必须重建 DOM: 只比较行数时, 两首歌行数相同就会残留上一首的歌词.
        var _sig = MP.lrcLines.length + '|' + MP.lrcLines[0].text + '|' + MP.lrcLines[MP.lrcLines.length - 1].text;
        if (container.children.length !== MP.lrcLines.length || container._lrcSig !== _sig) {
            var html = '';
            for (var j = 0; j < MP.lrcLines.length; j++) {
                html += '<div class="im-lrc-line">' + _.escapeHtml(MP.lrcLines[j].text) + '</div>';
            }
            container.innerHTML = html;
            container._lrcSig = _sig;
        }

        for (var k = 0; k < container.children.length; k++) {
            var cl = 'im-lrc-line';
            if (k === activeIdx) cl += ' active';
            else if (k === activeIdx - 1) cl += ' prev';
            container.children[k].className = cl;
        }
        // 先上字体再算滚动位置：行高会随字号变，位置必须按最终行高来
        _applyLrcFont(container);

        if (activeIdx >= 0) {
            var activeEl = container.children[activeIdx];
            if (activeEl) {
                var wrapH = wrap.offsetHeight || 150;
                var lineH = activeEl.offsetHeight || 30;
                var offset = activeEl.offsetTop - wrapH / 2 + lineH / 2;
                container.style.transform = 'translateY(-' + Math.max(0, offset) + 'px)';
            }
        }
    };

})(window.__mapiPlayer);