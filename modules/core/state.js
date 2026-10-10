(function(MP){
    if (!MP) return;
    var _ = MP._;

    // 取歌曲身份（server + song_id）
    MP._songRef = function(s) {
        if (!s) return '';
        var sv = s.server || s._sv || MP._server || 'netease';
        var id = s.id || s._sid || '';
        return id ? (sv + '_' + id) : '';
    };

    // 取本实例的 cookie 名（默认实例保持原名，兼容旧宿主页）
    MP._cookieName = function(base) {
        var id = _.INSTANCE_ID || '';
        return id ? base + '_' + id : base;
    };

    // 取站点命名空间（密钥 + 实例）
    MP._stateKey = function() {
        var s = String(_.API_KEY || '');
        if (!s && _.API_TOKEN) {
            try {
                var seg = String(_.API_TOKEN).split('.')[1];
                if (seg) {
                    seg = seg.replace(/-/g, '+').replace(/_/g, '/');
                    while (seg.length % 4) seg += '=';
                    var payload = JSON.parse(atob(seg));
                    s = String(payload.key || '');
                }
            } catch (e) {}
        }
        if (_.INSTANCE_ID) s = s + '#' + _.INSTANCE_ID;
        if (!s) return '';                       // 拿不到稳定标识 → 不启用命名空间（等同于旧行为）
        var h = 5381;
        for (var i = 0; i < s.length; i++) { h = ((h << 5) + h + s.charCodeAt(i)) | 0; }
        return (h >>> 0).toString(36);
    };

    // 取后台设置的默认位置（side:pct）
    MP._posDefault = function() {
        return String(MP._playerPos || (window.__mszeph_config && window.__mszeph_config.player_pos) || '');
    };

    // 取本实例的位置记忆（先新格式，再旧全局 cookie）
    MP._readPos = function() {
        var st = MP._readState();
        if (st && st.pos && (st.pos.side === 'left' || st.pos.side === 'right')) {
            // 后台默认位置改过：旧记忆作废，重新应用后台设置
            if (String(st.pos.def || '') !== MP._posDefault()) return null;
            return { side: st.pos.side, top: MP._clampTop(st.pos.top) };
        }
        if (_.INSTANCE_ID) return null;
        var raw = '';
        try { raw = _.getCookie('mapi_pos'); } catch (e) { raw = ''; }
        if (!raw) return null;
        var side = '', top = '';
        var parts = String(raw).split(',');
        for (var i = 0; i < parts.length; i++) {
            var kv = parts[i].split(':');
            if (kv[0] === 'left' || kv[0] === 'right') side = kv[0];
            if (kv[0] === 't' && kv[1] && kv[1] !== 'auto' && kv[1] !== 'initial') top = MP._clampTop(kv[1]);
        }
        return side ? { side: side, top: top } : null;
    };

    // 夹取竖直像素位置到视口内
    MP._clampTop = function(top) {
        var n = parseFloat(top);
        if (isNaN(n)) return '';
        var max = Math.max(0, window.innerHeight - 60);
        if (n < 0) n = 0;
        if (n > max) n = max;
        return Math.round(n) + 'px';
    };

    MP._readState = function() {
        try {
            var raw = _.getCookie(MP._cookieName('mapi_state'));
            if (!raw) return null;
            var st = JSON.parse(raw);
            if (!st || st.v !== 2) return null;
            var k = MP._stateKey();
            if (st.k && k && st.k !== k) return null;          // 别的密钥/站点的记忆：忽略
            return st;
        } catch (e) { return null; }
    };

    // 在当前音频列表里按身份找下标；找不到返回 -1（由调用方回落到第 1 首）
    MP._audioIndexOf = function(ref) {
        if (!ref || !MP.ap || !MP.ap.list) return -1;
        var list = MP.ap.list.audios || [];
        for (var i = 0; i < list.length; i++) { if (MP._songRef(list[i]) === ref) return i; }
        return -1;
    };

    MP.saveState = function(force) {
        if (!MP._ready) return;
        if (!MP._cookieConsented) return;
        if (!MP.ap || !MP.ap.list) return;
        var a = MP.ap.list.audios[MP.ap.list.index] || null;
        var pl = (MP.playlists && MP.playlists[MP.currentPlaylistIndex]) || {};
        var hostRoot = MP._hostRoot;
        // 记忆里只用访客自己拖的竖直位置；面板展开时的临时夹取不写入
        var _top = MP._posTopUser || (hostRoot && hostRoot.host ? hostRoot.host.style.top : '');
        var _posValid = !!_top && _top !== 'initial' && _top !== 'auto';
        var _pos = MP._side + (_posValid ? ',t:' + _top : '');
        var st = {
            v: 2,
            k: MP._stateKey(),                                    // 命名空间
            pl: pl.pid || 0,                                      // 歌单 DB id（重排/改名都不变）
            pln: pl.name || '',                                   // 宿主页内联 config 没有 pid 时按名字兜底
            rf: MP._restorePendingRef || MP._songRef(a),          // 歌曲身份 server_songId；依次加载时记忆里那首还没到货，先别把它冲掉
            md: MP.mode || 'list',
            vl: (MP.ap.audio && typeof MP.ap.audio.volume === 'number')
                ? Math.round(MP.ap.audio.volume * 100) / 100 : 1,
            pos: { side: MP._side, top: _posValid ? _top : '', def: MP._posDefault() },
            ts: Date.now()
        };
        // 内容未变化则不写入
        var sig = [st.pl, st.rf, st.md, st.vl, _pos].join('|');
        if (!force && sig === MP._stateSig) return;
        MP._stateSig = sig;
        try { _.setCookie(MP._cookieName('mapi_state'), JSON.stringify(st)); } catch (e) {}
        // 位置已并入 mapi_state；旧 cookie 只由默认实例写，避免多实例互相覆盖
        if (!_.INSTANCE_ID) { try { _.setCookie('mapi_pos', _pos); } catch (e) {} }
        // 顺手同步一份到云端（防抖；服务端未启用/连不上时本次会话自动停手，不影响上面这份 cookie 记忆）
        MP._cloudSyncSoon(st);
    };

    // 定时保存播放状态
    MP._stateWatch = function() {
        if (MP._stateWatchTimer) return;
        MP._stateWatchTimer = setInterval(function() {
            if (MP._destroyed) {
                clearInterval(MP._stateWatchTimer);
                MP._stateWatchTimer = null;
                return;
            }
            MP.saveState();
        }, 2000);
    };

    MP.loadState = function() {
        if (!MP._cookieConsented) return;
        var st = MP._readState();
        var mode, vol, idx;
        if (st) {
            mode = st.md; vol = st.vl;
            idx = MP._audioIndexOf(st.rf);                         // 按身份找
        } else {
            // 兼容旧 cookie
            mode = _.getCookie('mapi_mode');
            vol = _.getCookie('mapi_volume');
            idx = parseInt(_.getCookie('mapi_song'), 10);
        }
        if (!isNaN(idx) && idx >= 0 && MP.ap && MP.ap.list && idx < MP.ap.list.audios.length) {
            try { MP.ap.list.switch(idx); } catch (e) {}       // 先把"当前曲目"指过去（高亮/歌词跟着它）
            // 懒加载：这一首的播放地址可能还没取过 → 按需取；取到后 _syncPlayerList 会补一次 switch
            // 把 src 装上（不强制 play，是否自动开播仍按自动播放规则来）
            if (typeof MP._ensureSlotUrl === 'function') MP._ensureSlotUrl(MP.currentPlaylistIndex, idx, false);
        }
        if (vol !== undefined && vol !== null && vol !== '') {
            var v = parseFloat(vol);
            if (!isNaN(v) && v >= 0 && v <= 1) {
                if (MP.ap) MP.ap.volume(v);
                if (MP.$('vol')) MP.$('vol').value = v;
                var _imVol = MP.$('imVol');
                if (_imVol) _imVol.value = v;
            }
        }
        if (mode && ['list','single','random'].indexOf(mode) >= 0) { MP.setMode(mode); }
    };

    MP.setMode = function(mode) {
        MP.mode = mode;
        var labels = {list:'\u5217\u8868\u64ad\u653e', single:'\u5355\u66f2\u5faa\u73af', random:'\u968f\u673a\u64ad\u653e'};
        var trigger = MP.$('modeTrigger');
        if (trigger) { trigger.textContent = labels[mode] || mode; }
        var menu = MP.$('modeMenu');
        if (menu) {
            menu.querySelectorAll('.mode-option').forEach(function(o){
                o.classList.toggle('active', o.getAttribute('data-mode') === mode);
            });
        }
        if (MP.ap) {
            var loopMap = {single:'one', list:'all', random:'none'};
            MP.ap.options.loop = loopMap[mode] || 'none';
        }
        MP.saveState();
    };

    MP.bindModeSelect = function() {
        var trigger = MP.$('modeTrigger');
        var menu = MP.$('modeMenu');
        if (!trigger || !menu) return;
        trigger.addEventListener('click', function(e){
            e.stopPropagation();
            if (menu.classList.contains('open')) {
                menu.classList.remove('open');
            } else {
                menu.classList.add('open');
            }
        });
        menu.querySelectorAll('.mode-option').forEach(function(opt){
            opt.addEventListener('click', function(){
                var mode = this.getAttribute('data-mode');
                if (mode === MP.mode) { menu.classList.remove('open'); return; }
                MP.setMode(mode);
                menu.classList.remove('open');
            });
        });
        document.addEventListener('click', function(e){
            if (!trigger.contains(e.target) && !menu.contains(e.target)) {
                menu.classList.remove('open');
            }
        });
    };

    MP._loadSavedPlaylistIndex = function() {
        try {
            var st = MP._readState();
            if (st) {
                // 按歌单 id 查找
                if (st.pl) {
                    for (var i = 0; i < MP.playlists.length; i++) {
                        if (String(MP.playlists[i].pid || '') === String(st.pl)) return i;
                    }
                }
                // 按歌单名查找
                if (st.pln) {
                    for (var j = 0; j < MP.playlists.length; j++) {
                        if (MP.playlists[j].name === st.pln) return j;
                    }
                }
                return -1;
            }
            // 旧 cookie（下标）兼容：一次性，saveState 之后就会写成新格式
            var idx = _.getCookie('mapi_pl_index');
            if (idx !== null && idx !== '') {
                var n = parseInt(idx);
                if (!isNaN(n) && n >= 0 && n < MP.playlists.length) return n;
            }
        } catch(e) {}
        return -1;
    };

    /* ── 设备标识 + 播放器状态云同步（Redis）──────────────────────────────
       设备号由服务端下发：get-config 的 config.device，同时种 cookie `mapi_device`。
       跨站嵌入时第三方 cookie 常被浏览器拦掉，所以这里**同时**把它镜像进 localStorage，
       之后所有网关请求都追加 &dev= —— 服务端两条路都认（cookie 优先，其次 ?dev=）。

       云同步是"锦上添花"，三条纪律：
         1. **推送**：saveState 后防抖 3s 推一份（服务端按 IP+设备校验后落 Redis）
         2. **恢复**：启动时拉一次，**只在本机没有任何状态时**才采用云端（避免和本地记忆打架）
         3. **失败即退**：服务端明确回 disabled/unavailable → 本次会话不再重试；
            网络错误一律忽略 —— 绝不能因为缓存服务影响播放与本地记忆
    ─────────────────────────────────────────────────────────────────── */
    MP._device = '';
    MP._cloudStateOff = false;
    MP._cloudTimer = null;

    /** 记下服务端下发的设备号（或回落到 localStorage 里那份） */
    MP._deviceInit = function (dev) {
        dev = String(dev || '').toLowerCase();
        if (/^[a-f0-9]{32,64}$/.test(dev)) {
            MP._device = dev;
            try { localStorage.setItem('mapi_device', dev); } catch (e) {}
            return MP._device;
        }
        if (!MP._device) {
            try {
                var saved = String(localStorage.getItem('mapi_device') || '').toLowerCase();
                if (/^[a-f0-9]{32,64}$/.test(saved)) MP._device = saved;
            } catch (e) {}
        }
        return MP._device;
    };

    /** 所有网关请求都要带上的设备参数（没有设备号时返回空串，服务端会发新的） */
    MP._devParam = function () {
        return MP._device ? ('&dev=' + encodeURIComponent(MP._device)) : '';
    };

    MP._cloudToken = function () { return encodeURIComponent(_.API_TOKEN || _.API_KEY || ''); };

    /** 把云端状态套用到播放器（仅在"本机没有状态"时走这里，所以不会和本地记忆打架） */
    MP._applyCloudState = function (st) {
        if (!st || MP._destroyed) return;
        var idx = MP._audioIndexOf(st.rf);
        if (typeof idx === 'number' && idx >= 0 && MP.ap && MP.ap.list && idx < MP.ap.list.audios.length) {
            try { MP.ap.list.switch(idx); } catch (e) {}
            if (typeof MP._ensureSlotUrl === 'function') MP._ensureSlotUrl(MP.currentPlaylistIndex, idx, false);
        }
        if (st.md && typeof MP.setMode === 'function') { try { MP.setMode(st.md); } catch (e) {} }
        if (typeof st.vl === 'number' && MP.ap && MP.ap.audio) {
            try { MP.ap.audio.volume = Math.max(0, Math.min(1, st.vl)); } catch (e) {}
        }
    };

    /** 启动时拉一次云端状态：只在本地没有状态时才采用（cookie 丢了/换浏览器能接着听） */
    MP._cloudPull = function () {
        if (MP._cloudStateOff || MP._destroyed || !_.API_BASE) return;
        var local = null;
        try { local = MP._readState(); } catch (e) {}
        if (local && local.rf) return;                       // 本机已经有记忆 → 不动它（云只是备份）
        fetch(_.API_BASE + '?action=state-get&token=' + MP._cloudToken() + MP._devParam(), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || MP._destroyed) return;
                if (d.device) MP._deviceInit(d.device);
                if (!d.ok) {
                    if (['disabled', 'unavailable', 'too_large', 'bad_device'].indexOf(d.reason) >= 0) MP._cloudStateOff = true;
                    return;
                }
                var cs = d.state || {};
                if (!cs.rf) return;                          // 云端没有有效状态
                try { _.setCookie(MP._cookieName('mapi_state'), JSON.stringify(cs)); } catch (e) {}
                MP._applyCloudState(cs);
            })
            .catch(function () {});
    };

    /** 防抖推送（saveState 会频繁触发，3s 一次即可） */
    MP._cloudSyncSoon = function (st) {
        if (MP._cloudStateOff || MP._destroyed || !st || !_.API_BASE) return;
        if (MP._cloudTimer) clearTimeout(MP._cloudTimer);
        MP._cloudTimer = setTimeout(function () {
            MP._cloudTimer = null;
            if (MP._cloudStateOff || MP._destroyed) return;
            fetch(_.API_BASE + '?action=state-set&token=' + MP._cloudToken() + MP._devParam(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'state=' + encodeURIComponent(JSON.stringify(st))
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (!d) return;
                if (d.device) MP._deviceInit(d.device);      // 服务端换发新设备号（换网段/首次）时跟上
                // 服务端明确说"这个功能没开/连不上/这类问题重试也没用"→ 本次会话停手，别空转重推
                if (!d.ok && ['disabled', 'unavailable', 'too_large', 'bad_device', 'bad_state'].indexOf(d.reason) >= 0) {
                    MP._cloudStateOff = true;
                }
            }).catch(function () {});
        }, 3000);
    };

})(window.__mapiPlayer);