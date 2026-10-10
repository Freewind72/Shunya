(function(MP){
    if (!MP) return;
    var _ = MP._;

    MP.showToggle = function() {
        var el = MP.$('toggle');
        if (el) { el.style.display = 'flex'; MP._updateToggleTransform(); }
    };

    MP._updateToggleTransform = function() {
        var tog = MP.$('toggle');
        if (!tog) return;
        if (!MP._retracted) { tog.style.transform = ''; return; }
        // 收起时按像素推，而不是按自身宽度的百分比：
        // 静止状态按钮距墙 mr，再往墙里推 (mr + 半个身位) → 屏幕外正好藏住一半（2/4），
        // 旧版写死 translateX(70%) 只藏了 2/5，且边距一变露出的比例还会跟着变。
        var w = tog.offsetWidth || 48;
        var mr = window.innerWidth <= 768 ? 4 : 15;
        var shift = mr + w / 2;
        var dir = MP._side === 'left' ? -1 : 1;
        tog.style.transform = 'translateX(' + (dir * shift) + 'px)';
    };

    // 悬浮按钮的自动收起 (吸附进侧边) : 平时移开鼠标 3 秒收起;
    MP._autoHideDelay = 3000;
    MP._postLoadHideDelay = (typeof window.__mapiToggleHideDelay === 'number' && window.__mapiToggleHideDelay >= 0)
        ? window.__mapiToggleHideDelay : 10000;

    MP._scheduleAutoHide = function(delayMs) {
        clearTimeout(MP._retractTimer);
        MP._retracted = false;
        MP._updateToggleTransform();
        var wait = (typeof delayMs === 'number') ? delayMs : MP._autoHideDelay;
        MP._retractTimer = setTimeout(function() {
            if (MP._loading) {                       // 加载中不收起（呼吸动效期间保持完整可见）
                MP._scheduleAutoHide(MP._autoHideDelay);
                return;
            }
            MP._retracted = true;
            MP._updateToggleTransform();
        }, wait);
    };

    MP._cancelAutoHide = function() {
        clearTimeout(MP._retractTimer);
        MP._retracted = false;
        var tog = MP.$('toggle');
        if (tog) tog.style.transform = '';
    };

    // ═══ 统一提示：嵌在宿主页里的浮层，样式自带（不依赖宿主 CSS / 主题） ═══
    // 起因：以前只有「缺 key」「后台没配歌单」两条文案，而且所有失败都往后者上靠 ——
    // 密钥失效、限流、服务器不可达、域名未授权、CDN 挂了，要么完全静默，要么说错话。
    // 现在按「原因码」给每种失败一条独立文案，统一渲染、同码去重、点一下可关掉。
    MP._noticeDefs = {
        no_key:          { level: 'error', title: '缺少 API Key',       detail: '嵌入代码里的 key 没填或为空' },
        invalid_key:     { level: 'error', title: '密钥无效',           detail: '这个密钥不存在，或已被删除' },
        key_disabled:    { level: 'error', title: '密钥已被停用',       detail: '到后台「密钥」页把它重新启用' },
        rate_limited:    { level: 'warn',  title: '请求太频繁',         detail: '限流保护已触发，等一分钟再刷新' },
        offline:         { level: 'error', title: '连不上服务器',       detail: '网络或服务不可达，检查后刷新页面' },
        server_error:    { level: 'error', title: '服务器返回异常',     detail: '稍后重试，或到后台看看接口日志' },
        domain_blocked:  { level: 'error', title: '这个域名没有授权',   detail: '到后台「域名」页添加它，或打开「自动添加检测到的域名」' },
        key_domain:      { level: 'error', title: '这个密钥不能在这里使用', detail: '这条密钥绑定了授权域名，请换用对应域名的密钥' },
        no_playlist:     { level: 'warn',  title: '后台还没有歌单',     detail: '到后台「配置」页添加歌单后刷新' },
        no_songs:        { level: 'warn',  title: '没有可播放的歌曲',   detail: '歌单是空的，或接口没返回数据' },
        playlist_failed: { level: 'warn',  title: '歌单加载失败',       detail: '点悬浮按钮重试，或检查后台的接口配置' },
        cdn_failed:      { level: 'error', title: '播放器资源加载失败', detail: 'CDN 不可达，刷新页面重试' },
    };
    MP._noticeColors = {
        error: { bg: 'rgba(229,72,77,.13)',  bd: 'rgba(229,72,77,.34)',  fg: '#e5484d' },
        warn:  { bg: 'rgba(243,156,18,.13)', bd: 'rgba(243,156,18,.34)', fg: '#e08b06' },
        info:  { bg: 'rgba(108,92,231,.13)', bd: 'rgba(108,92,231,.34)', fg: '#6c5ce7' },
    };
    MP._noticeIcons = {
        error: '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7.6v5.4M12 16.3v.3"/></svg>',
        warn:  '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 3.7 2.9 19.3h18.2z"/><path d="M12 9.6v4.1M12 16.5v.3"/></svg>',
        info:  '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 11.2v5.3M12 7.7v.3"/></svg>',
    };

    MP._noticeHost = function() {
        var host = document.getElementById('mapi-notices');
        if (host) return host;
        host = document.createElement('div');
        host.id = 'mapi-notices';
        host.style.cssText = 'position:fixed;top:16px;left:50%;transform:translateX(-50%);z-index:2147483647;display:flex;flex-direction:column;align-items:center;gap:8px;max-width:calc(100vw - 24px);pointer-events:none;font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif';
        (document.body || document.documentElement).appendChild(host);
        return host;
    };

    MP._noticeTimeout = function(level) {
        return level === 'error' ? 9000 : (level === 'warn' ? 6000 : 4000);
    };

    MP._noticeDismiss = function(el) {
        if (!el) return;
        clearTimeout(el._mapiTimer);
        el.style.opacity = '0';
        el.style.transform = 'translateY(-8px)';
        setTimeout(function(){ if (el.parentNode) el.parentNode.removeChild(el); }, 260);
    };

    // MP.notice('invalid_key') ；未知码也可以直接传文案：MP.notice('自定义文案', {level:'info'})
    MP.notice = function(code, opts) {
        if (!code) return null;
        var def = MP._noticeDefs[code] || { level: (opts && opts.level) || 'info', title: String(code), detail: (opts && opts.detail) || '' };
        var level = (opts && opts.level) || def.level || 'info';
        var c = MP._noticeColors[level] || MP._noticeColors.info;
        var host = MP._noticeHost();

        // 同码去重：已经在显示就只闪一下并续命，不叠罗汉
        var exist = host.querySelector('[data-notice="' + code + '"]');
        if (exist) {
            clearTimeout(exist._mapiTimer);
            exist.style.transform = 'translateY(0) scale(1.03)';
            setTimeout(function(){ exist.style.transform = 'translateY(0) scale(1)'; }, 160);
            exist._mapiTimer = setTimeout(function(){ MP._noticeDismiss(exist); }, MP._noticeTimeout(level));
            return exist;
        }
        while (host.children.length >= 3) MP._noticeDismiss(host.firstChild);   // 最多同时 3 条

        var el = document.createElement('div');
        el.setAttribute('data-notice', code);
        el.style.cssText = 'pointer-events:auto;cursor:pointer;box-sizing:border-box;display:flex;align-items:flex-start;gap:8px;max-width:420px;padding:9px 12px;border-radius:11px;background:' + c.bg + ';border:1px solid ' + c.bd + ';color:' + c.fg + ';backdrop-filter:blur(14px)saturate(180%);-webkit-backdrop-filter:blur(14px)saturate(180%);box-shadow:0 6px 24px rgba(0,0,0,.14);opacity:0;transform:translateY(-8px);transition:opacity .26s cubic-bezier(.4,0,.2,1),transform .26s cubic-bezier(.4,0,.2,1)';
        el.innerHTML = '<span style="flex:0 0 auto;display:flex;margin-top:1px">' + (MP._noticeIcons[level] || MP._noticeIcons.info) + '</span>'
            + '<span style="min-width:0">'
            + '<span style="display:block;font-size:13px;font-weight:700;line-height:1.45;word-break:break-word">' + _.escapeHtml(def.title) + '</span>'
            + (def.detail ? '<span style="display:block;font-size:12px;line-height:1.55;margin-top:2px;opacity:.85;word-break:break-word">' + _.escapeHtml(def.detail) + '</span>' : '')
            + '</span>';
        el.addEventListener('click', function(){ MP._noticeDismiss(el); });
        host.appendChild(el);
        void el.offsetWidth;
        el.style.opacity = '1';
        el.style.transform = 'translateY(0)';
        el._mapiTimer = setTimeout(function(){ MP._noticeDismiss(el); }, MP._noticeTimeout(level));
        return el;
    };

    // 卸载播放器时清掉提示，宿主页面上不留残影
    MP._noticeCleanup = function() {
        var host = document.getElementById('mapi-notices');
        if (host && host.parentNode) host.parentNode.removeChild(host);
    };

    MP._showNoPlaylistNotice = function() { MP.notice('no_playlist'); };

    MP.applyMarquee = function(el, text) {
        if (!el) return;
        var span = el.querySelector('.mi');
        if (!span) { el.textContent = ''; span = document.createElement('span'); span.className = 'mi'; el.appendChild(span); }
        span.textContent = text;
        el.classList.add('mw');
        var parentW = el.parentElement ? el.parentElement.clientWidth : el.clientWidth;
        var singleW = span.scrollWidth;
        if (singleW > parentW) {
            span.textContent = text + '             ' + text;
            var totalW = span.scrollWidth;
            var gapW = totalW - singleW * 2;
            var cycleW = singleW + gapW;
            el.style.setProperty('--mx', '-' + cycleW + 'px');
            el.style.setProperty('--md', Math.max(cycleW / 25, 5) + 's');
        } else {
            el.style.setProperty('--md', '0s');
            el.style.setProperty('--mx', '0px');
        }
    };

    MP.updateUI = function() {
        if (!MP.ap || !MP.ap.list) return;
        var idx = MP.ap.list.index;
        var info = MP.ap.list.audios[idx];
        var cover = MP.$('cv').querySelector('img');
        if (info && info.cover) { cover.src = info.cover; cover.style.display = ''; }
        else { cover.style.display = 'none'; }
        var togCover = MP.$('toggleCover');
        var togSvg = MP.$('toggleSvg');
        if (MP._loading) {
            // 加载完成前：悬浮按钮只显示音符 + 呼吸动效，不显示“播放中”封面
            if (togCover) { togCover.style.display = 'none'; togCover.src = ''; }
            if (togSvg) togSvg.style.display = '';
            MP._lastToggleCover = '';
        } else if (togCover && info && info.cover) {
            if (info.cover !== MP._lastToggleCover) {
                MP._lastToggleCover = info.cover;
                togCover.style.display = 'block';
                if (togSvg) togSvg.style.display = '';
                togCover.onload = function() {};
                togCover.onerror = function() {
                    togCover.style.display = 'none';
                };
                togCover.src = info.cover;
            } else {
                togCover.style.display = 'block';
            }
        } else if (togCover) {
            togCover.style.display = 'none';
            togCover.src = '';
            if (togSvg) togSvg.style.display = '';
            MP._lastToggleCover = '';
        }
        MP.applyMarquee(MP.$('ttl'), info ? info.name : '\u672a\u77e5');
        MP.applyMarquee(MP.$('art'), info ? (info.artist || '') : '');
        MP.updateProgress();
    };

    // 自动滚动到「正在播放」那行，以及「用户正在自己挑歌」时的不打扰保护。
    // 依次加载期间每批歌到货都会刷新一次列表：如果每次都滚回当前曲，用户根本翻不下去挑歌，
    // 所以只有「真的换了歌」或「整表刚重建」（换歌单/切视图）时才自动滚动。
    MP._lastRenderedRef = '';      // 上一帧显示的是哪首歌：只有它变了才算「换歌」
    MP._listTouched = false;
    MP._listTouchBound = false;
    MP._bindListTouch = function() {
        if (MP._listTouchBound) return;
        MP._listTouchBound = true;
        var inList = function(t) {
            var els = [MP.$('slistInner'), MP.$('imSlistInner')];
            for (var i = 0; i < els.length; i++) { if (els[i] && t && els[i].contains(t)) return true; }
            return false;
        };
        ['pointerdown', 'touchstart', 'wheel', 'touchmove'].forEach(function(type){
            document.addEventListener(type, function(e){ if (inList(e.target)) MP._listTouched = true; }, true);
        });
    };
    MP._scrollActiveIfNeeded = function(els, curRef, force) {
        if (!curRef) return;
        if (!force) {
            if (MP._listTouched) return;                    // 用户刚滚过/按过：别把他拽回来
            if (curRef === MP._lastRenderedRef) return;      // 还是同一首：只是依次加载在刷新列表
        }
        els.forEach(function(el){
            var activeEl = el.querySelector('.songitem.active');
            if (activeEl && activeEl.scrollIntoView) activeEl.scrollIntoView({block:'nearest',behavior:'smooth'});
        });
    };
    // 行状态（重建 HTML 与原地更新共用同一套判断）；state 为 ''/'pending'/'failed'
    // 懒加载：'pending' 不再代表"正在加载"，而是"还没取过（点它才取）" —— 绝大多数行本来就该是这个状态
    MP._rowState = function(s) { return s.url ? '' : (s._failed ? 'failed' : 'pending'); };
    MP._songitemState = function(s, isSamePL, curRef) {
        var active = (isSamePL && s.url && MP._songRef(s) === curRef) ? ' active' : '';
        var state = MP._rowState(s);
        var tip = state === 'failed' ? '\u52a0\u8f7d\u5931\u8d25\uff0c\u70b9\u51fb\u91cd\u8bd5'
                : (state === 'pending' ? '\u70b9\u51fb\u64ad\u653e\uff08\u6309\u9700\u52a0\u8f7d\uff09' : '');
        return { cls: 'songitem' + active + (state ? ' ' + state : ''), tip: tip, state: state };
    };
    // 封面位：排队中的行写「加载」（失败写「失败」），解析出封面后换成真图。
    // 两种形态占同一格，行高与右侧文字的位置不随解析进度跳动。
    //
    // ⚠ 列表里几十上百行同时渲染时，**绝不能全部立刻加载**：
    // 浏览器对同一域名只放 6 个并发，194 张图会排成 30 多轮，首屏被这些图拖住。
    // loading="lazy" 让浏览器只取视口附近的那几张（其它滚到才取），decoding="async" 不阻塞排版。
    MP._coverHtml = function(cov, state) {
        // 封面优先：只要有封面就一定显示它（哪怕这一首还没取播放地址）——
        // 懒加载只影响"播放地址什么时候取"，绝不该影响封面显示。
        if (cov) {
            MP._preconnectCover(cov);
            return '<img class="si-cover" src="' + _.escapeHtml(cov) + '" alt="" loading="lazy" decoding="async">';
        }
        // 真没有封面时才用占位格：失败写「失败」，其余留空
        return '<span class="si-cover">' + (state === 'failed' ? '\u5931\u8d25' : '') + '</span>';
    };

    // 封面域名预连接：图都在对象存储上（跨域），提前建好 DNS+TLS，首屏能省掉第一轮握手
    MP._preconnectCover = function(url) {
        try {
            if (!url || !/^https?:\/\//i.test(url)) return;          // 相对地址＝本站，不用预连接
            var host = String(url).replace(/^https?:\/\//i, '').split('/')[0];
            if (!host || host === location.host) return;
            if (!MP._preHosts) MP._preHosts = {};
            if (MP._preHosts[host]) return;
            MP._preHosts[host] = 1;
            var head = document.head || document.documentElement;
            if (!head) return;
            var pre = document.createElement('link');
            pre.rel = 'preconnect';
            pre.href = location.protocol + '//' + host;
            pre.crossOrigin = 'anonymous';
            head.appendChild(pre);
            var dns = document.createElement('link');
            dns.rel = 'dns-prefetch';
            dns.href = '//' + host;
            head.appendChild(dns);
        } catch (e) {}
    };
    MP._patchCover = function(item, cov, state) {
        var el = item.querySelector('.si-cover');
        if (!el) return;
        // 封面优先：只要有封面就换成真图（未取播放地址不影响它）
        var wantImg = !!cov;
        if (wantImg !== (el.tagName === 'IMG')) {          // 占位格子与真图互换
            var tmp = document.createElement('div');
            tmp.innerHTML = MP._coverHtml(cov, state);
            if (tmp.firstChild) el.parentNode.replaceChild(tmp.firstChild, el);
            return;
        }
        if (wantImg) {
            if (el.getAttribute('src') !== cov) el.setAttribute('src', cov);
            return;
        }
        var txt = state === 'failed' ? '\u5931\u8d25' : (state ? '\u52a0\u8f7d' : '');
        if (el.textContent !== txt) el.textContent = txt;
    };
    // 原地更新一行：不重建节点 → 保住滚动位置、悬停态和「按下还没松开」的那次点击
    MP._patchSongitem = function(item, s, isSamePL, curRef) {
        if (!item) return;
        var st = MP._songitemState(s, isSamePL, curRef);
        if (item.className !== st.cls) item.className = st.cls;
        if (st.tip) { if (item.getAttribute('title') !== st.tip) item.setAttribute('title', st.tip); }
        else if (item.hasAttribute('title')) item.removeAttribute('title');
        var nm = item.querySelector('.si-name');
        if (nm && nm.textContent !== (s.name || '')) nm.textContent = s.name || '';
        var ar = item.querySelector('.si-artist');
        if (ar && ar.textContent !== (s.artist || '')) ar.textContent = s.artist || '';
        MP._patchCover(item, s.cover || s.pic || '', st.state);
    };

    MP.renderSonglist = function() {
        var innerEls = [MP.$('slistInner'), MP.$('imSlistInner')].filter(function(e){return e;});
        if (innerEls.length === 0) return;
        var hasMultiple = MP.playlists && MP.playlists.length > 1;
        var backEls = [MP.$('plBack'), MP.$('imPlBack')].filter(function(e){return e;});
        backEls.forEach(function(btn){
            if (!MP._viewingPlaylist && hasMultiple) btn.classList.add('visible');
            else btn.classList.remove('visible');
        });
        if (MP._viewingPlaylist && hasMultiple) {
            var html = '';
            for (var i = 0; i < MP.playlists.length; i++) {
                var pl = MP.playlists[i];
                var name = pl.name || ('\u6b4c\u5355' + (i + 1));
                var cover = MP._playlistCovers[i] || '';
                html += '<div class="pl-list-item" data-plidx="' + i + '">';
                html += '<span class="pl-idx">' + (i + 1) + '</span>';
                if (cover) {
                    html += '<img class="pl-cover" src="' + _.escapeHtml(cover) + '" alt="" loading="lazy" decoding="async" onerror="var s=document.createElement(\'span\');s.className=\'pl-cover\';s.style.cssText=\'display:flex;align-items:center;justify-content:center;font-size:10px;background:rgba(0,0,0,.06)\';s.textContent=\'' + _.escapeHtml(name.charAt(0)) + '\';this.parentNode.replaceChild(s,this)">';
                } else {
                    html += '<span class="pl-cover" style="display:flex;align-items:center;justify-content:center;font-size:10px">' + _.escapeHtml(name.charAt(0)) + '</span>';
                }
                html += '<span class="pl-name">' + _.escapeHtml(name) + '</span>';
                var cnt;
                if (MP._loadFailed[i]) cnt = '\u5931\u8d25';
                else if (MP._allSongs[i]) {
                    // 懒加载：不再显示「已就绪/总数」（点哪首取哪首，绝大多数行本来就没取），只报曲目总数
                    cnt = String(MP._allSongs[i].length) + '\u9996';
                }
                else if (MP._loadingPlaylists[i] || MP._preloadIdx === i) cnt = '\u52a0\u8f7d\u4e2d';
                else cnt = '\u5f85\u52a0\u8f7d';
                html += '<span class="pl-count">' + cnt + '</span>';
                html += '</div>';
            }
            innerEls.forEach(function(el){
                el.innerHTML = html;
                el.querySelectorAll('.pl-list-item').forEach(function(item){
                    item.addEventListener('click', function(e){
                        e.stopPropagation();
                        // 用户主动翻歌单：启动流程不许再把他正在看的视图拽走（见 player.js loadInitialPlaylist）
                        MP._userNavigated = true;
                        var plidx = parseInt(this.getAttribute('data-plidx'));
                        var showSongs = function(){
                            if (MP._destroyed) return;
                            MP._viewingPlaylist = false;
                            MP._viewingPlaylistIndex = plidx;
                            if (!MP.ap) {
                                var list = MP._allSongs[plidx] || [];
                                if (list.length) {
                                    MP.currentPlaylistIndex = plidx;
                                    MP.songs = list;
                                    MP.initPlayer(list);
                                    return;
                                }
                            }
                            MP._renderWithFade();
                        };
                        if (MP._allSongs[plidx] && !MP._loadFailed[plidx]) {
                            showSongs();
                        } else {
                            // 该歌单尚未加载：先给出“加载中”反馈，再按需拉取
                            MP._renderWithFade();
                            MP.ensurePlaylistLoaded(plidx, showSongs);
                        }
                    });
                });
            });
        } else {
            var viewIdx = MP._viewingPlaylistIndex;
            var viewSongs = MP._allSongs[viewIdx] || [];
            if (!MP.playlists || !MP.playlists[viewIdx]) {
                // 引擎还没定下当前歌单（或下标已失效）：先显示加载态，别去自动拉取 ——
                // 未知下标会被 loadPlaylistSongs 直接 resolve([])，回调和本函数互相调用会死循环
                innerEls.forEach(function(el){
                    el.innerHTML = '<div style="color:#999;font-size:13px;text-align:center;padding:20px 0">\u52a0\u8f7d\u4e2d\u2026</div>';
                });
                return;
            }
            if (viewSongs.length === 0 && !MP._allSongs[viewIdx] && !MP._loadFailed[viewIdx]) {
                // 歌单尚未加载过：显示加载态并自动拉取
                innerEls.forEach(function(el){
                    el.innerHTML = '<div style="color:#999;font-size:13px;text-align:center;padding:20px 0">\u52a0\u8f7d\u4e2d\u2026</div>';
                });
                MP.ensurePlaylistLoaded(viewIdx, function(){ if (!MP._destroyed) MP.renderSonglist(); });
                return;
            }
            if (viewSongs.length === 0 && MP._loadFailed[viewIdx]) {
                // 上次加载失败：给出可点击的重试入口，而不是误报“无歌曲”
                innerEls.forEach(function(el){
                    el.innerHTML = '<div class="pl-retry" style="color:#999;font-size:13px;text-align:center;padding:20px 0;cursor:pointer">\u52a0\u8f7d\u5931\u8d25\uff0c\u70b9\u51fb\u91cd\u8bd5</div>';
                    var retryEl = el.querySelector('.pl-retry');
                    if (retryEl) retryEl.addEventListener('click', function(e){
                        e.stopPropagation();
                        MP.ensurePlaylistLoaded(viewIdx, function(){ if (!MP._destroyed) MP.renderSonglist(); });
                    });
                });
                return;
            }
            if (viewSongs.length === 0) {
                innerEls.forEach(function(el){
                    el.innerHTML = '<div style="color:#999;font-size:13px;text-align:center;padding:20px 0">\u65e0\u6b4c\u66f2</div>';
                });
                return;
            }
            var isSamePL = MP._viewingPlaylistIndex === MP.currentPlaylistIndex;
            // 未解析的曲目也在 APlayer 列表里占位（url 为空），但「正在播放」仍按身份比对更稳
            var _curAudio = (MP.ap && MP.ap.list && MP.ap.list.audios[MP.ap.list.index]) || null;
            var curRef = _curAudio ? MP._songRef(_curAudio) : '';
            MP._bindListTouch();
            var _scope = 'songs:' + viewIdx;
            var html = '';
            for (var i = 0; i < viewSongs.length; i++) {
                var s = viewSongs[i];
                var _st = MP._songitemState(s, isSamePL, curRef);
                // 还没解析出来的行：pending=加载中（点它优先加载）、failed=失败（点它重试）
                html += '<div class="' + _st.cls + '" data-idx="' + i + '"' + (_st.tip ? ' title="' + _st.tip + '"' : '') + '>';
                html += '<span class="si-idx">' + (i + 1) + '</span>';
                var _cov = s.cover || s.pic || '';
                // 封面位始终占住：排队中写「加载」，解析出封面就换成真图 → 行高与文字不跳
                html += MP._coverHtml(_cov, _st.state);
                html += '<span class="si-name">' + _.escapeHtml(s.name || '') + '</span>';
                html += '<span class="si-artist">' + _.escapeHtml(s.artist || '') + '</span>';
                html += '</div>';
            }
            innerEls.forEach(function(el){
                // 同一歌单、行数没变 → 原地补内容，不重建 DOM。
                // 整表重建会清掉用户滚到的位置、悬停态，以及「按下还没松开」的那次点击
                //（节点被换掉后 mouseup 落在新节点上，click 不触发，点歌就像没反应）。
                if (el.__mszScope === _scope && el.querySelectorAll('.songitem').length === viewSongs.length) {
                    var items = el.querySelectorAll('.songitem');
                    for (var pi = 0; pi < viewSongs.length; pi++) MP._patchSongitem(items[pi], viewSongs[pi], isSamePL, curRef);
                    MP._scrollActiveIfNeeded([el], curRef, false);
                    return;
                }
                el.innerHTML = html;
                el.__mszScope = _scope;
                MP._bindSongitemClicks(el);
                // 整表刚重建 = 用户自己切了歌单/视图（主动导航，不是被加载进度拽走）：
                // 清掉触摸保护，再滚到正在播放那行（视图里没有正在播放的歌时自然什么也不滚）
                MP._listTouched = false;
                MP._scrollActiveIfNeeded([el], curRef, true);
            });
            MP._lastRenderedRef = curRef;   // 记下这一帧的身份：下一次只有真的换歌才自动滚动
        }
    };

    // 行点击：绑定一次即可 —— 原地补丁不换节点，监听器一直有效；下标在点击时现场查表
    MP._bindSongitemClicks = function(el) {
                el.querySelectorAll('.songitem').forEach(function(item){
                    item.addEventListener('click', function(e){
                        e.stopPropagation();
                        var targetIdx = parseInt(this.getAttribute('data-idx'));
                        var viewIdx = MP._viewingPlaylistIndex;
                        var targetSongs = MP._allSongs[viewIdx] || [];
                        var targetSong = targetSongs[targetIdx];
                        if (!targetSong) return;
                        // 用户主动点列表：之后的换歌可以再自动滚动（清掉触摸保护）
                        MP._listTouched = false;
                        if (!targetSong.url) {
                            // 还没解析出来的行：点它 = 插到队首优先加载（失败的行 = 重试）
                            MP._userPicked = true;
                            if (viewIdx !== MP.currentPlaylistIndex) MP.switchPlaylist(viewIdx);
                            MP._prioritizeSong(viewIdx, targetIdx);
                            return;
                        }
                        MP._userPicked = true;
                        if (viewIdx !== MP.currentPlaylistIndex) {
                            MP.currentPlaylistIndex = viewIdx;
                            MP.songs = targetSongs;
                            MP._viewingPlaylist = false;
                            MP._viewingPlaylistIndex = viewIdx;
                            MP._restorePendingRef = '';
                            MP.saveState();
                            if (MP.ap) {
                                MP._rebuildApList(targetSongs, MP._songRef(targetSong));
                                MP.ap.play();
                            } else {
                                // 播放器还没建出来（加载期间就点进别的歌单点了歌）：先把「要播的那首」记下来，
                                // initPlayer 收尾的 _resolvePendingPicks() 会切到它并开播 ——
                                // 否则列表建好了却停在第一首，用户点了像没反应
                                MP._pendingPlayRef = MP._songRef(targetSong);
                                MP._pendingPlayIdx = viewIdx;
                                MP.initPlayer(targetSongs);
                            }
                        } else {
                            var pos = MP._audioIndexOf(MP._songRef(targetSong));
                            if (pos < 0) {                       // APlayer 列表里还没有它：优先拉它
                                MP._prioritizeSong(viewIdx, targetIdx);
                                return;
                            }
                            if (!MP.ap || pos === MP.ap.list.index) return;
                            try { MP.ap.list.switch(pos); } catch(e) {}
                            setTimeout(function(){ MP.onSwitch(); }, 150);
                        }
                        MP.renderSonglist();
                    });
                });
    };

    MP._renderWithFade = function() {
        var innerEls = [MP.$('slistInner'), MP.$('imSlistInner')].filter(function(e){return e;});
        innerEls.forEach(function(el){ el.style.opacity = '0'; });
        setTimeout(function(){
            MP.renderSonglist();
            innerEls.forEach(function(el){ el.style.opacity = '1'; });
        }, 150);
    };

    MP.updateProgress = function() {
        if (!MP.ap || !MP.ap.audio) return;
        var a = MP.ap.audio;
        var cur = a.currentTime||0, dur = a.duration||0;
        var pct = dur > 0 ? (cur/dur*100) : 0;
        MP.$('played').style.width = pct + '%';
        MP.$('cur').textContent = _.fmt(cur);
        MP.$('dur').textContent = dur ? _.fmt(dur) : '00:00';
    };

    MP.updatePlayBtn = function(playing) {
        var svg = MP.$('playSvg');
        if (playing) {
            svg.innerHTML = '<rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>';
        } else {
            svg.innerHTML = '<polygon points="6,4 20,12 6,20"/>';
        }
    };

    MP.togglePanel = function() {
        // 依次加载期间也允许打开面板。列表本身就是进度展示（歌单行写「加载中 / 已就绪 3/50」、
        // 未解析的行写「加载」），用户想边加载边进别的歌单挑歌不该被拦。
        // 这里原来在加载中直接 return 只弹一句「正在加载…」，结果歌没加载完就进不去任何歌单，
        // 更别说点歌了 —— 加载提示仍由悬浮按钮的呼吸动效承担，不需要靠「不让进门」来表达。
        MP.open = !MP.open;
        var pnl = MP.$('panel');
        MP.$('overlay').style.display = MP.open ? 'block' : 'none';
        if (MP.open) {
            MP._cancelAutoHide();
            pnl.classList.add('open');
            MP.updateUI();
            // 展开后按「面板 ∪ 按钮」的实际可见范围统一夹进视口。
            // 顶部停靠时面板是向下浮出的，本来就不会顶出屏幕；其余情况才需要临时下移。
            if (typeof MP._snap === 'function') MP._snap();
        } else {
            pnl.classList.remove('open');
            MP._scheduleAutoHide();
            // 收起后可见范围缩回按钮本身：让它回到访客拖到的位置（临时夹取不落盘）
            if (typeof MP._snap === 'function') MP._snap();
        }
    };

})(window.__mapiPlayer);