(function(MP){
    if (!MP) return;
    var _ = MP._;

    // 提示兜底：ui.js 正常在启动前就已加载，但万一没有（被裁剪的构建），退回原来那条内联提示
    function _notify(code, level) {
        if (typeof MP.notice === 'function') { MP.notice(code, level ? { level: level } : undefined); return; }
        var err = document.createElement('div');
        err.textContent = code === 'no_key' ? '\u7f3a\u5c11 API Key\uff0c\u64ad\u653e\u5668\u65e0\u6cd5\u52a0\u8f7d' : String(code);
        err.style.cssText = 'position:fixed;top:16px;right:16px;z-index:2147483647;padding:10px 20px;border-radius:12px;font-size:13px;font-weight:500;background:rgba(108,92,231,.92);color:#fff;backdrop-filter:blur(8px);box-shadow:0 4px 20px rgba(0,0,0,.2);transform:translateX(120%);opacity:0;transition:all .4s cubic-bezier(.4,0,.2,1);pointer-events:none';
        document.body.appendChild(err);
        requestAnimationFrame(function(){ err.style.transform = 'translateX(0)'; err.style.opacity = '1'; });
        setTimeout(function(){ err.style.transform = 'translateX(120%)'; err.style.opacity = '0'; setTimeout(function(){ err.remove(); }, 400); }, 5000);
    }

    // 校验密钥：cb(ok, code) —— code 是失败原因码（no_key / invalid_key / key_disabled /
    // rate_limited / offline / server_error），调用方据此给出各自的提示文案。
    MP.verifyKey = function(cb) {
        if (!_.API_KEY && !_.API_TOKEN) {
            _notify('no_key');
            cb(false, 'no_key'); return;
        }
        if (_.API_TOKEN) { cb(true); return; }
        var x = new XMLHttpRequest();
        x.open('POST', _.API_BASE + '?action=verify-key', true);
        x.setRequestHeader('Content-Type', 'application/json');
        x.timeout = 15000;                       // 请求卡住时也要让启动有个结果（宿主页按钮才能恢复为“加载”）
        x.ontimeout = function() { cb(false, 'offline'); };
        x.onload = function() {
            if (x.status === 429) { cb(false, 'rate_limited'); return; }        // 限流：不是密钥的问题
            if (x.status >= 500) { cb(false, 'server_error'); return; }
            try {
                var d = JSON.parse(x.responseText);
                if (d.valid === true && d.token) _.API_TOKEN = d.token;
                cb(d.valid === true, d.valid === true ? '' : (d.code || 'invalid_key'));
            } catch(e) { cb(false, 'server_error'); }
        };
        x.onerror = function() { cb(false, 'offline'); };
        x.send(JSON.stringify({key: _.API_KEY}));
    };

    MP.showConsentBanner = function(callback) {
        var consented = _.getCookie('mapi_cookie_consent');
        if (consented === 'granted') { callback(true); return; }
        if (consented === 'denied') { callback(false); return; }

        function render() {
            // 非阻塞的角落卡片：不注入滚动锁、不铺全屏遮罩、不遮暗页面。
            // 播放器是嵌在别人博客里的，读者不该为了一个 cookie 提示被按住、读不了文章。
            var card = document.createElement('div');
            card.id = 'mapi-consent-overlay';
            card.style.cssText = 'position:fixed;left:16px;bottom:calc(16px + var(--mapi-inset-player,0px));z-index:2147483647;width:300px;max-width:calc(100vw - 32px);box-sizing:border-box;background:rgba(255,255,255,.85);backdrop-filter:blur(24px)saturate(200%);-webkit-backdrop-filter:blur(24px)saturate(200%);border:1px solid rgba(255,255,255,.75);border-radius:14px;padding:16px;box-shadow:0 8px 32px rgba(0,0,0,.16);font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;opacity:0;transform:translateY(10px);transition:opacity .3s cubic-bezier(.4,0,.2,1),transform .3s cubic-bezier(.4,0,.2,1)';

            card.innerHTML = '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">'
                + '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#1a1a2e" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>'
                + '<span style="font-size:14px;font-weight:700;color:#1a1a2e">\u97f3\u4e50\u64ad\u653e\u5668</span></div>'
                + '<p style="font-size:12px;color:#777;line-height:1.65;margin:0 0 12px">\u64ad\u653e\u5668\u4f1a\u7528 cookie \u8bb0\u4f4f\u64ad\u653e\u6a21\u5f0f\u3001\u6b4c\u5355\u8fdb\u5ea6\u4e0e\u6b4c\u8bcd\u5f00\u5173\uff0c\u4ec5\u4fdd\u5b58\u5728\u4f60\u7684\u6d4f\u89c8\u5668\u4e2d\uff0c\u4e0d\u4f1a\u4e0a\u4f20\u5230\u670d\u52a1\u5668\u3002</p>'
                + '<div style="display:flex;gap:8px;justify-content:flex-end">'
                + '<button id="mapi-consent-deny" style="padding:6px 14px;border-radius:8px;border:1px solid rgba(0,0,0,.1);background:rgba(0,0,0,.04);color:#1a1a2e;font-size:12px;cursor:pointer;font-weight:600">\u62d2\u7edd</button>'
                + '<button id="mapi-consent-accept" style="padding:6px 14px;border-radius:8px;border:1px solid rgba(0,0,0,.12);background:rgba(0,0,0,.08);color:#1a1a2e;font-size:12px;cursor:pointer;font-weight:700">\u540c\u610f</button>'
                + '</div>';

            document.body.appendChild(card);
            requestAnimationFrame(function(){ card.style.opacity = '1'; card.style.transform = 'translateY(0)'; });

            function cleanup() { if (card.parentNode) card.parentNode.removeChild(card); }

            card.querySelector('#mapi-consent-accept').addEventListener('click', function() {
                _.setCookie('mapi_cookie_consent', 'granted');
                cleanup();
                callback(true);
            });
            card.querySelector('#mapi-consent-deny').addEventListener('click', function() {
                _.setCookie('mapi_cookie_consent', 'denied');
                cleanup();
                callback(false);
            });
        }

        // 脚本可能被放在 <head>（未加 defer）里执行，此时 body 还不存在
        if (document.body) render();
        else document.addEventListener('DOMContentLoaded', render, { once: true });
    };

})(window.__mapiPlayer);