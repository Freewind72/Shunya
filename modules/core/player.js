(function(MP){
    if (!MP) return;
    var _ = MP._;

    MP.init = function() {
        if (MP._destroyed) return;
        MP._applyDefaultPos();      // 首次访问：宿主一创建就摆到后台设置的位置（不等加载完）
        // 在途请求的中止句柄：destroy() 时会 abort()，卸载后不再占住（单线程）服务器
        if (typeof AbortController !== 'undefined' && !MP._abort) {
            MP._abort = new AbortController();
        }
        MP.fetchConfig();
        MP._themeInterval = setInterval(function(){ if (!MP._destroyed) MP.checkTheme(); }, 60000);
        MP.checkGreeting();
        // 公告请求延后发出：启动瞬间的并发请求会在单线程服务器上排队，拖慢后台页面切换
        if (MP._annTimer) clearTimeout(MP._annTimer);
        MP._annTimer = setTimeout(function() {
            MP._annTimer = null;
            if (!MP._destroyed) MP.fetchAnnouncement();
        }, 2500);
        MP._onMessage = function(e) {            if (e.data && e.data.type === 'mszeph-config-update' && e.data.config) {
                var updated = false;
                if (e.data.config.auto_theme !== undefined) {
                    MP._autoTheme = e.data.config.auto_theme;
                    updated = true;
                }
                if (e.data.config.theme_mode !== undefined) {
                    MP._themeMode = e.data.config.theme_mode;
                    updated = true;
                }
                if (e.data.config.lyrics_default !== undefined) {
                    MP.applyLrcDefault(e.data.config.lyrics_default);
                }
                if (e.data.config.lrc_font !== undefined || e.data.config.lrc_font_name !== undefined || e.data.config.lrc_font_size !== undefined) {
                    MP.applyLrcFont({ url: e.data.config.lrc_font, name: e.data.config.lrc_font_name, size: e.data.config.lrc_font_size });
                }
                if (e.data.config.autoplay_default !== undefined) {
                    MP._autoplayDefault = e.data.config.autoplay_default;
                }
                if (e.data.config.player_pos !== undefined) {
                    MP._playerPos = e.data.config.player_pos;
                }
                if (updated) MP.checkTheme();
            }
        };
        window.addEventListener('message', MP._onMessage);
// 忽略页面级 AbortError 拒绝
        if (!window.__mapiAbortGuard) {
            window.__mapiAbortGuard = function(e) {
                if (e && e.reason && e.reason.name === 'AbortError') e.preventDefault();
            };
            window.addEventListener('unhandledrejection', window.__mapiAbortGuard);
        }
    };

    MP.fetchConfig = function() {
        if (MP._destroyed) return;
        if (window.__mszeph_config && window.__mszeph_config.auto_theme !== undefined) {
            MP._autoTheme = window.__mszeph_config.auto_theme;
            if (window.__mszeph_config.theme_mode !== undefined) {
                MP._themeMode = window.__mszeph_config.theme_mode;
            }
            MP.applyLrcDefault(window.__mszeph_config.lyrics_default);
            MP.applyLrcFont({
                url: window.__mszeph_config.lrc_font,
                name: window.__mszeph_config.lrc_font_name,
                size: window.__mszeph_config.lrc_font_size
            });
            if (window.__mszeph_config.autoplay_default !== undefined) {
                MP._autoplayDefault = window.__mszeph_config.autoplay_default;
            }
            if (window.__mszeph_config.player_pos !== undefined) {
                MP._playerPos = window.__mszeph_config.player_pos;
            }
            if (window.__mszeph_config.server !== undefined) {
                MP._server = window.__mszeph_config.server;
            }
            if (window.__mszeph_config.source) {
                MP._source = window.__mszeph_config.source;
            }
            MP.checkTheme();
            var pls = (window.__mszeph_config.playlists || []).map(function(p){ p.type = p.type || 'playlist'; return p; });
            if (pls && pls.length) { MP.playlists = pls; MP.loadInitialPlaylist(); }
            else { MP._showNoPlaylistNotice(); }
            return;
        }
        if (_.API_KEY || _.API_TOKEN) {
            // 先把设备号捞出来（localStorage 里存着上一次服务端发的那份）：
            // 跨站嵌入时没有 cookie，只能靠 ?dev= 回传，否则每次进页面都会被当成新设备、云状态对不上。
            if (typeof MP._deviceInit === 'function') MP._deviceInit('');
            var xhr = new XMLHttpRequest();
            xhr.open('GET', _.API_BASE + '?action=get-config&token=' + encodeURIComponent(_.API_TOKEN || _.API_KEY)
                + (typeof MP._devParam === 'function' ? MP._devParam() : ''), true);
            xhr.timeout = 15000;
            xhr.onload = function() {
                if (MP._destroyed) return;
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.ok && data.config) {
                        // 设备号（服务端下发，可能同时种了 cookie）：记下来，之后所有请求都带上
                        if (typeof MP._deviceInit === 'function') MP._deviceInit(data.config.device);
                        if (data.config.auto_theme !== undefined) {
                            MP._autoTheme = data.config.auto_theme;
                        }
                        if (data.config.theme_mode !== undefined) {
                            MP._themeMode = data.config.theme_mode;
                        }
                        MP.applyLrcDefault(data.config.lyrics_default);
                        MP.applyLrcFont({
                            url: data.config.lrc_font,
                            name: data.config.lrc_font_name,
                            size: data.config.lrc_font_size
                        });
                        if (data.config.autoplay_default !== undefined) {
                            MP._autoplayDefault = data.config.autoplay_default;
                        }
                        if (data.config.player_pos !== undefined) {
                            MP._playerPos = data.config.player_pos;
                        }
                        if (data.config.server !== undefined) {
                            MP._server = data.config.server;
                        }
                        if (data.config.source) {
                            MP._source = data.config.source;
                        }
                        MP.checkTheme();
                        var pls = (data.config.playlists || []).map(function(p){ p.type = p.type || 'playlist'; return p; });
                        if (pls && pls.length) {
                            MP.playlists = pls;
                            MP.loadInitialPlaylist();
                            // 状态云同步：本机没有状态时，用云端那份接着听（cookie 丢了/换浏览器）
                            if (typeof MP._cloudPull === 'function') MP._cloudPull();
                        }
                        else { MP._showNoPlaylistNotice(); }
                    } else if (data && data.config && data.config.domain && data.config.domain.blocked) {
                        MP._bootFailed = true;
                        if (typeof MP.notice === 'function') {
                            if (data.config.domain.reason === 'key_domain') {
                                MP.notice('key_domain', { detail: '这条密钥只授权给 ' + (data.config.domain.keyDomain || '它绑定的域名') });
                            } else {
                                MP.notice('domain_blocked');
                            }
                        }
                    } else {
                        MP._bootFailed = true;
                        if (typeof MP.notice === 'function') MP.notice((data && data.code) || 'server_error');
                    }
                } catch(e) {
                    MP._bootFailed = true;
                    if (typeof MP.notice === 'function') MP.notice('server_error');
                }
            };
            xhr.ontimeout = function() {
                if (MP._destroyed) return;
                MP._bootFailed = true;
                if (typeof MP.notice === 'function') MP.notice('offline');
            };
            xhr.onerror = function() {
                if (MP._destroyed) return;
                MP._bootFailed = true;
                if (typeof MP.notice === 'function') MP.notice('offline');
            };
            MP._configXhr = xhr;
            xhr.send();
        }
    };

    MP._sourceURL = function(type, id, server) {
        if (!MP._source) return null;
        var base = MP._source.base_url;
        var p = MP._source.params;
        var params = {};
        params[p.server] = server || MP._server || 'netease';
        params[p.type] = type;
        params[p.id] = id || '';
        return base + (base.indexOf('?') >= 0 ? '&' : '?') + Object.keys(params).map(function(k){ return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
    };

    MP._parseSourceRaw = function(raw, server) {
        if (!raw || !Array.isArray(raw)) return [];
        var f = (MP._source && MP._source.fields) || {};
        var out = [];
        for (var i = 0; i < raw.length; i++) {
            var s = raw[i] || {};
            var mid = '';
            var fUrl = f.url || 'url';
            var fLrc = f.lrc || 'lrc';
            var pId = f.id || 'id';
            // 优先取上游给出的稳定 id
            if (s.songmid) mid = String(s.songmid);
            else if (s.mid) mid = String(s.mid);
            else if (s[pId] !== undefined && s[pId] !== null && s[pId] !== '' && typeof s[pId] !== 'object') mid = String(s[pId]);
            var picAuth = '';
            var picId = '';
            var lrcUrl = '';
            var rawUrl = s[fUrl] || '';
            var rawLrc = s[fLrc] || '';
            var rawPic = s[f.pic || 'pic'] || '';
            if (rawLrc) {
                if (MP._isInlineLrc(rawLrc)) {
                    lrcUrl = rawLrc;            // 接口已内联歌词正文：直接使用，无需再请求
                } else {
                    var m1 = rawLrc.match(new RegExp('[?&]' + pId + '=([^&]+)'));
                    if (m1) { mid = m1[1]; lrcUrl = rawLrc; }
                }
            }
            if (!mid && rawUrl) {
                var m2 = rawUrl.match(new RegExp('[?&]' + pId + '=([^&]+)'));
                if (m2) { mid = m2[1]; }
            }
            if (rawPic) {
                var m3 = rawPic.match(new RegExp('[?&]' + pId + '=([^&]+)'));
                if (m3) picId = m3[1];
                if (f.auth) {
                    var m4 = rawPic.match(new RegExp('[?&]' + f.auth + '=([^&]+)'));
                    if (m4) picAuth = m4[1];
                }
            }
            out.push({
                mid: mid,
                title: s[f.title || 'title'] || '',
                artist: s[f.artist || 'artist'] || '',
                rawUrl: rawUrl,
                rawPic: rawPic,
                picId: picId,
                picAuth: picAuth,
                lrcUrl: lrcUrl,
                server: server,
            });
        }
        return out;
    };

    MP._fetchWithTimeout = function(url, signal, ms, asText) {
        return new Promise(function(resolve, reject) {
            var ctrl = null;
            var timer = null;
            var aborted = false;
            if (typeof AbortController !== 'undefined') {
                ctrl = new AbortController();
            }
            var childSig = ctrl ? ctrl.signal : undefined;
            if (signal) {
                signal.addEventListener('abort', function() {
                    aborted = true;
                    if (timer) clearTimeout(timer);
                    if (ctrl) try { ctrl.abort(); } catch(e) {}
                    try { var e = new Error('AbortError'); e.name = 'AbortError'; reject(e); } catch(err) {}
                }, {once: true});
            }
            if (ctrl) {
                childSig.addEventListener('abort', function() {
                    if (aborted) return;
                    if (timer) clearTimeout(timer);
                    try { var e = new Error('AbortError'); e.name = 'AbortError'; reject(e); } catch(err) {}
                }, {once: true});
            }
            timer = setTimeout(function() {
                aborted = true;
                if (ctrl) try { ctrl.abort(); } catch(e) {}
                try { var e = new Error('timeout'); e.name = 'TimeoutError'; reject(e); } catch(err) {}
            }, ms || 10000);
            fetch(url, {signal: childSig, credentials: 'omit'}).then(function(r) {
                if (aborted) return;
                clearTimeout(timer);
                if (!r.ok) throw new Error('http ' + r.status);
                return asText ? r.text() : r.json();
            }).then(function(d) {
                if (aborted) return;
                resolve(d);
            }).catch(function(err) {
                if (aborted) return;
                clearTimeout(timer);
                reject(err);
            });
        });
    };

// 地址统一升级为 https
    MP._httpsUrl = function(u) {
        if (!u || typeof u !== 'string') return '';
        if (/^http:\/\//i.test(u)) return u.replace(/^http:/i, 'https:');
        if (/^\/\//.test(u)) return 'https:' + u;   // 协议相对
        return u;
    };

    MP._sourceResolveUrls = function(parsedList, server, signal) {
        if (server !== 'netease') {
            var arr = [];
            for (var i = 0; i < parsedList.length; i++) {
                var rawUrl = parsedList[i].rawUrl || '';
                arr.push({idx: i, url: MP._httpsUrl(rawUrl), lrc: ''});
            }
            return Promise.resolve(arr);
        }
        var mh = parsedList.filter(function(p){ return p.mid; });
        var tasks = mh.map(function(p) {
            return MP._fetchWithTimeout(MP._sourceURL('url', p.mid, server), signal, 8000).then(function(data) {
                var out = {idx: -1, url: '', lrc: ''};
                for (var i = 0; i < parsedList.length; i++) {
                    if (parsedList[i].mid === p.mid) { out.idx = i; break; }
                }
                if (Array.isArray(data) && data[0]) {
                    out.url = data[0].url || '';
                } else if (data && typeof data === 'object') {
                    out.url = data.url || '';
                }
                return out;
            }).catch(function() { return {idx: -1, url: '', lrc: ''}; });
        });
        return Promise.all(tasks).then(function(results) {
            var byIdx = {};
            for (var i = 0; i < results.length; i++) {
                if (results[i].idx >= 0) byIdx[results[i].idx] = results[i].url;
            }
            var out = [];
            for (var j = 0; j < parsedList.length; j++) {
                // 解析不到时回退接口原样给的 url：上游 type=url 是 302 跳转（不是 JSON），解析可能为空
                var u = byIdx[j] || parsedList[j].rawUrl || '';
                u = MP._httpsUrl(u);
                out.push({
                    idx: j,
                    url: u,
                    lrc: parsedList[j].lrcUrl || '',
                });
            }
            return out;
        });
    };

// 识别两种歌词返回形态
    MP._isInlineLrc = function(v) {
        if (!v || typeof v !== 'string') return false;
        var s = v.trim();
        if (/\[\d{1,3}:\d{1,2}(?:[.:]\d{1,3})?\]/.test(s)) return true;
        return s.charAt(0) === '{' && /"(?:lyric|lrc)"/.test(s);
    };

    MP._unwrapLrc = function(data) {
        if (typeof data === 'string') {
            var s = data.replace(/^\uFEFF/, '').trim();
            if (s.charAt(0) === '{' || s.charAt(0) === '[') {
                try {
                    var obj = JSON.parse(s);
                    if (obj && typeof obj === 'object' && !Array.isArray(obj)) {
                        return obj.lyric || (obj.lrc && (obj.lrc.lyric || obj.lrc)) || '';
                    }
                } catch (e) { /* 不是 JSON：按纯文本处理 */ }
            }
            return data;
        }
        if (data && typeof data === 'object' && !Array.isArray(data)) {
            return data.lyric || (data.lrc && (data.lrc.lyric || data.lrc)) || '';
        }
        return '';
    };

    MP._sourceResolveLrcs = function(lrcTasks, server, signal) {
        var tasks = lrcTasks.map(function(t) {
            if (!t.lrc) return Promise.resolve({idx: t.idx, lrc: ''});
            if (MP._isInlineLrc(t.lrc)) return Promise.resolve({idx: t.idx, lrc: t.lrc});
            // 接口可能返回 http 链接：HTTPS 页面里 fetch(http) 属于会被浏览器直接拦截的混合内容
            var lrcUrl = /^http:\/\//i.test(t.lrc) ? t.lrc.replace(/^http:/i, 'https:') : t.lrc;
            return MP._fetchWithTimeout(lrcUrl, signal, 8000, true).then(function(data) {
                return {idx: t.idx, lrc: MP._unwrapLrc(data)};
            }).catch(function() { return {idx: t.idx, lrc: ''}; });
        });
        return Promise.all(tasks).then(function(results) {
            var byIdx = {};
            for (var i = 0; i < results.length; i++) {
                if (results[i].idx >= 0 && results[i].lrc) byIdx[results[i].idx] = results[i].lrc;
            }
            return byIdx;
        });
    };

// 歌曲数据加载

    MP._signal = function() {
        return MP._abort ? MP._abort.signal : undefined;
    };

    MP._markLoaded = function(idx, list) {
        MP._allSongs[idx] = list || [];
        if (!MP._loadFailed) MP._loadFailed = {};
        if (!MP._timeoutFailed) MP._timeoutFailed = {};
        delete MP._loadFailed[idx];          // 成功即清除失败标记
        delete MP._timeoutFailed[idx];
        if (!MP._playlistCovers[idx]) {
            var pl = MP.playlists[idx] || {};
            if (pl.cover_url) MP._playlistCovers[idx] = pl.cover_url;
            else if (list && list.length && list[0].pic) MP._playlistCovers[idx] = list[0].pic;
        }
    };

    // ---- 依次加载（解析好一首就点亮一首）----
    // 自定义歌单的曲目单在配置里就有（名字/歌手/顺序齐全），所以先把整单铺成占位渲染出来，
    // 再分批解析 url 与歌词；上百首的歌单不必等全部解析完才显示，也不用等全部解析完才能播。
    MP._playableSongs = function(list) {
        var out = [];
        (list || []).forEach(function(s){ if (s && s.url) out.push(s); });
        return out;
    };

    // 歌单条目 → APlayer 音频对象（身份字段供 cookie 记忆精确匹配）
    MP._apAudio = function(s) {
        s.server = s.server || MP._server || 'netease';
        return {
            name: s.name || '\u672a\u77e5', artist: s.artist || '', url: s.url, cover: s.pic || '', _lrc: s.lrc || '',
            _sv: s.server, _sid: s.id || '', _ref: MP._songRef(s)
        };
    };

    MP._apOrder = [];                        // 当前 APlayer 列表里各曲的身份（按列表顺序）

    // 重建 APlayer 列表（顺序＝歌单顺序）。只在「后到的歌位置更靠前」时才用：
    // 能顺序追加的走 add（不打断播放），否则整体重建并保留当前曲目与播放进度。
    //
    // 「保留进度」只对**切过去的就是原来在播的那首**成立。selectRef 是用户点去另一首时
    // （见 ui.js 点歌：跨歌单点歌走这里），重建后停在的是新歌，若还按 keepTime 写回，
    // 就会把上一首的播放位置套到新歌上 —— 症状：切歌后新歌从上一首的位置开始播
    // （上一首听到 10 秒时切歌，新歌就从 10 秒开始）。所以只有同一首才续接进度。
    MP._rebuildApList = function(want, selectRef) {
        if (!MP.ap || !want || !want.length) return;
        var audio = MP.ap.audio;
        var curAudio = MP.ap.list.audios[MP.ap.list.index];
        var prevRef = curAudio ? curAudio._ref : '';        // 重建前正在播的那首
        var keepRef = selectRef || prevRef;                 // 重建后要停在的那首
        var sameTrack = !!keepRef && keepRef === prevRef;   // 还是同一首：才允许续上进度
        var keepTime = (sameTrack && audio && !audio.paused) ? (audio.currentTime || 0) : 0;
        var wasPlaying = !!(audio && !audio.paused);
        MP.ap.list.clear();
        MP._apOrder = [];
        var audios = [];
        for (var i = 0; i < want.length; i++) {
            audios.push(MP._apAudio(want[i]));
            MP._apOrder.push(MP._songRef(want[i]));
        }
        MP.ap.list.add(audios);
        var pos = 0, found = false;
        if (keepRef) {
            for (var k = 0; k < MP._apOrder.length; k++) {
                if (MP._apOrder[k] === keepRef) { pos = k; found = true; break; }
            }
        }
        MP.ap.list.switch(pos);
        if (sameTrack && found && wasPlaying && keepTime > 0.2 && audio) { try { audio.currentTime = keepTime; } catch (e) {} }
        if (wasPlaying) { try { MP.ap.play(); } catch (e) {} }
    };

    // 把解析进度并进 APlayer 列表：列表与歌单等长同序，**只追加缺失的尾部、原地把 url 填进已有对象**。
    // 为什么不能重排/重建：cookie 记住的那首通常先到且位置靠后（比如第 175 首），随后每一批歌都在它
    // 前面。整体重建（clear + add）会让音频被反复清空重载、当前曲指针来回跳 —— 听起来一顿一顿、
    // 封面一直闪（2026-10 用户实测反馈）；而直接重排 list.audios 又会让 DOM 与数组错位（切歌时
    // APlayer 取不到对应 li 会抛错）。原地填数据两头都不碰：对象身份不变、src 不变、不触发 listswitch。
    // 返回列表是否有变化。
    MP._syncPlayerList = function(idx) {
        if (MP._destroyed || !MP.ap || !MP.ap.list) return false;
        var want = MP._allSongs[idx] || [];
        if (!want.length) return false;
        var audios = MP.ap.list.audios || [];
        var changed = false, i;
        if (audios.length < want.length) {                  // 尾部补齐：占位也进列表，DOM 与数组必须等长
            var add = [];
            for (i = audios.length; i < want.length; i++) add.push(MP._apAudio(want[i]));
            try { MP.ap.list.add(add); } catch (e) { return false; }
            audios = MP.ap.list.audios || [];
            MP._apOrder = audios.map(function(a){ return MP._songRef(a); });
            changed = true;
        }
        for (i = 0; i < want.length && i < audios.length; i++) {   // 原地填数据：对象不变、音频不重载
            var s = want[i], a = audios[i];
            if (!s || !a) continue;
            if (s.url && a.url !== s.url) { a.url = s.url; changed = true; }
            if (s.pic && a.cover !== s.pic) a.cover = s.pic;
            if (s.lrc && a._lrc !== s.lrc) a._lrc = s.lrc;
            if (s.name && a.name !== s.name) a.name = s.name;
            if (s.artist && a.artist !== s.artist) a.artist = s.artist;
        }
        // 当前指向的那首刚刚解析出来（换到新歌单时第一首还没解析好，src 还是空的）：补一次 switch 装上它。
        // 正常播放途中 current src 与当前曲目一致，这里不会触发，所以不会打断正在响的那首。
        var cur = audios[MP.ap.list.index];
        if (cur && cur.url) {
            var el = MP.ap.audio;
            var curSrc = el && el.getAttribute ? el.getAttribute('src') : null;
            if (el && curSrc !== cur.url) { try { MP.ap.list.switch(MP.ap.list.index); } catch (e) {} }
        }
        return changed;
    };

    // 上/下一首：懒加载之后**不再跳过任何条目** —— 每一条都能按需取到地址，
    // 所以直接切到相邻那一首（必要时绕回开头），地址由 onSwitch → _ensureSlotUrl 现场取。
    // （旧的"跳过没解析出来的条目"在懒加载下会把整单一路跳过去，等于上/下一首失灵。）
    MP._skipResolved = function(dir) {
        if (!MP.ap || !MP.ap.list) return false;
        var audios = MP.ap.list.audios || [], n = audios.length;
        if (n < 2) return false;
        var k = ((MP.ap.list.index + dir) % n + n) % n;
        try { MP.ap.list.switch(k); } catch (e) { return false; }
        return true;
    };

    // 解析进度：封面、APlayer 列表、待播曲目、加载门禁、列表视图一起跟上
    MP._songsRenderTimer = null;
    MP._scheduleSongsRender = function() {
        if (MP._songsRenderTimer) return;
        MP._songsRenderTimer = setTimeout(function(){
            MP._songsRenderTimer = null;
            if (!MP._destroyed && typeof MP.renderSonglist === 'function') MP.renderSonglist();
        }, 120);
    };

    MP._onSongsProgress = function(idx) {
        if (MP._destroyed) return;
        var list = MP._allSongs[idx] || [];
        if (!MP._playlistCovers[idx]) {              // 门禁只等「当前歌单封面」：哪张图先到先用
            for (var i = 0; i < list.length; i++) {
                if (list[i] && list[i].url && list[i].pic) { MP._playlistCovers[idx] = list[i].pic; break; }
            }
        }
        if (idx === MP.currentPlaylistIndex) {
            // 还没建播放器（启动之后这个歌单一首都没解析出来）：第一首到了就建
            if (!MP.ap && !MP._loading && (MP._allSongs[idx] || []).length) MP.initPlayer(MP._allSongs[idx]);
            if (MP.ap) MP._syncPlayerList(idx);
            MP._resolvePendingPicks();
            // 换到新歌单：不等"第一首解析出来"，直接按需取当前指向的那一首（懒加载）
            if (MP._autoplayOnReady && !MP._loading && MP.ap && MP.ap.list && MP.ap.list.audios.length) {
                MP._autoplayOnReady = false;
                MP._ensureSlotUrl(idx, MP.ap.list.index, true);
            }
        }
        MP._updateLoadingState();
        if (MP._viewingPlaylist || MP._viewingPlaylistIndex === idx || idx === MP.currentPlaylistIndex) {
            MP._scheduleSongsRender();
        }
    };

    MP._restorePendingRef = '';      // cookie 记住的那首还没解析出来：先等它，别让记忆被冲掉
    MP._pendingPlayRef = '';         // 用户点了还没解析好的那一行：到货后直接播它
    MP._pendingPlayIdx = -1;         // 上面那首属于哪个歌单（换歌单时用来判断该不该把它丢掉）
    MP._userPicked = false;          // 用户自己选过歌了：不再把播放位置拉回记忆里的那首
    MP._userNavigated = false;       // 用户自己翻过歌单/列表了：启动流程不许再把他正在看的视图拽走
    MP._autoplayOnReady = false;     // 换到还没解析出歌的歌单：第一首到货就开播
    MP._priorityWaiting = false;     // cookie 记住的那首还在路上：先别开播（门禁见 _essentialLoaded）

    // cookie 记住的那首如果已经不在这个歌单里，就别再拦着状态保存
    MP._finishPriorityWait = function() {
        if (!MP._restorePendingRef) return;
        if (MP._audioIndexOf && MP._audioIndexOf(MP._restorePendingRef) >= 0) return;
        MP._restorePendingRef = '';
    };

    MP._resolvePendingPicks = function() {
        if (MP._destroyed || !MP.ap || !MP._audioIndexOf) return;
        if (MP._restorePendingRef) {
            var ri = MP._audioIndexOf(MP._restorePendingRef);
            if (ri >= 0) {
                MP._restorePendingRef = '';
                if (!MP._userPicked) {
                    // 懒加载：这一首可能还没取地址 → 先按需取，取到再切过去（是否开播仍由自动播放规则决定）
                    MP._ensureSlotUrl(MP.currentPlaylistIndex, ri, false).then(function(){
                        if (MP._destroyed || !MP.ap) return;
                        try { MP.ap.list.switch(ri); } catch (e) {}
                        MP._maybeStartPlayback();
                    });
                }
                return;
            }
        }
        if (MP._pendingPlayRef) {
            var pi = MP._audioIndexOf(MP._pendingPlayRef);
            if (pi >= 0) {
                MP._pendingPlayRef = '';
                var pIdx = MP._pendingPlayIdx;
                MP._pendingPlayIdx = -1;
                if (pIdx < 0) pIdx = MP.currentPlaylistIndex;
                // 用户自己点的那一首：按需取到地址后立刻响（加载态不该压住点播）
                MP._ensureSlotUrl(pIdx, pi, true);
            }
        }
    };

    // 用户点了还没解析好的那一行：立刻把它拉到队首解析（失败的行＝重试）
    MP._prioritizeSong = function(idx, slotIdx) {
        var song = (MP._allSongs[idx] || [])[slotIdx];
        if (!song) return;
        MP._userPicked = true;
        MP._restorePendingRef = '';
        MP._pendingPlayRef = MP._songRef(song);
        MP._pendingPlayIdx = idx;
        if (song._failed) { song._failed = false; song._pending = true; }
        var job = MP._customJobs[idx];
        if (job && job.prioritize) job.prioritize(slotIdx);
        else if (!song.url) MP.ensurePlaylistLoaded(idx, function(){ if (!MP._destroyed) MP.renderSonglist(); });
        MP.renderSonglist();
    };

    // 自定义歌单（懒加载 / 点哪首取哪首）：
    //   ① 打开歌单只把曲目单铺成占位（名字/歌手/顺序配置里本来就有）—— **0 个解析请求**
    //   ② 用户点哪一首，才去取哪一首的播放地址（1 个 action=song 请求）
    //   ③ 播完自动切下一首、上/下一首，同样只取那一首
    //   ④ 只为"下一首"多预取一格，换来切歌秒开（每首最多多花 1 个请求）
    // 以前是"依次加载"：整单分批全解析（133 首要 6+ 次批量请求，服务端还要逐个回源），
    // 打开歌单就得等它把一百多首跑完 —— 请求数与流量都压在这一下，慢也慢在这里。
    MP._customJobs = {};                     // idx → { promise, prioritize(slotIdx), resolveSlot(slotIdx) }

    MP._customSlots = function(pl) {
        return ((pl && pl.songs) || []).map(function(d){
            // 封面优先：配置里带的封面（S3 直链 / 网关相对路径）立刻用上，
            // **不等解析播放地址** —— 列表一打开就该有图（这是这次改造的硬要求）。
            var pic = d.pic || '';
            if (pic && !/^https?:\/\//.test(pic)) pic = _.API_BASE + pic + '&token=' + encodeURIComponent(_.API_TOKEN || '');
            return {
                id: d.id,
                server: d.server || MP._server || 'netease',
                name: d.name || '\u672a\u77e5',
                artist: d.artist || '',
                url: '', pic: pic, lrc: '',
                _pending: true
            };
        });
    };

    MP._startCustomLoad = function(idx, pl, signal) {
        if (MP._customJobs[idx]) return MP._customJobs[idx].promise;
        var defs = (pl && pl.songs) || [];
        if (!defs.length) return Promise.resolve([]);
        var slots = MP._customSlots(pl);
        MP._markLoaded(idx, slots);              // 先铺满：名字/歌手/顺序都齐，界面立刻能显示整单

        // 单曲解析（source 直连模式也走这里）：结果统一成一行 {url,name,artist,pic,lrc}
        function fetchOne(def) {
            var s = def;
            var server = s.server || 'netease';
            if (MP._source) {
                var directUrl = MP._sourceURL('song', s.id, server);
                return MP._fetchWithTimeout(directUrl, signal, 10000).then(function(raw) {
                    var parsed = MP._parseSourceRaw(raw, server);
                    if (!parsed.length) throw new Error('no data');
                    var item = parsed[0];
                    return MP._sourceResolveUrls([item], server, signal).then(function(urlResults) {
                        return MP._sourceResolveLrcs([{idx: 0, lrc: item.lrcUrl || ''}], server, signal).then(function(lrcMap) {
                            var picUrl = '';
                            if (item.rawPic) {
                                if (/^https?:\/\//.test(item.rawPic)) picUrl = item.rawPic;
                                else if (item.picId) {
                                    var picParams = ['action=pic', 'server=' + encodeURIComponent(server), (MP._source.params.id || 'id') + '=' + encodeURIComponent(item.picId)];
                                    if (item.picAuth) picParams.push((MP._source.fields.auth || 'auth') + '=' + encodeURIComponent(item.picAuth));
                                    picUrl = _.API_BASE + '?' + picParams.join('&') + '&token=' + encodeURIComponent(_.API_TOKEN || '');
                                }
                            }
                            var u = urlResults[0] && urlResults[0].url ? urlResults[0].url : '';
                            var l = lrcMap[0] || '';
                            if (!u) throw new Error('no url');
                            return {name: item.title || '未知', artist: item.artist || '', url: u, pic: picUrl, lrc: l, id: item.mid};
                        });
                    });
                });
            }
            var phpUrl = _.API_BASE + '?action=song&id=' + encodeURIComponent(s.id)
                + '&server=' + encodeURIComponent(server)
                + '&token=' + encodeURIComponent(_.API_TOKEN)
                + (typeof MP._devParam === 'function' ? MP._devParam() : '');
            return fetch(phpUrl, {credentials:'same-origin', signal: signal || MP._signal()})
                .then(function(r){
                    if (!r.ok) throw new Error('http ' + r.status);
                    return r.json();
                })
                .then(function(data){
                    if (Array.isArray(data) && data.length > 0 && data[0] && data[0].url) {
                        var song = data[0];
                        if (song.pic && !/^https?:\/\//.test(song.pic)) song.pic = _.API_BASE + song.pic + '&token=' + encodeURIComponent(_.API_TOKEN);
                        return song;
                    }
                    throw new Error('empty');
                });
        }

        // 一行解析结果落到对应槽位（按下标落位，保持歌单顺序）
        function applyRow(slotIdx, row) {
            var song = slots[slotIdx];
            if (!song) return false;
            if (!row || !row.url) { song._pending = false; song._failed = true; return false; }
            if (row.name) song.name = row.name;
            if (row.artist) song.artist = row.artist;
            song.url = row.url;
            song.pic = row.pic || '';
            song.lrc = row.lrc || '';
            if (song.pic && !/^https?:\/\//.test(song.pic)) song.pic = _.API_BASE + song.pic + '&token=' + encodeURIComponent(_.API_TOKEN);
            song._pending = false;
            song._failed = false;
            return true;
        }

        // （原来这里有 fetchBatch / fetchSlots 两个"整单批量解析"函数：
        //   打开歌单就按 25 首一批把整单全解析掉。懒加载后不再需要 —— 见 resolveSlot）

        var aborted = false;
        var job = { promise: null, prioritize: null, resolveSlot: null, done: true };

        function notify() {
            if (MP._destroyed) return;
            MP._onSongsProgress(idx);
        }

        // 单曲解析（这套懒加载的唯一取数入口）：
        //   已经取到 → 直接用；正在取 → 复用同一个 promise（连点两下不会发两次请求）
        function resolveSlot(slotIdx) {
            var song = slots[slotIdx];
            if (!song) return Promise.resolve();
            if (song.url) return Promise.resolve();
            if (song._inflight) return song._inflight;
            song._pending = true;
            var p = fetchOne(defs[slotIdx]).then(function(row){
                applyRow(slotIdx, row);
            }).catch(function(err){
                if (err && err.name === 'AbortError') aborted = true;
                if (song && !song.url) { song._pending = false; song._failed = true; }
            }).then(function(){
                song._inflight = null;
                notify();
            });
            song._inflight = p;
            return p;
        }
        job.resolveSlot = resolveSlot;
        job.prioritize = function(slotIdx) { resolveSlot(slotIdx).then(function(){ notify(); }); };

        // 铺单即"就绪"：门禁（_essentialLoaded）只看曲目单有没有铺出来，不再等任何一首解析完。
        // 这是懒加载能立刻结束加载态、并且点歌不被加载流程挡住的关键。
        job.promise = Promise.resolve(slots);

        // 只预取"要播的那一首"（cookie 记住的优先，否则第一首）—— 登录后自动播放不必干等。
        var prioIdx = -1;
        if (MP._priorityRef) {
            for (var p = 0; p < defs.length; p++) {
                var psv = defs[p].server || MP._server || 'netease';
                if (MP._songRef({ server: psv, id: defs[p].id }) === MP._priorityRef) { prioIdx = p; break; }
            }
        }
        var firstIdx = prioIdx >= 0 ? prioIdx : 0;
        var holdGate = (idx === MP._startupIdx && prioIdx >= 0);
        if (holdGate) MP._priorityWaiting = true;         // 门禁等它（只有一条请求）：自动播放的第一首就是它
        var gateTmr = holdGate ? setTimeout(function(){
            MP._priorityWaiting = false;
            MP._updateLoadingState();
        }, 8000) : null;
        resolveSlot(firstIdx).then(function(){
            if (gateTmr) clearTimeout(gateTmr);
            if (holdGate) MP._priorityWaiting = false;
            MP._finishPriorityWait();
            MP._updateLoadingState();
        });

        MP._customJobs[idx] = job;
        return job.promise;
    };

    // 按需取某一首的播放地址（懒加载核心出口）。任何"真的要播这一首"的路径都走它：
    //   列表点歌 / 自动切下一首 / 上一下一首 / cookie 恢复 / 换歌单后的首播。
    // 取到之后再同步 APlayer 列表并开播；已经在取的路上就等它，不会重复请求。
    MP._ensureSlotUrl = function(idx, slotIdx, autoplay) {
        if (MP._destroyed) return Promise.resolve(false);
        var list = MP._allSongs[idx] || [];
        var song = list[slotIdx];
        if (!song) return Promise.resolve(false);
        if (song.url) { if (autoplay) MP._playSlotWhenReady(idx, slotIdx); MP._prefetchNextSlot(idx, slotIdx); return Promise.resolve(true); }
        var job = MP._customJobs[idx];
        var p = (job && job.resolveSlot) ? job.resolveSlot(slotIdx) : Promise.resolve();
        MP._scheduleSongsRender();
        return Promise.resolve(p).then(function(){
            if (MP._destroyed) return false;
            var ok = !!song.url;
            MP._onSongsProgress(idx);
            if (ok) { if (autoplay) MP._playSlotWhenReady(idx, slotIdx); MP._prefetchNextSlot(idx, slotIdx); }
            else MP._scheduleSongsRender();
            return ok;
        });
    };

    // 预取下一首（只预取一格，且只在还没取过的时候）：切歌秒开的代价是每首最多多 1 个请求
    MP._prefetchNextSlot = function(idx, fromSlot) {
        if (MP._destroyed) return;
        var list = MP._allSongs[idx] || [];
        var n = list.length;
        if (n < 2) return;
        var k = ((fromSlot + 1) % n + n) % n;
        if (k === fromSlot) return;
        var nxt = list[k];
        if (!nxt || nxt.url || nxt._inflight) return;
        var job = MP._customJobs[idx];
        if (job && job.resolveSlot) job.resolveSlot(k);
    };

    // 让某一首真正响起来：把刚取到的 url 同步进 APlayer 列表，切到它并 play。
    // （列表与歌单等长同序，所以下标一一对应；如果已经指向它、只是 src 之前是空的，补一次 switch 装上）
    MP._playSlotWhenReady = function(idx, slotIdx) {
        if (MP._destroyed || !MP.ap || !MP.ap.list) return;
        if (idx !== MP.currentPlaylistIndex) return;              // 歌单已经换走：别再把它拽回来
        var list = MP._allSongs[idx] || [];
        var song = list[slotIdx];
        if (!song || !song.url) return;
        MP._syncPlayerList(idx);
        var audios = MP.ap.list.audios || [];
        var pos = MP._audioIndexOf ? MP._audioIndexOf(MP._songRef(song)) : -1;
        if (pos < 0 || pos >= audios.length) pos = slotIdx;
        var cur = audios[MP.ap.list.index];
        if (cur && MP._songRef(cur) === MP._songRef(song)) {
            var el = MP.ap.audio;
            var curSrc = el && el.getAttribute ? el.getAttribute('src') : null;
            if (curSrc !== song.url) { try { MP.ap.list.switch(MP.ap.list.index); } catch (e) {} }
        } else {
            try { MP.ap.list.switch(pos); } catch (e) {}
        }
        try { MP.ap.play(); } catch (e) {}
    };

    // 兼容旧调用名（引擎内部统一走 MP._startCustomLoad）
    MP._loadCustomPlaylist = function(pl, signal) {
        return MP._startCustomLoad(MP.currentPlaylistIndex, pl, signal);
    };

    // 远端歌单 / 单曲列表：单次请求（失败必须抛出，否则“失败→重试”路径永远不可达）
    MP._loadRemotePlaylist = function(pl, action, signal) {
        var server = pl.server || 'netease';
        if (MP._source) {
            var directUrl = MP._sourceURL(action || 'playlist', pl.id, server);
            var limit = Math.min(pl.limit || 30, 50);
            if (directUrl && limit !== 30) {
                var sep = directUrl.indexOf('?') >= 0 ? '&' : '?';
                directUrl = directUrl + sep + 'limit=' + limit;
            }
            var parsedList = null;
            return MP._fetchWithTimeout(directUrl, signal, 10000).then(function(raw) {
                parsedList = MP._parseSourceRaw(raw, server);
                var resolveTasks = [];
                for (var i = 0; i < parsedList.length; i++) {
                    resolveTasks.push({idx: i, lrc: parsedList[i].lrcUrl || ''});
                }
                return MP._sourceResolveUrls(parsedList, server, signal).then(function(urlResults) {
                    return MP._sourceResolveLrcs(resolveTasks, server, signal).then(function(lrcMap) {
                        var result = [];
                        for (var j = 0; j < parsedList.length; j++) {
                            var plItem = parsedList[j];
                            var u = urlResults[j] && urlResults[j].url ? urlResults[j].url : '';
                            var l = lrcMap[j] || '';
                            var picUrl = '';
                            if (plItem.rawPic) {
                                if (/^https?:\/\//.test(plItem.rawPic)) {
                                    picUrl = plItem.rawPic;
                                } else if (plItem.picId) {
                                    var picParams = ['action=pic', 'server=' + encodeURIComponent(server), (MP._source.params.id || 'id') + '=' + encodeURIComponent(plItem.picId)];
                                    if (plItem.picAuth) picParams.push((MP._source.fields.auth || 'auth') + '=' + encodeURIComponent(plItem.picAuth));
                                    picUrl = _.API_BASE + '?' + picParams.join('&') + '&token=' + encodeURIComponent(_.API_TOKEN || '');
                                }
                            }
                            result.push({
                                // 取歌曲身份
                                id: plItem.mid || ((plItem.title || '') + '|' + (plItem.artist || '')),
                                server: server,
                                name: plItem.title || '未知',
                                artist: plItem.artist || '',
                                url: u,
                                pic: picUrl,
                                lrc: l,
                            });
                        }
                        return result.slice(0, limit || 50);
                    });
                });
            });
        }
        var url = _.API_BASE + '?action=' + action + '&id=' + encodeURIComponent(pl.id)
            + '&limit=30&server=' + encodeURIComponent(server)
            + '&token=' + encodeURIComponent(_.API_TOKEN)
            + (typeof MP._devParam === 'function' ? MP._devParam() : '');
        return fetch(url, {credentials:'same-origin', signal: signal || MP._signal()})
            .then(function(r){
                if (!r.ok) throw new Error('http ' + r.status);
                return r.json();
            })
            .then(function(data){
                if (!Array.isArray(data)) throw new Error('bad payload');
                data.forEach(function(s){
                    s.server = server;                     // 身份需要：cookie 存的是 server_songId
                    if (s.pic && !/^https?:\/\//.test(s.pic)) s.pic = _.API_BASE + s.pic + '&token=' + encodeURIComponent(_.API_TOKEN);
                });
                return data;
            });
    };

    // 按需加载某个歌单（同一歌单只请求一次；加载中复用同一个 Promise；force=true 用于失败重试）
    MP.loadPlaylistSongs = function(idx, signal, force) {
        if (MP._destroyed) return Promise.resolve([]);
        if (MP._allSongs[idx] && !force) return Promise.resolve(MP._allSongs[idx]);
        if (MP._loadingPlaylists[idx]) return MP._loadingPlaylists[idx];
        var pl = MP.playlists[idx];
        if (!pl) return Promise.resolve([]);

        var pending;
        var plType = pl.type || 'playlist';
        if (plType === 'custom') {
            pending = MP._startCustomLoad(idx, pl, signal);
        } else if (!pl.id) {
            pending = Promise.resolve([]);
        } else {
            pending = MP._loadRemotePlaylist(pl, plType === 'song' ? 'song' : 'playlist', signal);
        }
        pending = pending.then(function(list){
            delete MP._loadingPlaylists[idx];
            if (MP._destroyed) return [];
            MP._markLoaded(idx, list);
            return MP._allSongs[idx];
        }).catch(function(err){
            delete MP._loadingPlaylists[idx];
            if (MP._destroyed) return [];
            // 为切页让路而被取消：不算失败，下次继续加载
            if (err && err.name === 'AbortError') return [];
            MP._markLoaded(idx, []);
            MP._loadFailed[idx] = true;
            return MP._allSongs[idx];
        });
        MP._loadingPlaylists[idx] = pending;
        return pending;
    };

    // 歌单列表点击时按需加载 / 手动重试（force 重新请求）
    MP.ensurePlaylistLoaded = function(idx, cb) {
        if (MP._destroyed) return;
        // 下标非法（面板在歌单确定前就被渲染）时什么都不做：不拉取、不改 UI、不回调 ——
        // 否则调用方 renderSonglist 会在「未加载过」分支里被立即回调，自调用成死循环
        if (!MP.playlists || !MP.playlists[idx]) return;
        if (MP._allSongs[idx] && !MP._loadFailed[idx]) { if (cb) cb(MP._allSongs[idx]); return; }
        var ttl = MP.$('ttl');
        if (ttl) ttl.textContent = '\u52a0\u8f7d\u4e2d...';
        MP.loadPlaylistSongs(idx, null, true).then(function(list){
            if (MP._destroyed) return;
            if (cb) cb(list);
        });
    };

    // 清除悬浮按钮的加载态（无歌可播/全部失败时也要立刻清掉，不必等 10s 兜底）
    MP._clearToggleLoading = function() {
        MP._loading = false;
        var t = MP.$('toggle');
        if (t) t.classList.remove('loading');
    };

    // “加载完成”＝每个歌单都已有结果（成功或失败）
    MP._allPlaylistsLoaded = function() {
        if (!MP.playlists || !MP.playlists.length) return true;
        for (var i = 0; i < MP.playlists.length; i++) {
            if (!MP._allSongs[i] && !MP._loadFailed[i]) return false;
        }
        return true;
    };

    // "必要加载"＝当前歌单的曲目单已经铺出来 + 各歌单封面就位。
    // 懒加载后**不再要求"至少有一首解析出来"**：播放地址改成点哪首取哪首（_ensureSlotUrl），
    // 所以铺单那一刻就算加载完成 —— 加载态秒结束，用户也不用等整单解析完才能点歌。
    MP._coverWaived = {};
    MP._essentialLoaded = function() {
        var cur = MP.currentPlaylistIndex;
        if (!(MP._allSongs[cur] && MP._allSongs[cur].length)) return false;   // 当前歌单曲目还没铺出来
        // cookie 记住的那首还在路上：先别开播，否则自动播放的第一首就不是它了（只有一条请求，很快）
        if (MP._priorityWaiting) return false;
        if (!MP.playlists || !MP.playlists.length) return true;
        for (var i = 0; i < MP.playlists.length; i++) {
            if (MP._playlistCovers[i] || MP._coverWaived[i]) continue;
            // 只有"配置里没存封面"的歌单才需要等首图；有封面就别为了它把加载态拖住
            if (i === cur && !(MP.playlists[i] && MP.playlists[i].cover_url)) return false;
            MP._coverWaived[i] = true;
        }
        return true;
    };

    // 加载未完全时保持悬浮按钮的呼吸动效 (面板此时不可打开, 这是设计上的加载提示) ;
    MP._updateLoadingState = function() {
        if (MP._essentialLoaded() || (MP._loadDeadline && Date.now() > MP._loadDeadline)) {
            var wasLoading = MP._loading;
            MP._clearToggleLoading();
            // 刚刚加载完成（呼吸动效结束）：先完整显示一段时间再吸附收起，不要一结束就贴墙隐藏
            if (wasLoading) MP._scheduleAutoHide(MP._postLoadHideDelay);
            MP._maybeStartPlayback();      // 加载完成（或超时兜底）后才允许自动播放
            return;
        }
        var entering = !MP._loading;       // 是「刚进入加载态」还是「加载中又被通知了一次」
        MP._loading = true;
        MP._cancelAutoHide();              // 加载期间保持完整可见（不收起）
        var t = MP.$('toggle');
        if (t) { t.classList.add('loading'); t.classList.remove('playing'); }
        if (!MP._userPicked && MP.ap && MP.ap.audio && !MP.ap.audio.paused) { try { MP.ap.pause(); } catch(e) {} }
        // 只在刚进入加载态时收一次封面：依次加载期间每解析好一首都会调到这，反复清 src 会让封面一闪一闪
        if (entering) MP._hidePlayingCover();
    };

    // 隐藏“播放中”封面，恢复音符图标（加载完成前只允许这个状态）
    MP._hidePlayingCover = function() {
        var c = MP.$('toggleCover');
        if (c) { c.style.display = 'none'; c.src = ''; }
        var svg = MP.$('toggleSvg');
        if (svg) svg.style.display = '';
        MP._lastToggleCover = '';
    };

    // 全部歌单加载完成后，才按用户设置决定是否自动播放；若被浏览器拦截，则等用户首次交互再恢复
    MP._maybeStartPlayback = function() {
        if (MP._destroyed || MP._loading) return;
        if (!MP._wantAutoplay || MP._autoplayTried) return;
        MP._autoplayTried = true;
        if (!(MP.ap && MP.ap.audio && MP.ap.audio.paused)) return;
        var p = null;
        try { p = MP.ap.play(); } catch(e) {}
        var arm = function() { MP._armGestureResume(); };
        if (p && p.catch) p.catch(arm);
        setTimeout(function() {
            if (!MP._destroyed && MP.ap && MP.ap.audio && MP.ap.audio.paused) arm();
        }, 700);
    };

    // 浏览器自动播放被拦截时：用户首次点击/触摸/按键再开始播放
    MP._armGestureResume = function() {
        if (MP._gestureArmed) return;
        MP._gestureArmed = true;
        var _resume = function() {
            MP._gestureArmed = false;
            document.removeEventListener('click', _resume);
            document.removeEventListener('touchstart', _resume);
            document.removeEventListener('keydown', _resume);
            // 加载完成前一律不播放
            if (MP._destroyed || MP._loading) return;
            if (MP.ap && MP.ap.audio && MP.ap.audio.paused) {
                var p = MP.ap.play();
                if (p && p.catch) p.catch(function(){});
            }
        };
        document.addEventListener('click', _resume);
        document.addEventListener('touchstart', _resume);
        document.addEventListener('keydown', _resume);
    };

    // 启动：只加载将要播放的歌单；若它没有歌曲，再按顺序试下一个
    MP.loadInitialPlaylist = function() {
        if (MP._destroyed) return;
        if (!MP.playlists || MP.playlists.length === 0) {
            MP._showNoPlaylistNotice();
            return;
        }
        MP._loading = true;
        MP._loadDeadline = Date.now() + 30000;        // 30s 兜底：超时后不再阻止打开面板/播放
        // 歌单封面优先：配置里已带 cover_url 的直接用（0 请求），门禁只需要“当前歌单 + 封面”
        for (var ci = 0; ci < MP.playlists.length; ci++) {
            var plc = MP.playlists[ci];
            if (plc && plc.cover_url) MP._playlistCovers[ci] = plc.cover_url;
        }
        if (MP._loadDeadlineTimer) clearTimeout(MP._loadDeadlineTimer);
        MP._loadDeadlineTimer = setTimeout(function() {
            MP._loadDeadlineTimer = null;
            if (MP._destroyed) return;
            MP._updateLoadingState();                 // 主动解除加载态（剩余歌单仍会在后台继续补）
        }, 30000);
        var ttl = MP.$('ttl');
        if (ttl) ttl.textContent = '\u52a0\u8f7d\u4e2d...';

        var saved = MP._loadSavedPlaylistIndex();
        var start = (saved >= 0 && saved < MP.playlists.length) ? saved : 0;
        MP._startupIdx = start;
        // 歌单面板可能在任何时刻被打开并渲染：先把「正在查看的歌单」定下来。
        // 否则 renderSonglist 会拿 undefined 下标去自动拉取，而 loadPlaylistSongs 对未知下标
        // 直接 resolve([])，于是 renderSonglist 的回调又立刻重渲染 —— 形成停不下来的微任务
        // 自调用循环，把整个启动卡死（实测：页面主线程再也不响应）。
        if (MP._viewingPlaylistIndex === undefined || MP._viewingPlaylistIndex === null) MP._viewingPlaylistIndex = start;
        // cookie 记住的那首：它的优先权不能被「依次加载」的顺序破坏 —— 单独先取，到货先切
        MP._priorityRef = '';
        if (typeof MP._readState === 'function') {
            var _pst = MP._readState();
            if (_pst && _pst.rf) MP._priorityRef = _pst.rf;
        }
        var order = [start];
        for (var i = 0; i < MP.playlists.length; i++) { if (i !== start) order.push(i); }

        var pos = 0;
        function tryNext() {
            if (MP._destroyed) return;
            if (pos >= order.length) {
                MP.currentPlaylistIndex = start;
                MP.songs = MP._allSongs[start] || [];
                MP._updateLoadingState();       // 所有歌单都已尝试 → 结束呼吸动效（面板可打开，可手动重试）
                MP.renderSonglist();
                var t = MP.$('ttl');
                if (t) t.textContent = '\u6682\u65e0\u6b4c\u66f2';
                // 所有歌单都试过了还是没有歌：区分“接口/网络失败”和“歌单本来就是空的”
                if (!MP.songs.length && typeof MP.notice === 'function') {
                    var anyFailed = false;
                    for (var fk in MP._loadFailed) { if (MP._loadFailed[fk]) { anyFailed = true; break; } }
                    MP.notice(anyFailed ? 'playlist_failed' : 'no_songs');
                }
                return;
            }            var idx = order[pos++];
            MP.loadPlaylistSongs(idx).then(function(list){
                if (MP._destroyed) return;
                if (list && list.length) {
                    MP._loading = false;
                    // 用户在加载期间自己进了别的歌单 / 点了歌：启动流程只负责把播放器和后台预加载
                    // 带起来，不许再把他选中的歌单、正在看的列表改回「cookie 记住的那首」。
                    // （若强行改成 current，他点的那首再也不会被该歌单的解析进度送进播放器 ——
                    //   表现就是「加载时点了别的歌单，点了没反应」）
                    var _userChoice = MP._userPicked || MP._userNavigated;
                    if (!_userChoice || MP.currentPlaylistIndex === undefined || MP.currentPlaylistIndex === null) {
                        MP.currentPlaylistIndex = idx;
                        MP.songs = list;
                    }
                    if (!_userChoice) {
                        MP._viewingPlaylist = (MP.playlists && MP.playlists.length > 1);
                        MP._viewingPlaylistIndex = idx;
                    }
                    MP.initPlayer(list);
                    MP._preloadRest();          // 其余歌单在后台自动加载（无需手动点击）
                    return;
                }
                tryNext();
            });
        }
        tryNext();
    };

    // 后台自动预加载其余歌单: 并发池 + 让路机制.
    MP._preloadConcurrency = (typeof window.__mapiPrefetchConcurrency === 'number' && window.__mapiPrefetchConcurrency > 0)
        ? Math.min(window.__mapiPrefetchConcurrency, 6) : 3;

    // 宿主页切页时调用：立刻取消所有在途的“后台预加载”请求（不影响正在播放所需的请求），并暂停后续预加载
    MP.pausePreload = function() {
        window.__mapiPrefetchPaused = true;
        if (MP._preloadCtls) {
            for (var i = 0; i < MP._preloadCtls.length; i++) {
                try { MP._preloadCtls[i].abort(); } catch(e) {}
            }
        }
    };
    MP.resumePreload = function() {
        window.__mapiPrefetchPaused = false;
        MP._preloadResumeAt = Date.now() + 400;        // 刚切完页先安静一下，连续点击也不抢请求
        if (!MP._destroyed && !MP._preloadRunning) MP._preloadRest();
    };

    MP._preloadRest = function() {
        if (MP._destroyed || MP._preloadRunning) return;
        MP._preloadRunning = true;
        MP._preloadDone = false;
        MP._preloadCtls = [];
        var conc = Math.max(1, Math.min(MP._preloadConcurrency || 1, 6));
        var order = [];
        for (var i = 0; i < MP.playlists.length; i++) {
            if (i !== MP.currentPlaylistIndex) order.push(i);
        }
        var pos = 0, running = 0, retried = false;

        function finish() {
            if (!retried) {
                var failed = [];
                for (var k = 0; k < order.length; k++) {
                    if (MP._loadFailed[order[k]] && !MP._timeoutFailed[order[k]]) failed.push(order[k]);
                }
                if (failed.length) {                   // 失败的自动重试一次（用户无需手动点）
                    retried = true;
                    order = failed; pos = 0; running = 0;
                    setTimeout(pump, 3000);
                    return;
                }
            }
            MP._preloadRunning = false;
            MP._preloadIdx = null;
            MP._preloadDone = true;
            MP._updateLoadingState();                  // 全部歌单就绪 → 呼吸动效结束，面板可打开
            if (MP._viewingPlaylist) MP.renderSonglist();
        }

        function startOne(idx, isRetry) {
            running++;
            var ctl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            if (ctl) MP._preloadCtls.push(ctl);
            var sig = ctl ? ctl.signal : undefined;
            var timedOut = false;
            var toTimer = setTimeout(function() {       // 单请求 20s 上限，避免一直卡在加载态
                timedOut = true;
                if (ctl) { try { ctl.abort(); } catch(e) {} }
            }, 20000);
            MP._preloadIdx = idx;
            MP.loadPlaylistSongs(idx, sig, isRetry).then(function() {
                clearTimeout(toTimer);
                running--;
                if (MP._destroyed) { MP._preloadRunning = false; return; }
                var ci = MP._preloadCtls ? MP._preloadCtls.indexOf(ctl) : -1;
                if (ci >= 0) MP._preloadCtls.splice(ci, 1);
                if (sig && sig.aborted) {
                    if (timedOut) {                     // 超时：记为失败并继续，别卡住
                        MP._allSongs[idx] = [];
                        MP._loadFailed[idx] = true;
                        MP._timeoutFailed[idx] = true;
                        MP._updateLoadingState();
                        if (MP._viewingPlaylist) MP.renderSonglist();
                    } else {
                        order.push(idx);                // 为切页让路被取消：放回队尾，稍后补上
                    }
                    setTimeout(pump, 200);
                    return;
                }
                MP._updateLoadingState();
                if (MP._viewingPlaylist) MP.renderSonglist();
                setTimeout(pump, 150);                  // 短间隙，让页面请求有机会插队
            });
        }

        function pump() {
            if (MP._destroyed) { MP._preloadRunning = false; MP._preloadIdx = null; return; }
            if (window.__mapiPrefetchPaused) {           // 切页中：让路（最长 8s，避免卡死）
                if (!MP._pausedSince) MP._pausedSince = Date.now();
                if (Date.now() - MP._pausedSince < 8000) { setTimeout(pump, 250); return; }
                window.__mapiPrefetchPaused = false;
            }
            MP._pausedSince = 0;
            if (running === 0 && MP._preloadResumeAt && Date.now() < MP._preloadResumeAt) { setTimeout(pump, 250); return; }
            while (running < conc) {
                var idx = -1;
                while (pos < order.length) {
                    var cand = order[pos++];
                    if (MP._allSongs[cand] && !MP._loadFailed[cand]) continue;                       // 已有数据
                    if (retried && MP._loadFailed[cand] && MP._timeoutFailed[cand]) continue;        // 超时过的不再重试
                    idx = cand; break;
                }
                if (idx < 0) break;
                startOne(idx, retried);
            }
            if (running === 0 && pos >= order.length) finish();
        }

        setTimeout(pump, 300);                          // 先让首屏（当前歌单）稳定
    };

    // 兼容旧调用（配置更新等场景）：重新走一次启动加载流程
    MP.fetchAllPlaylists = function() {
        MP.loadInitialPlaylist();
    };

    MP.switchPlaylist = function(index) {
        if (index < 0 || index >= MP.playlists.length) return;
        if (index === MP.currentPlaylistIndex && MP.songs && MP.songs.length) return;
        MP.ensurePlaylistLoaded(index, function(list){
            if (MP._destroyed) return;
            MP.currentPlaylistIndex = index;
            MP.songs = list || [];
            if (MP.songs.length === 0) {
                var t = MP.$('ttl');
                if (t) t.textContent = '\u6682\u65e0\u6b4c\u66f2';
                MP.renderSonglist();
                MP.saveState();
                return;
            }
            // 换歌单是用户自己的选择：让位给用户，别再把播放位置拉回 cookie 记住的那首
            MP._userPicked = true;
            MP._restorePendingRef = '';
            // 只丢掉「别的歌单」留下的待播曲目。用户点了本歌单里还没解析出来的那首时，
            // switchPlaylist 的这次回调是异步来的（歌单数据还在路上），若无条件清空，
            // 这一下就把他的点击吞了 —— 到货后也没人再把它切过去（点了像没反应）。
            if (MP._pendingPlayIdx !== index) MP._pendingPlayRef = '';
            var want = MP.songs || [];
            if (MP.ap) {
                if (want.length) {
                    MP._rebuildApList(want);                 // 整单进列表（含未解析的占位）：下标与歌单对齐
                    // 懒加载：不再"等第一首解析出来再播"，直接按需取当前指向的那一首
                    MP._autoplayOnReady = false;
                    MP._ensureSlotUrl(index, MP.ap.list.index, true);
                } else {
                    // 新歌单连曲目单都没有：先停住
                    MP.ap.list.clear();
                    MP._autoplayOnReady = true;
                }
                MP.renderSonglist();
            } else {
                MP.initPlayer(MP.songs);
            }
            // 列表刚换好：用户点的那首若已解析出来，就地切过去并开播（可能是等这个歌单数据时才到货的）
            MP._resolvePendingPicks();
            MP.saveState();
        });
    };

    MP.initPlayer = function(songs) {
        if (MP._destroyed) return;
        if (MP.ap) return;                       // 播放器只有一个：依次加载期间重复调用不再新建第二个
        var audios = [];
        MP._apOrder = [];
        // 列表与歌单**等长同序**（还没解析出来的先占位，url 为空）：APlayer 的 list.switch() 会按
        // 下标去取 DOM 里的 .aplayer-list li 加高亮类，而 DOM 只在 list.add() 时追加 —— 数组与 DOM
        // 必须一一对应，否则切到靠后的下标会直接抛 TypeError（2026-10 实测）。
        // 未解析的占位条目由 MP._skipResolved() 跳过（上/下一首不会停在空 src 上）。
        songs.forEach(function(s){
            s.pic = s.pic || '';
            s.server = s.server || MP._server || 'netease';
            audios.push(MP._apAudio(s));
            MP._apOrder.push(MP._songRef(s));
        });
        if (audios.length === 0) return;

        var apContainer = MP.$('apContainer');
        if (!apContainer) return;

        MP.ap = new APlayer({
            container: apContainer,
            audio: audios,
            mini: false, autoplay: false, theme: '#888',       // 加载完成前一律不自动播放（见 _maybeStartPlayback）
            loop: 'all', order: 'list', preload: 'none', volume: 1.0, mutex: true
        });
        MP._wantAutoplay = !!MP._autoplayDefault;              // 记住用户设置，等全部加载完成后再决定
        if (MP.ap.audio) MP.ap.audio.crossOrigin = 'anonymous';

        // 忽略播放 Promise 的中断拒绝
        if (typeof MP.ap.play === 'function') {
            var _apPlay = MP.ap.play.bind(MP.ap);
            MP.ap.play = function() {
                var p = _apPlay();
                if (p && typeof p.catch === 'function') p.catch(function(){});
                return p;
            };
        }

        MP.ap.on('play', function(){
            // 加载完成前一律不播放（防御性：任何来源触发的播放都立即停掉）；
            // 例外：用户自己点的那首（_userPicked）—— 依次加载下整单可能还在后台解析，不该压住点播
            if (MP._loading && !MP._userPicked) { try { MP.ap.pause(); } catch(e) {} MP._hidePlayingCover(); return; }
            MP._autoplayTried = true;
            MP.updateUI(); MP.renderSonglist(); MP.updatePlayBtn(true); MP.loadLrc(); MP.syncLrc(); if(MP._imOpen){MP.updateImmersiveUI();MP.updateImmersivePlayBtn(true);} var tg=MP.$('toggle');if(tg){tg.classList.add('playing');} MP._updateLoadingState(); MP.updateMediaSession(); });
        MP.ap.on('pause', function(){ MP.updateUI(); MP.updatePlayBtn(false); if(MP._imOpen){MP.updateImmersivePlayBtn(false);} var tg=MP.$('toggle');if(tg){tg.classList.remove('playing');} MP._updateLoadingState(); });
        MP.ap.on('timeupdate', function(){ MP.updateProgress(); MP.syncLrc(); if(MP._imOpen){MP.updateImmersiveProgress();MP.updateImmersiveLrc();} if(!MP._ts||Date.now()-MP._ts>5000){MP._ts=Date.now();MP.saveState();} });
        MP.ap.on('ended', function(){ MP.onEnded(); });
        MP.ap.on('listswitch', function(){ MP.onSwitch(); if(MP._imOpen)MP.updateImmersiveUI(); MP.updateMediaSession(); });
        // 延后一拍保存状态
        MP.ap.on('listswitch', function(){ setTimeout(function(){ if (!MP._destroyed) MP.saveState(); }, 60); });

        if ('mediaSession' in navigator) {
            navigator.mediaSession.setActionHandler('play', function(){ if(MP.ap){ MP.ap.play(); } });
            navigator.mediaSession.setActionHandler('pause', function(){ if(MP.ap){ MP.ap.pause(); } });
            navigator.mediaSession.setActionHandler('previoustrack', function(){ if(MP.ap){ MP._userPicked = true; MP._skipResolved(-1); setTimeout(function(){ MP.onSwitch(); }, 200); } });
            navigator.mediaSession.setActionHandler('nexttrack', function(){ if(MP.ap){ MP._userPicked = true; MP._skipResolved(1); setTimeout(function(){ MP.onSwitch(); }, 200); } });
        }

        // 自动播放统一在“全部歌单加载完成”后由 MP._maybeStartPlayback() 处理（含被拦截时的交互恢复）
        if (MP._autoplayDefault && MP.ap && MP.ap.audio) {
            setTimeout(function() {
                if (!MP._destroyed && !MP._loading) MP._maybeStartPlayback();
            }, 300);
        }

        MP._resizeLrc = function(){
            if (MP._destroyed) return;
            var el = document.querySelector('[data-mp="lrc"]');
            if (el) {
                var px = MP._getOverlapBottom();
                el.style.bottom = px + 'px';
            }
            if (!MP._destroyed) MP._snap();
            var pnl = MP.$('panel');
            if (pnl) {
                var isMob = window.innerWidth <= 768;
                pnl.style.width = isMob ? '280px' : Math.min(360, window.innerWidth - 30) + 'px';
            }
            var host = MP._hostRoot && MP._hostRoot.host;
            if (host) {
                var mr = window.innerWidth <= 768 ? 4 : 15;
                var mb = window.innerWidth <= 768 ? 35 : 50;
                var _t = host.style.top;
                if (!_t || _t === '' || _t === 'initial' || _t === 'auto') {
                    // 底部锚定：尊重后台设置的默认位置（没有默认值时退回安全值 + 宿主底栏高度）
                    var _ib = (typeof MP._getPlayerInset === 'function') ? (MP._getPlayerInset() || 0) : 0;
                    host.style.bottom = (MP._posBottom ? MP._posBottom : (mb + (_ib > 0 ? _ib : 0))) + 'px';
                }
            }
            // 沉浸式打开时重算歌词居中
            if (MP._imOpen && typeof MP.updateImmersiveLrc === 'function') {
                try { MP.updateImmersiveLrc(); } catch (e) {}
            }
        };
        window.addEventListener('resize', MP._resizeLrc);

        MP._domTimer = null;
        MP._domObs = new MutationObserver(function(mutations){
            var lrc = document.querySelector('[data-mp="lrc"]');
            if (lrc) {
                var skip = true;
                for (var i = 0; i < mutations.length; i++) {
                    if (!lrc.contains(mutations[i].target)) { skip = false; break; }
                }
                if (skip) return;
            }
            clearTimeout(MP._domTimer);
            MP._domTimer = setTimeout(function(){
                var el = document.querySelector('[data-mp="lrc"]');
                if (el) {
                    var px = MP._getOverlapBottom();
                    el.style.bottom = px + 'px';
                }
            }, 500);
        });
        if (document.body) {
            MP._domObs.observe(document.body, { childList: true, subtree: true });
        }

        MP.renderSonglist();
        MP.showToggle();
        MP._applyDefaultPos();
        MP.updateUI();
        MP.updatePlayBtn(false);
        MP.$('vol').value = 1.0;
        if(MP.ap) MP.ap.volume(1.0);
        var _imVol = MP.$('imVol');
        if(_imVol) _imVol.value = 1.0;

        var _songToRestore = -1;
        if (MP._cookieConsented) {
            // 新格式（mapi_state）：按身份恢复 —— 后台重排歌单/歌曲、改歌单名都不会错位
            var _st = MP._readState ? MP._readState() : null;
            var _savedMode = (_st && _st.md) || _.getCookie('mapi_mode');
            if (_savedMode && ['list','single','random'].indexOf(_savedMode)>=0) {
                MP.mode = _savedMode;
                var _loopMap = {single:'one', list:'all', random:'none'};
                if (MP.ap) MP.ap.options.loop = _loopMap[_savedMode] || 'none';
                var _trig = MP.$('modeTrigger');
                if (_trig) { var _labels={list:'\u5217\u8868\u64ad\u653e',single:'\u5355\u66f2\u5faa\u73af',random:'\u968f\u673a\u64ad\u653e'}; _trig.textContent = _labels[_savedMode] || _savedMode; }
                var _menu = MP.$('modeMenu');
                if (_menu) { _menu.querySelectorAll('.mode-option').forEach(function(o){ o.classList.toggle('active', o.getAttribute('data-mode')===_savedMode); }); }
            }
            if (_st && _st.rf && MP.ap && MP.ap.list) {
                // 精确匹配 server_songId; 匹配不到 (那首歌被删了/歌单变了) 就留在该歌单第 1 首,
                var _ri = MP._audioIndexOf(_st.rf);
                if (_ri >= 0) {
                    MP.ap.list.switch(_ri);
                    _songToRestore = _ri;
                } else {
                    // 依次加载时它可能还在路上：先记住，到货就切过去；这段时间不让 saveState 把记忆冲掉
                    MP._restorePendingRef = _st.rf;
                    setTimeout(function(){
                        if (MP._restorePendingRef !== _st.rf) return;
                        MP._restorePendingRef = '';              // 等太久（歌被删/一直在失败）：放弃，恢复正常的记忆更新
                        if (typeof MP.saveState === 'function') MP.saveState(true);
                    }, 15000);
                }
            } else {
                var _savedSong = _.getCookie('mapi_song');     // 旧 cookie（下标）：一次性兼容
                if (_savedSong && MP.ap && MP.ap.list) {
                    var _n = parseInt(_savedSong);
                    if (!isNaN(_n) && _n >= 0 && _n < MP.ap.list.audios.length) {
                        MP.ap.list.switch(_n);
                        _songToRestore = _n;
                    }
                }
            }
            var _savedVol = (_st && typeof _st.vl === 'number') ? _st.vl : _.getCookie('mapi_volume');
            if (_savedVol !== '' && _savedVol !== null && _savedVol !== undefined) {
                var _v = parseFloat(_savedVol);
                if (!isNaN(_v) && _v >= 0 && _v <= 1) {
                    if (MP.ap) MP.ap.volume(_v);
                    MP.$('vol').value = _v;
                    if (_imVol) _imVol.value = _v;
                }
            }
        }

        if (!MP.mode || MP.mode === 'list') {
            MP.mode = 'list';
            if (MP.ap) MP.ap.options.loop = 'all';
        }

        MP._ready = true;
        // 用户在加载期间已经自己点过歌：别把 _userPicked 清零 —— 清零之后下面这次
        // _resolvePendingPicks() 就会让「记忆里那首」把他点的歌顶掉，他再点也像没反应。
        // 全新启动（还没人点过）时它本来就是 false，这里不需要重置。
        MP._autoplayOnReady = false;
        MP._resolvePendingPicks();            // 记忆里那首 / 用户点的那首已到货：直接切过去
        if (MP._cookieConsented && MP.ap && MP.ap.list) {
            // 保存播放状态
            if (typeof MP.saveState === 'function') MP.saveState(true);
        }
        // 启动状态看门狗
        if (typeof MP._stateWatch === 'function') MP._stateWatch();
        // 注意：这里不能直接结束呼吸动效——必须等所有歌单都加载完成（见 _updateLoadingState）
        MP._updateLoadingState();
        MP._scheduleAutoHide();
    };

    // 应用播放器位置：左右用记忆（没有就用后台设置），竖直一律用后台设置（访客拖过才用他的）
    MP._applyDefaultPos = function(force, override) {
        var raw = MP._posDefault();
        var m = /^(left|right):(\d{1,3})$/.exec(String(raw));
        var mem = (override && (override.side === 'left' || override.side === 'right')) ? override : null;
        if (!mem && force) mem = MP._readPos();
        if (!mem && !m) return false;
        if (!mem && !force && MP._readPos()) return false;      // 已有记忆且没要求强制：不动
        var host = MP._hostRoot && MP._hostRoot.host;
        if (!host) return false;
        var side = (mem && mem.side) ? mem.side : (m ? m[1] : MP._side);
        var pct = m ? Math.max(0, Math.min(100, parseInt(m[2], 10))) : 88;
        var mr = window.innerWidth <= 768 ? 4 : 15;
        host.style.left = side === 'left' ? mr + 'px' : 'auto';
        host.style.right = side === 'right' ? mr + 'px' : 'auto';
        if (mem && mem.top) {
            host.style.top = mem.top;                            // 访客拖过竖直位置
            host.style.bottom = 'auto';
            MP._posTopUser = mem.top;                            // 从记忆恢复的竖直位置同样受保护
        } else {
            // 悬浮按钮挂在宿主底部下方 (bottom:-28px, 高 48px) → 按钮中心 ≈ 宿主底边 + 4px
            var bottomPx = Math.round(window.innerHeight - (window.innerHeight * pct / 100 - 4));
            if (bottomPx < 20) bottomPx = 20;
            // 宿主站点底部有横向 tab 栏 / 吸底条时（自动探测或后台修正量），整块播放器再抬起来
            var insB = (typeof MP._getPlayerInset === 'function') ? (MP._getPlayerInset() || 0) : 0;
            if (insB < 0) insB = 0;
            bottomPx += insB;
            // 保证宿主完整落在视口内（百分比过小时否则会被顶出屏幕）
            var hostH = host.getBoundingClientRect().height || 0;
            var maxBottom = window.innerHeight - hostH - 4 - insB;
            if (maxBottom < 20) maxBottom = 20;
            if (bottomPx > maxBottom) bottomPx = maxBottom;
            host.style.top = 'auto';
            host.style.bottom = bottomPx + 'px';
            MP._posBottom = bottomPx;
        }
        MP._side = side;
        // 停靠方向：访客拖过竖直位置的按落点推断（_snap 会自己判定），后台百分比位置一律底部停靠
        MP._dockTop = (mem && mem.top) ? undefined : false;
        var root = MP.$('root');
        if (root) root.style.alignItems = side === 'left' ? 'flex-start' : 'flex-end';
        var tog = MP.$('toggle');
        if (tog) {
            tog.style.left = side === 'left' ? '' : 'auto';
            tog.style.right = side === 'left' ? 'auto' : '';
        }
        if (typeof MP._applyDock === 'function') MP._applyDock(false);
        MP._updateToggleTransform();
        if (typeof MP._snap === 'function') MP._snap();
        return true;
    };

    MP._initAntiDebug = function() {        var _detected = false;
        var _suspectCount = 0;
        var _ban = function() {
            if (_detected) return;
            _detected = true;
            if (MP.ap && MP.ap.audio) {
                try { MP.ap.audio.pause(); } catch(e) {}
            }
            document.body.innerHTML = '';
            var style = document.createElement('style');
            style.textContent = 'html,body{background:#000!important;margin:0!important;padding:0!important;overflow:hidden!important}';
            document.head.appendChild(style);
            window.location.replace('about:blank');
        };

        MP._antiDbInterval = setInterval(function() {
            var t = Date.now();
            debugger;
            if (Date.now() - t > 100) {
                _suspectCount++;
                if (_suspectCount >= 2) _ban();
            } else {
                _suspectCount = Math.max(0, _suspectCount - 1);
            }
        }, 500);
    };

    MP.destroy = function() {
        // 先置销毁标记: 所有在途回调 (模块加载 / 配置 / 歌单 / APlayer) 都会据此停止建 UI,
        MP._destroyed = true;

        if (MP._abort) { try { MP._abort.abort(); } catch(e) {} MP._abort = null; }
        if (MP._preloadCtls) {
            for (var pi = 0; pi < MP._preloadCtls.length; pi++) {
                try { MP._preloadCtls[pi].abort(); } catch(e) {}
            }
            MP._preloadCtls = [];
        }
        if (MP._configXhr) { try { MP._configXhr.abort(); } catch(e) {} MP._configXhr = null; }
        if (MP._bootXhr) { try { MP._bootXhr.abort(); } catch(e) {} MP._bootXhr = null; }
        if (MP.ap) {
            var _ap = MP.ap, _audioEl = _ap.audio;
            // 先停播，避免卸载瞬间还在出声
            try { if (_audioEl) _audioEl.pause(); } catch(e) {}
            // APlayer 1.10.1 在音频 error 事件后会挂一个 2s 定时器执行 skipForward/notice,
            try {
                if (_ap.list) {
                    _ap.list.switch = function(){};
                    _ap.list.clear = function(){};
                    _ap.list.add = function(){};
                }
                _ap.skipForward = function(){};
                _ap.skipBack = function(){};
                _ap.notice = function(){};
                _ap.play = function(){};
            } catch(e) {}
            try {
                if (_audioEl) {
                    _audioEl.removeAttribute('src');
                    try { _audioEl.load(); } catch(e) {}
                }
            } catch(e) {}
            try { _ap.destroy(); } catch(e) {}
            try { if (_audioEl && _audioEl.parentNode) _audioEl.parentNode.removeChild(_audioEl); } catch(e) {}
            MP.ap = null;
        }
        if (MP._themeInterval) { clearInterval(MP._themeInterval); MP._themeInterval = null; }
        if (MP._stateWatchTimer) { clearInterval(MP._stateWatchTimer); MP._stateWatchTimer = null; }
        if (MP._antiDbInterval) { clearInterval(MP._antiDbInterval); MP._antiDbInterval = null; }
        if (MP._retractTimer) { clearTimeout(MP._retractTimer); MP._retractTimer = null; }
        if (MP._domTimer) { clearTimeout(MP._domTimer); MP._domTimer = null; }
        if (MP._domObs) { try { MP._domObs.disconnect(); } catch(e) {} MP._domObs = null; }
        if (MP._resizeLrc) { window.removeEventListener('resize', MP._resizeLrc); MP._resizeLrc = null; }
        if (MP._onMessage) { window.removeEventListener('message', MP._onMessage); MP._onMessage = null; }
        if (MP._snapTmr) { clearTimeout(MP._snapTmr); MP._snapTmr = null; }
        if (MP._autoCalibrateTmr) { clearTimeout(MP._autoCalibrateTmr); MP._autoCalibrateTmr = null; }
        if (MP._lrcAnimTmr) { clearTimeout(MP._lrcAnimTmr); MP._lrcAnimTmr = null; }
        if (MP._annTimer) { clearTimeout(MP._annTimer); MP._annTimer = null; }
        if (MP._loadFallbackTimer) { clearTimeout(MP._loadFallbackTimer); MP._loadFallbackTimer = null; }
        if (MP._loadDeadlineTimer) { clearTimeout(MP._loadDeadlineTimer); MP._loadDeadlineTimer = null; }

        // 播放器自身插入宿主页面的所有痕迹
        var lrcEl = document.querySelector('[data-mp="lrc"]');
        if (lrcEl) lrcEl.remove();
        var hostEl = MP._hostRoot ? MP._hostRoot.host : null;
        if (hostEl && hostEl.parentNode) hostEl.parentNode.removeChild(hostEl);
        document.querySelectorAll('[id^="mapi-player-"]').forEach(function(el){
            if (el !== hostEl && el.parentNode) el.parentNode.removeChild(el);
        });
        var annWrap = document.querySelector('[data-mp="annWrap"]');
        if (annWrap && annWrap.parentNode) annWrap.parentNode.removeChild(annWrap);
        var annLock = document.getElementById('mapi-ann-scroll-lock');
        if (annLock) annLock.remove();
        var consent = document.getElementById('mapi-consent-overlay');
        if (consent && consent.parentNode) consent.parentNode.removeChild(consent);
        var scrollLock = document.getElementById('mapi-scroll-lock');
        if (scrollLock) scrollLock.remove();
        var scrollbarStyle = document.getElementById('mapi-scrollbar-style');
        if (scrollbarStyle) scrollbarStyle.remove();
        // 播放器自己插入的模块/APlayer 资源标签：卸载后清掉，避免反复加载/卸载让 head 无限增长
        document.querySelectorAll('script[data-mapi-asset]').forEach(function(el){ el.remove(); });
        var apCss = document.getElementById('mapi-aplayer-css');
        if (apCss) apCss.remove();
        if (MP._toastEl && MP._toastEl.parentNode) MP._toastEl.parentNode.removeChild(MP._toastEl);
        MP._toastEl = null;
        if (typeof MP._noticeCleanup === 'function') MP._noticeCleanup();
        if ('mediaSession' in navigator) {
            try { navigator.mediaSession.metadata = null; } catch(e) {}
        }
    };

    MP.bindUI = function() {
        MP.$('playBtn').addEventListener('click', function(){ if(MP.ap) MP.ap.toggle(); });
        MP.$('prevBtn').addEventListener('click', function(){ if(MP.ap){MP._userPicked = true;MP._skipResolved(-1);setTimeout(function(){MP.onSwitch();},200);} });
        MP.$('nextBtn').addEventListener('click', function(){ if(MP.ap){MP._userPicked = true;MP._skipResolved(1);setTimeout(function(){MP.onSwitch();},200);} });
        // 进度条点击 seek：只在「条真的可见且有宽度」时才算比例。
        // 皮肤把进度条藏着（玫瑰皮肤的 pbar 在 .rs-hidden 里）、面板收起或动画中时
        // getBoundingClientRect() 全 0，直接算会得到 Infinity/NaN，audio.currentTime
        // 被写成非法值（抛错）或直接跳到末尾 —— 表现为「进度莫名跳走」。
        MP._seekByClick = function(el) {
            if (!el) return;
            el.addEventListener('click', function(e){
                if (!MP.ap || !MP.ap.audio) return;
                var r = this.getBoundingClientRect();
                var dur = MP.ap.audio.duration;
                if (!(r.width > 0) || !isFinite(dur) || dur <= 0) return;
                var ratio = (e.clientX - r.left) / r.width;
                if (!isFinite(ratio)) return;
                if (ratio < 0) ratio = 0; else if (ratio > 1) ratio = 1;
                try { MP.ap.seek(ratio * dur); } catch (err) {}
            });
        };
        MP._seekByClick(MP.$('pbar'));
        MP.$('vol').addEventListener('input', function(){ if(MP.ap) MP.ap.volume(this.value); });
        MP.$('vol').addEventListener('change', function(){ MP.saveState(); });

        MP._boostOn = false;
        MP._boostCtx = null;
        MP._boostSource = null;
        MP._boostGain = null;
        MP._setupBoost = function(){
            try {
                var AudioCtx = window.AudioContext || window.webkitAudioContext;
                if (!AudioCtx || !MP.ap || !MP.ap.audio) return false;
                if (MP._boostCtx && MP._boostCtx.state === 'suspended') MP._boostCtx.resume();
                if (MP._boostSource && MP.ap.audio !== MP._boostAudioEl) {
                    try { MP._boostSource.disconnect(); } catch(e) {}
                    try { MP._boostGain.disconnect(); } catch(e) {}
                    MP._boostSource = null;
                    MP._boostGain = null;
                }
                if (MP._boostSource) return true;
                if (!MP._boostCtx) MP._boostCtx = new AudioCtx();
                MP._boostSource = MP._boostCtx.createMediaElementSource(MP.ap.audio);
                MP._boostGain = MP._boostCtx.createGain();
                MP._boostGain.gain.value = MP._boostOn ? 2.0 : 1.0;
                MP._boostSource.connect(MP._boostGain);
                MP._boostGain.connect(MP._boostCtx.destination);
                MP._boostAudioEl = MP.ap.audio;
                return true;
            } catch(e) {
                console.warn('[\u589e\u5f3a] WebAudio \u8bbe\u7f6e\u5931\u8d25:', e);
                return false;
            }
        };
        var boostBtn = MP.$('boost');
        if (boostBtn) boostBtn.addEventListener('click', function(){
            if (!MP._setupBoost()) return;
            var levels = [1.0, 2.0, 3.0];
            var labels = ['\u589e\u5f3a', '\u589e\u5f3a2x', '\u589e\u5f3a3x'];
            var idx = levels.indexOf(MP._boostGain.gain.value);
            idx = (idx + 1) % levels.length;
            MP._boostGain.gain.value = levels[idx];
            MP._boostOn = idx > 0;
            this.classList.toggle('on', MP._boostOn);
            this.textContent = labels[idx];
        });

        MP.bindModeSelect();
        MP.$('overlay').addEventListener('click', function(){ MP.togglePanel(); });
        MP.$('overlay').addEventListener('touchend', function(e){ e.preventDefault(); MP.togglePanel(); });

        var lrcBtn = MP.$('lrcToggle');
        if (lrcBtn) lrcBtn.addEventListener('click', function(){ MP.toggleLrc(); });

        var imBtn = MP.$('immersiveBtn');
        if (imBtn) imBtn.addEventListener('click', function(){ MP.toggleImmersive(); });
        var imClose = MP.$('imClose');
        if (imClose) imClose.addEventListener('click', function(){ MP.closeImmersive(); });
        var imPlay = MP.$('imPlay');
        if (imPlay) imPlay.addEventListener('click', function(){ if(MP.ap) MP.ap.toggle(); });
        var imPrev = MP.$('imPrev');
        if (imPrev) imPrev.addEventListener('click', function(){ if(MP.ap){MP._userPicked = true;MP._skipResolved(-1);setTimeout(function(){MP.onSwitch();if(MP._imOpen)MP.updateImmersiveUI();},200);} });
        var imNext = MP.$('imNext');
        if (imNext) imNext.addEventListener('click', function(){ if(MP.ap){MP._userPicked = true;MP._skipResolved(1);setTimeout(function(){MP.onSwitch();if(MP._imOpen)MP.updateImmersiveUI();},200);} });
        var imPbar = MP.$('imPbar');
        MP._seekByClick(imPbar);        // 与主进度条同一套校验（沉浸层关闭时 imPbar 不可见、rect 为 0）
        var imVol = MP.$('imVol');
        if (imVol) { imVol.addEventListener('input', function(){ if(MP.ap) MP.ap.volume(this.value); }); imVol.addEventListener('change', function(){ MP.saveState(); }); }

        var slistTrigger = MP.$('imSlistTrigger');
        var slistDropdown = MP.$('imSlist');
        if (slistTrigger && slistDropdown) {
            slistTrigger.addEventListener('click', function(e){
                e.stopPropagation();
                slistDropdown.classList.toggle('open');
            });
            document.addEventListener('click', function(e){
                if (!slistTrigger.contains(e.target) && !slistDropdown.contains(e.target)) {
                    slistDropdown.classList.remove('open');
                }
            });
        }

        var backBtns = [MP.$('plBack'), MP.$('imPlBack')].filter(function(e){return e;});
        backBtns.forEach(function(btn){
            btn.addEventListener('click', function(e){
                e.stopPropagation();
                MP._userNavigated = true;        // 用户主动翻列表：启动流程让路（见 loadInitialPlaylist）
                MP._viewingPlaylist = true;
                MP._renderWithFade();
            });
        });

        var imModeBtn = MP.$('imModeBtn');
        if (imModeBtn) {
            imModeBtn.addEventListener('click', function(){
                var order = ['list','single','random'];
                var idx = order.indexOf(MP.mode);
                var next = order[(idx + 1) % order.length];
                MP.setMode(next);
                MP.updateImmersiveModeBtn();
            });
        }

        MP.enableDrag();
        if (typeof MP.enableResizeGuard === 'function') MP.enableResizeGuard();
    };

    MP.onEnded = function() {
        if (MP.mode !== 'random') return;
        var total = MP.ap.list.audios.length;
        var cur = MP.ap.list.index;
        var next = Math.floor(Math.random() * total);
        if (next === cur && total > 1) next = (next + 1) % total;
        MP.ap.list.switch(next);
        setTimeout(function(){ MP.onSwitch(); }, 100);
    };

    MP.onSwitch = function() {
        // APlayer 播完会自动切下一首（list.switch(nextIndex)），可能落到还没解析出来的占位条目上：
        // 这里就地跳到最近的可播曲目，别让播放停在空 src 上（上百首的歌单里这种占位随时都可能出现）。
        var _audios = (MP.ap && MP.ap.list && MP.ap.list.audios) || [];
        var _curI = (MP.ap && MP.ap.list) ? MP.ap.list.index : 0;
        var _curA = _audios[_curI];
        if (_curA && !_curA.url) {
            // 懒加载：切到的这一首还没取过地址 → 只取这一首（1 个请求），取到后由 _playSlotWhenReady 接着播
            MP._ensureSlotUrl(MP.currentPlaylistIndex, _curI, true);
        } else if (_curA && _curA.url) {
            MP._prefetchNextSlot(MP.currentPlaylistIndex, _curI);   // 已经就绪：顺手把下一首备好
        }
        MP.updateUI();
        MP.lrcLines = [];
        MP._lrcTxt = '';                 // 上一首的歌词不许留在歌词条上（暂停着换歌尤其明显）
        MP.loadLrc();
        if (MP._imOpen) MP.updateImmersiveLrc();
        // APlayer 的 listswitch 在切 index 之前就触发了（List.switch：先 trigger 再 this.index = e），
        // 所以上面这次读到的可能还是上一首的歌词；延后一拍按新 index 重排一次。
        // 暂停着换歌不会再有时刻刷新的 timeupdate，歌词条就靠这一拍跟上新歌。
        // 歌词条还没挂载时（首次播放之前）不动它，避免提前凭空创建。
        setTimeout(function(){
            if (MP._destroyed) return;
            MP.lrcLines = [];
            MP.loadLrc();
            if (document.querySelector('[data-mp="lrc"]')) MP.syncLrc();
        }, 0);
        setTimeout(function(){ MP.renderSonglist(); }, 80);
    };

    MP.updateMediaSession = function() {
        if (!('mediaSession' in navigator) || !MP.ap || !MP.ap.list) return;
        var idx = MP.ap.list.index;
        var info = MP.ap.list.audios[idx];
        if (!info) return;
        navigator.mediaSession.metadata = new MediaMetadata({
            title: info.name || '\u672a\u77e5',
            artist: info.artist || '',
            artwork: info.cover ? [{ src: info.cover, sizes: '512x512', type: 'image/jpeg' }] : []
        });
    };

})(window.__mapiPlayer);