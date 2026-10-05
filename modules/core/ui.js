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
                    html += '<img class="pl-cover" src="' + _.escapeHtml(cover) + '" alt="" onerror="var s=document.createElement(\'span\');s.className=\'pl-cover\';s.style.cssText=\'display:flex;align-items:center;justify-content:center;font-size:10px;background:rgba(0,0,0,.06)\';s.textContent=\'' + _.escapeHtml(name.charAt(0)) + '\';this.parentNode.replaceChild(s,this)">';
                } else {
                    html += '<span class="pl-cover" style="display:flex;align-items:center;justify-content:center;font-size:10px">' + _.escapeHtml(name.charAt(0)) + '</span>';
                }
                html += '<span class="pl-name">' + _.escapeHtml(name) + '</span>';
                var cnt;
                if (MP._loadFailed[i]) cnt = '\u5931\u8d25';
                else if (MP._allSongs[i]) cnt = MP._allSongs[i].length + '\u9996';
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
            var idx = MP.ap ? MP.ap.list.index : 0;
            var html = '';
            for (var i = 0; i < viewSongs.length; i++) {
                var s = viewSongs[i];
                var active = (isSamePL && i === idx) ? ' active' : '';
                html += '<div class="songitem' + active + '" data-idx="' + i + '">';
                html += '<span class="si-idx">' + (i + 1) + '</span>';
                var _cov = s.cover || s.pic || ''; if (_cov) html += '<img class="si-cover" src="' + _.escapeHtml(_cov) + '" alt="">';
                html += '<span class="si-name">' + _.escapeHtml(s.name || '') + '</span>';
                html += '<span class="si-artist">' + _.escapeHtml(s.artist || '') + '</span>';
                html += '</div>';
            }
            innerEls.forEach(function(el){
                el.innerHTML = html;
                var activeEl = el.querySelector('.songitem.active');
                if (activeEl) activeEl.scrollIntoView({block:'nearest',behavior:'smooth'});
                el.querySelectorAll('.songitem').forEach(function(item){
                    item.addEventListener('click', function(e){
                        e.stopPropagation();
                        var targetIdx = parseInt(this.getAttribute('data-idx'));
                        var targetSongs = MP._allSongs[MP._viewingPlaylistIndex] || [];
                        var targetSong = targetSongs[targetIdx];
                        if (!targetSong || !targetSong.url) return;
                        if (MP._viewingPlaylistIndex !== MP.currentPlaylistIndex) {
                            MP.currentPlaylistIndex = MP._viewingPlaylistIndex;
                            MP.songs = targetSongs;
                            MP._viewingPlaylist = false;
                            MP.saveState();
                            if (MP.ap) {
                                MP.ap.list.clear();
                                var audios = [];
                                targetSongs.forEach(function(ts){
                                    if (ts.url) audios.push({name:ts.name||'\u672a\u77e5', artist:ts.artist||'', url:ts.url, cover:ts.pic||'', _lrc:ts.lrc||''});
                                });
                                MP.ap.list.add(audios);
                                MP.ap.list.switch(targetIdx);
                                MP.ap.play();
                            } else {
                                MP.initPlayer(targetSongs);
                            }
                        } else {
                            if (targetIdx === idx) return;
                            try { MP.ap.list.switch(targetIdx); } catch(e) {}
                            setTimeout(function(){ MP.onSwitch(); }, 150);
                        }
                        MP.renderSonglist();
                    });
                });
            });
        }
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
        if (MP._loading) {
            // 设计如此：歌单没有全部加载完成前不打开面板，悬浮按钮的呼吸动效就是加载提示
            if (MP._toastEl && MP._toastEl.parentNode) return;
            var toast = document.createElement('div');
            toast.textContent = '\u6b63\u5728\u52a0\u8f7d\u2026';
            toast.style.cssText = 'position:fixed;top:80px;left:50%;transform:translateX(-50%) scale(0.8);z-index:2147483647;background:rgba(0,0,0,.55);backdrop-filter:blur(16px)saturate(200%);color:#fff;font-size:14px;font-weight:600;padding:10px 20px;border-radius:10px;font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;opacity:0;transition:all .3s cubic-bezier(.4,0,.2,1);pointer-events:none';
            document.body.appendChild(toast);
            MP._toastEl = toast;
            void toast.offsetWidth;
            toast.style.opacity = '1';
            toast.style.transform = 'translateX(-50%) scale(1)';
            setTimeout(function(){
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(-50%) scale(0.8)';
                setTimeout(function(){ if (toast.parentNode) toast.parentNode.removeChild(toast); MP._toastEl = null; }, 300);
            }, 2000);
            return;
        }
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