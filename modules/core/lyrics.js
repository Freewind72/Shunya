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
            // 纯音乐占位行：丢掉这一行交给下面的「无歌词」分支显示占位文案。
            // 以前这里顺手把歌词条 opacity 归零，但下一拍就会显示占位文案 —— 只是白闪一下。
            MP.lrcLines = [];
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
            if (!txt && MP.lrcLines.length > 0) {
                // 还没唱到第一行（前奏）也不许藏：先亮第一行，与旧版 ct < 1 时的行为一致。
                // 否则前奏这几秒里歌词条是「自己消失」的，而能关掉它的只该是歌词开关。
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
            MP._applyLrcFontStyles(lrcEl);   // 后台设置的字体（配置还没到时就是默认）
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

        // 显隐只认歌词开关（MP._showLrc）——「是否正在播放」不参与判定，这个函数里也
        // 不再有任何隐藏歌词条的路径。以前这里套着 if (MP.ap && !MP.ap.audio.paused)：
        // 一旦不是播放中（暂停、拖进度条、缓冲暂停）就直接掉到最下面把 opacity 归零，
        // 歌词条整条淡出 —— 等于「暂停 = 关闭」。现在暂停只是定格在当前这一行，
        // 能关掉歌词条的只有歌词开关（MP.toggleLrc）。
        var playing = !!(MP.ap && MP.ap.audio && !MP.ap.audio.paused);
        if (!hasLrc && txt === '') { txt = PLACEHOLDER; }   // 没有歌词：显示占位文案
        // 正常路径下 txt 到这里必然非空：要么是上面取的「最后一个已到时间的行」（间奏期间它
        // 自己就留着上一行，不需要额外兜底），要么是前奏时兜底的第一行，要么是占位文案。
        // 万一真的为空，也只是保持现状，绝不去关掉歌词条。
        if (txt) {
            // 只有【真的换行】才重排跑马灯。
            // 这个函数挂在 timeupdate 上（约 4 次/秒），以前每次都把 transform 归零、
            // 再重启 transition —— 等于每秒把文字拽回起点 4 次，移动端看着就是来回抽搐。
            var lineChanged = (txt !== MP._lrcTxt);
            if (lineChanged) { MP._lrcTxt = txt; span.textContent = txt; }
            if (!MP._showLrc || MP._lrcAnimating) return;
            // 皮肤可能把歌词条整条藏起来（沉浸模式就是 display:none）。元素不在布局里时
            // 量不到宽度（scrollWidth 全是 0），一旦写进 style 就是 42px 这种垃圾值 ——
            // 退出沉浸后只要这一句还没唱完，歌词条就会一直挤成小格，只有换歌才会恢复。
            // 所以隐藏期间只更新文本、不动宽度，等皮肤把它显示回来时调 MP.refreshLrc() 重排。
            if (!lrcEl.getClientRects().length) { MP._lrcWStale = true; return; }
            var wasStale = MP._lrcWStale === true;   // 隐藏期间攒下的重排请求
            MP._lrcWStale = false;
            lrcEl.style.opacity = '1';
            if (!lineChanged && !wasStale) return;   // 文本没变：什么都不做，让动画安静地跑完

            MP._lrcRestartCount = (MP._lrcRestartCount || 0) + 1;   // 诊断：真正触发的重排次数
            var newW = span.scrollWidth + 42;
            if (isMobile) newW = Math.min(newW, window.innerWidth - 32);
            var oldW = MP._lastLrcW || newW;
            lrcEl.style.transition = 'width .35s cubic-bezier(.4,0,.2,1),opacity .3s';
            lrcEl.style.width = oldW + 'px';
            void lrcEl.offsetWidth;
            lrcEl.style.width = newW + 'px';
            MP._lastLrcW = newW;

            if (isMobile && playing) {          // 暂停时不启动跑马灯：定格，不滚动
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
        // 理论上到不了这里（占位文案 / 前奏首行两层兜底都在上面）。以前这里是「隐藏」的
        // 兜底：opacity 归零 + 收宽度。现在显隐只归歌词开关管，所以 syncLrc 里不再有任何
        // 隐藏歌词条的代码路径 —— 没有可显示的内容时就保持现状。
    };

    // 皮肤把歌词条重新显示出来之后必须调一次（沉浸模式退出的两条路径都调了）：
    // 隐藏期间 syncLrc 不量宽度，这里强制按当前行重排。不能等下一次 timeupdate ——
    // 暂停时根本没有 timeupdate，歌词条会一直保持隐藏期间的宽度。
    MP.refreshLrc = function() {
        MP._lrcWStale = true;
        MP.syncLrc();
    };

    // ═══ 歌词条字体（后台「音乐配置」里设置）═══
    // 两种来源：字体文件（.woff2/.woff/.ttf/.otf，直接作为 @font-face 的 src）
    // 与 CSS 链接（.css，里面自己写 @font-face，这里用 @import 引进来）。
    // 引擎只认「URL + 字体名 + 字号」，不关心是上传到存储的还是外链的。
    MP._LRC_FONT_FALLBACK = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,'PingFang SC','Microsoft YaHei',sans-serif";

    MP._lrcFontIsCss = function(url) { return /\.css(\?|#|$)/i.test(String(url || '')); };

    // 写进 CSS 前做最小安全化：这两个值来自后台配置，不能让它截断规则或塞进别的声明
    MP._lrcFontSafeUrl = function(url) { return String(url || '').replace(/[\\'"()<>]/g, '').trim(); };
    MP._lrcFontSafeName = function(name) { return String(name || '').replace(/[^A-Za-z0-9 _\-\u4e00-\u9fa5]/g, '').trim(); };

    // 文件模式用字体名（缺省 MsapiLrcFont）；CSS 模式只能靠用户填的字体名
    MP._lrcFontFamily = function(url, name) {
        var n = MP._lrcFontSafeName(name);
        if (n) return n;
        return (url && !MP._lrcFontIsCss(url)) ? 'MsapiLrcFont' : '';
    };

    // 皮肤（沉浸式歌词等）用的公开接口：现成的 font-family 值；字号 0 = 皮肤用自己的默认字号。
    // 皮肤不要直接读 MP._lrcFont —— 那里是引擎内部状态。
    MP.lrcFontFamilyCss = function() {
        var f = MP._lrcFont || {};
        var fam = MP._lrcFontFamily(f.url, f.name);
        return fam ? ("'" + fam + "'," + MP._LRC_FONT_FALLBACK) : '';
    };

    MP.lrcFontSize = function() {
        var s = parseInt((MP._lrcFont || {}).size, 10);
        return (s > 0 && s <= 200) ? s : 0;
    };

    MP._applyLrcFontStyles = function(el) {
        if (!el) return;
        el.style.fontFamily = MP.lrcFontFamilyCss();
        var size = MP.lrcFontSize();
        el.style.fontSize = (size > 0 ? size : 15) + 'px';   // 悬浮歌词条没设字号时就是原来的 15px
    };

    MP.applyLrcFont = function(cfg) {
        cfg = cfg || {};
        var url = MP._lrcFontSafeUrl(cfg.url);
        var name = MP._lrcFontSafeName(cfg.name);
        var size = parseInt(cfg.size, 10);
        if (!(size > 0) || size > 200) size = 0;
        var sig = url + '|' + name + '|' + size;
        MP._lrcFont = { url: url, name: name, size: size };
        // 同一份配置会被下发好几次（首屏内联 / XHR 拉取 / postMessage 推送），没必要反复重写样式元素
        if (MP._lrcFontSig !== sig) {
            MP._lrcFontSig = sig;
            var st = document.getElementById('mapi-lrc-font');
            if (!url) {
                if (st && st.parentNode) st.parentNode.removeChild(st);
            } else {
                if (!st) {
                    st = document.createElement('style');
                    st.id = 'mapi-lrc-font';
                    (document.head || document.documentElement).appendChild(st);
                }
                st.textContent = MP._lrcFontIsCss(url)
                    ? '@import url("' + url + '");'
                    : "@font-face{font-family:'" + (MP._lrcFontFamily(url, name) || 'MsapiLrcFont') + "';src:url('" + url + "');font-display:swap}";
            }
        }
        MP._applyLrcFontStyles(document.querySelector('[data-mp="lrc"]'));
        // 皮肤自己也画歌词的（沉浸式歌词）在这里跟上来；皮肤没实现这个钩子就什么都不做
        if (typeof MP.onLrcFontChange === 'function') { try { MP.onLrcFontChange(); } catch (e) {} }
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