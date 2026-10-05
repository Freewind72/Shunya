(function(MP){
    if (!MP) return;
    var _ = MP._;

    // 底部占用变化时实时跟随（订阅一次就够了；探测与迟滞都在 core/inset.js 里）
    if (typeof MP._onBottomInset === 'function') {
        MP._onBottomInset(function (px) {
            var el = document.querySelector('[data-mp="lrc"]');
            if (el) el.style.bottom = px + 'px';
        });
    }

    MP.loadLrc = function() {
        if (!MP.ap || !MP.ap.list) return;
        var idx = MP.ap.list.index;
        var audio = MP.ap.list.audios[idx];
        if (!audio) return;
        if (audio._lrc) { MP.parseLrc(audio._lrc); return; }
        MP.lrcLines = [];
    };

    MP.parseLrc = function(str) {
        MP.lrcLines = [];
        if (!str) return;
        if (str.trim().charAt(0) === '{') {
            try {
                var obj = JSON.parse(str);
                str = obj.lyric || (obj.lrc && obj.lrc.lyric) || str;
            } catch(e) {}
        }
        var lines = str.split('\n');
        for (var i = 0; i < lines.length; i++) {
            var m = lines[i].match(/\[(\d{2}):(\d{2})(?:[:.](\d+))?\](.*)/);
            if (m) {
                var t = parseInt(m[1])*60 + parseInt(m[2]) + parseInt((m[3]||'0').substring(0,3))/1000;
                var txt = (m[4]||'').trim();
                if (txt) {
                    MP.lrcLines.push({time:t, text:txt});
                }
            }
        }
        MP.lrcLines.sort(function(a,b){return a.time-b.time;});
    };

    MP.syncLrc = function() {
        var PLACEHOLDER = '\u6b64\u6b4c\u66f2\u4e3a\u6ca1\u6709\u586b\u8bcd\u7684\u7eaf\u97f3\u4e50\uff0c\u8bf7\u60a8\u6b23\u8d4f';
        if (MP.lrcLines.length === 1 && MP.lrcLines[0].text === PLACEHOLDER) {
            MP.lrcLines = [];
            var lrcEl = document.querySelector('[data-mp="lrc"]');
            if (lrcEl) lrcEl.style.opacity = '0';
            return;
        }
        var ct = MP.ap ? MP.ap.audio.currentTime : 0;
        var txt = '';
        var hasLrc = MP.lrcLines.length >= 5;
        if (!hasLrc && MP.lrcLines.length > 0) { MP.lrcLines = []; }
        if (hasLrc) {
            var _idx = -1;
            for (var i = MP.lrcLines.length - 1; i >= 0; i--) {
                if (ct >= MP.lrcLines[i].time) { txt = MP.lrcLines[i].text; _idx = i; break; }
            }
            if (!txt && ct < 1 && MP.lrcLines.length > 0) {
                txt = MP.lrcLines[0].text; _idx = 0;
            }
        }
        var isMobile = window.innerWidth <= 768;
        var lrcEl = document.querySelector('[data-mp="lrc"]');
        if (!lrcEl) {
            lrcEl = document.createElement('div');
            lrcEl.setAttribute('data-mp', 'lrc');
            // 底部占用统一由 core/inset.js 探测（已排除悬浮球/cookie 横幅与播放器自身）
            var bottomPx = (typeof MP._getOverlapBottom === 'function') ? MP._getOverlapBottom() : 8;
            var isDark = MP.$('root') && MP.$('root').classList.contains('dark');
            var baseStyle = 'position:fixed;bottom:' + bottomPx + 'px;left:50%;transform:translateX(-50%);z-index:2147483646;font-size:15px;font-weight:700;white-space:nowrap;pointer-events:none'
                + (isDark ? ';color:#d0d0d8;background:rgba(55,55,68,.65);backdrop-filter:blur(16px)saturate(200%);padding:6px 20px;border-radius:20px;border:1px solid rgba(255,255,255,.06)' : ';color:#1a1a2e;background:rgba(255,255,255,.3);backdrop-filter:blur(16px)saturate(200%);padding:6px 20px;border-radius:20px;border:1px solid rgba(255,255,255,.5)');
            lrcEl.style.cssText = baseStyle + (isMobile ? ';max-width:calc(100vw - 32px);overflow:hidden' : '');
            var span = document.createElement('span');
            lrcEl.appendChild(span);
            document.body.appendChild(lrcEl);
            MP._lastLrcW = 0;
            var btn = MP.$('lrcToggle');
            if (btn) btn.classList.toggle('active', MP._showLrc);
            if (!MP._showLrc) { lrcEl.style.overflow = 'hidden'; lrcEl.style.width = '0'; lrcEl.style.padding = '6px 0'; lrcEl.style.opacity = '0'; }
        }
        var span = lrcEl.firstElementChild;
        if (!span) { span = document.createElement('span'); lrcEl.appendChild(span); }
        span.style.display = 'inline-block';

        if (MP.ap && !MP.ap.audio.paused) {
            if (!hasLrc && txt === '') { txt = PLACEHOLDER; }
            if (txt) {
                // 只有【真的换行】才重排跑马灯。
                // 这个函数挂在 timeupdate 上（约 4 次/秒），以前每次都把 transform 归零、
                // 再重启 transition —— 等于每秒把文字拽回起点 4 次，移动端看着就是来回抽搐。
                var lineChanged = (txt !== MP._lrcTxt);
                if (lineChanged) { MP._lrcTxt = txt; span.textContent = txt; }
                if (!MP._showLrc || MP._lrcAnimating) return;
                lrcEl.style.opacity = '1';
                if (!lineChanged) return;          // 文本没变：什么都不做，让动画安静地跑完

                MP._lrcRestartCount = (MP._lrcRestartCount || 0) + 1;   // 诊断：真正触发的重排次数
                var newW = span.scrollWidth + 42;
                if (isMobile) newW = Math.min(newW, window.innerWidth - 32);
                var oldW = MP._lastLrcW || newW;
                lrcEl.style.transition = 'width .35s cubic-bezier(.4,0,.2,1),opacity .3s';
                lrcEl.style.width = oldW + 'px';
                void lrcEl.offsetWidth;
                lrcEl.style.width = newW + 'px';
                MP._lastLrcW = newW;

                if (isMobile) {
                    span.style.transition = 'none';
                    span.style.transform = 'translateX(0)';
                    void span.offsetWidth;
                    var boxW = newW - 42;
                    if (span.scrollWidth > boxW) {
                        var overflow = span.scrollWidth - boxW;
                        // 匀速滚动：按固定速度算时长，不再用「本行剩余时间」——
                        // 那会让每行速度快慢不一，看着忽快忽慢。
                        var dur = Math.min(30, Math.max(4, Math.round(overflow / 28)));
                        requestAnimationFrame(function(){
                            span.style.transition = 'transform ' + dur + 's linear';
                            span.style.transform = 'translateX(-' + overflow + 'px)';
                        });
                    }
                }
                return;
            }
        }
        lrcEl.style.opacity = '0';
        lrcEl.style.width = '';
        lrcEl.style.transition = 'opacity .3s';
    };

    MP.toggleLrc = function() {
        MP._showLrc = !MP._showLrc;
        var btn = MP.$('lrcToggle');
        if (btn) btn.classList.toggle('active', MP._showLrc);
        var lrcEl = document.querySelector('[data-mp="lrc"]');
        if (!lrcEl) { MP.saveState(); return; }
        if (MP._showLrc) {
            var span = lrcEl.firstElementChild;
            if (!span) { lrcEl.style.display = ''; MP.saveState(); return; }
            var txt = span.textContent || '';
            if (!txt.trim()) { MP.saveState(); return; }
            span.style.display = 'inline-block';
            lrcEl.style.transition = 'none';
            lrcEl.style.width = '0';
            lrcEl.style.padding = '6px 0';
            lrcEl.style.height = '';
            lrcEl.style.overflow = 'hidden';
            lrcEl.style.opacity = '0';
            void lrcEl.offsetWidth;
            var fullW = span.scrollWidth + 40;
            lrcEl.style.transition = 'width .7s cubic-bezier(.4,0,.2,1),padding .7s cubic-bezier(.4,0,.2,1),opacity .5s';
            lrcEl.style.width = fullW + 'px';
            lrcEl.style.padding = '6px 20px';
            lrcEl.style.opacity = '1';
            MP._lrcAnimating = true;
            clearTimeout(MP._lrcAnimTmr);
            MP._lrcAnimTmr = setTimeout(function(){ MP._lrcAnimating = false; }, 800);
        } else {
            var curW = lrcEl.offsetWidth;
            if (curW <= 1) { lrcEl.style.display = 'none'; MP.saveState(); return; }
            lrcEl.style.overflow = 'hidden';
            lrcEl.style.transition = 'width .6s cubic-bezier(.4,0,.2,1),padding .6s cubic-bezier(.4,0,.2,1),opacity .45s';
            lrcEl.style.width = '0';
            lrcEl.style.padding = '6px 0';
            lrcEl.style.opacity = '0';
            MP._lrcAnimating = true;
            clearTimeout(MP._lrcAnimTmr);
            MP._lrcAnimTmr = setTimeout(function(){ MP._lrcAnimating = false; }, 700);
        }
        MP.saveState();
    };

    MP.applyLrcDefault = function(val) {
        if (val === 0) {
            MP._showLrc = false;
            var btn = MP.$('lrcToggle');
            if (btn) btn.classList.remove('active');
            var lrcEl = document.querySelector('[data-mp="lrc"]');
            if (lrcEl) {
                lrcEl.style.overflow = 'hidden';
                lrcEl.style.width = '0';
                lrcEl.style.padding = '6px 0';
                lrcEl.style.opacity = '0';
            }
        }
    };


})(window.__mapiPlayer);