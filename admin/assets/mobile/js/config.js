/* config.js — 系统配置页面脚本 */
(function(){
  var D = document.getElementById('config-data');
  if (!D) return;
  var csrf = D.dataset.csrf;
  var activeKeyId = 0;
  var dragGuardAt = 0;                 // 最近一次拖动的结束时间：拖动后的 click 不当作点击

  // ═══ 歌词条字体（音乐配置）═══
  // 只管预览、上传、清除；「换文件就删旧文件 / 填 URL 就删上传件」的判定都在服务端
  // （handlers/lrc_font.php 与 handlers/config_user.php）
  (function(){
    var FALLBACK = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,'PingFang SC','Microsoft YaHei',sans-serif";
    var urlInput = document.getElementById('lrcFontUrl');
    var preview = document.getElementById('lrcFontPreview');
    if (!urlInput || !preview) return;          // 不是音乐配置页（或旧版页面）
    var nameInput = document.getElementById('lrcFontName');
    var sizeInput = document.getElementById('lrcFontSize');
    var uploaded = document.getElementById('lrcFontUploaded');
    var fileInput = document.getElementById('lrcFontFile');
    var upBtn = document.getElementById('lrcFontUpload');
    var clearBtn = document.getElementById('lrcFontClear');
    var statusEl = document.getElementById('lrcFontStatus');
    var upBtnDisabled = upBtn ? !!upBtn.disabled : true;   // 没配存储时服务端已禁用，别把它打开

    function uploadedUrl() { return uploaded ? String(uploaded.value || '') : ''; }
    function isCss(u) { return /\.css(\?|#|$)/i.test(String(u || '')); }
    function cleanUrl(u) { return String(u || '').replace(/[\\'"()<>]/g, '').trim(); }
    function cleanName(n) { return String(n || '').replace(/[^A-Za-z0-9 _\-\u4e00-\u9fa5]/g, '').trim(); }
    function currentUrl() { return cleanUrl(urlInput.value) || uploadedUrl(); }
    function family() {
      var n = cleanName(nameInput ? nameInput.value : '');
      if (n) return n;
      var u = currentUrl();
      return (u && !isCss(u)) ? 'MsapiLrcFont' : '';
    }
    function fontSize() {
      var v = sizeInput ? parseInt(sizeInput.value, 10) : 0;
      return (v > 0 && v <= 200) ? v : 15;
    }
    function setStatus(msg, bad) {
      if (!statusEl) return;
      statusEl.textContent = msg || '';
      statusEl.style.color = bad ? '#ff5f57' : '';
    }
    function setFontFace(u, fam) {
      var st = document.getElementById('lrcFontPreviewStyle');
      if (!u || isCss(u) || !fam) { if (st && st.parentNode) st.parentNode.removeChild(st); return; }
      if (!st) { st = document.createElement('style'); st.id = 'lrcFontPreviewStyle'; document.head.appendChild(st); }
      st.textContent = "@font-face{font-family:'" + fam + "';src:url('" + u + "');font-display:swap}";
    }
    function setLink(u) {
      var lk = document.getElementById('lrcFontPreviewLink');
      if (!u || !isCss(u)) { if (lk && lk.parentNode) lk.parentNode.removeChild(lk); return; }
      if (!lk) { lk = document.createElement('link'); lk.id = 'lrcFontPreviewLink'; lk.rel = 'stylesheet'; document.head.appendChild(lk); }
      lk.href = u;
    }
    function refresh() {
      var u = currentUrl(), fam = family();
      setFontFace(u, fam);
      setLink(u);
      preview.style.fontFamily = fam ? ("'" + fam + "'," + FALLBACK) : '';
      preview.style.fontSize = fontSize() + 'px';
    }
    function idleStatus() {
      if (uploadedUrl()) setStatus('当前使用已上传的字体文件');
      else if (urlInput.value) setStatus('当前使用外部字体 URL');
      else setStatus('当前使用默认字体');
    }
    function post(action, data) {
      return fetch('/admin/api/?action='+encodeURIComponent(String(action).replace(/\/+$/,'')), {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      }).then(function(r){ return r.json(); });
    }
    function upload(file) {
      var m = /\.([a-z0-9]+)$/i.exec(file.name || '');
      var ext = m ? m[1].toLowerCase() : '';
      if (!/^(woff2|woff|ttf|otf)$/.test(ext)) { setStatus('只支持 .woff2 / .woff / .ttf / .otf 字体文件', true); return; }
      if (file.size > 5 * 1024 * 1024) { setStatus('字体文件不能超过 5MB', true); return; }
      if (upBtn) upBtn.disabled = true;
      setStatus('正在上传…');
      post('lrc-font-presign', { mime: file.type || '', ext: ext, _csrf: csrf }).then(function(d){
        if (!d || !d.ok) throw new Error((d && d.error) || '生成上传签名失败');
        return fetch(d.url, { method: 'PUT', body: file, headers: { 'Content-Type': file.type || 'application/octet-stream' } })
          .then(function(pr){
            if (!pr.ok) throw new Error('直传存储失败（' + pr.status + '）');
            return post('lrc-font-confirm', { key: d.key, _csrf: csrf });
          });
      }).then(function(d){
        if (!d || !d.ok) throw new Error((d && d.error) || '保存字体失败');
        if (uploaded) uploaded.value = d.url || '';
        urlInput.value = '';                 // 上传优先：清掉 URL，服务端也把 URL 清了
        setStatus('已上传：' + (file.name || '字体文件') + '（立即生效）');
        refresh();
      }).catch(function(e){
        setStatus(e && e.message ? e.message : '上传失败', true);
      }).then(function(){ if (upBtn) upBtn.disabled = upBtnDisabled; });
    }

    if (upBtn && fileInput) upBtn.addEventListener('click', function(){ fileInput.click(); });
    if (fileInput) fileInput.addEventListener('change', function(){
      var f = fileInput.files && fileInput.files[0];
      fileInput.value = '';
      if (f) upload(f);
    });
    if (clearBtn) clearBtn.addEventListener('click', function(){
      if (!window.confirm('清除歌词条字体设置？已上传的字体文件会被删除。')) return;
      clearBtn.disabled = true;
      post('lrc-font-clear', { _csrf: csrf }).then(function(d){
        if (!d || !d.ok) throw new Error((d && d.error) || '清除失败');
        urlInput.value = '';
        if (nameInput) nameInput.value = '';
        if (sizeInput) sizeInput.value = '';
        if (uploaded) uploaded.value = '';
        setStatus('已恢复默认字体');
        refresh();
      }).catch(function(e){
        setStatus(e && e.message ? e.message : '清除失败', true);
      }).then(function(){ clearBtn.disabled = false; });
    });
    urlInput.addEventListener('input', function(){
      if (cleanUrl(urlInput.value)) setStatus('当前使用外部字体 URL（保存后生效）');
      else idleStatus();
      refresh();
    });
    if (nameInput) nameInput.addEventListener('input', refresh);
    if (sizeInput) sizeInput.addEventListener('input', refresh);
    idleStatus();
    refresh();
  })();

  // 将弹窗移到 body 下, 使其 fixed 定位基于视口, 可覆盖侧边栏
  ['createModal', 'editRemoteModal'].forEach(function(id) {
    if (!document.querySelector('.wrap #' + id)) return;
    var stale = document.querySelectorAll('body > #' + id);
    for (var i = 0; i < stale.length; i++) stale[i].remove();
  });
  var createModal = document.getElementById('createModal');
  var editModal = document.getElementById('editRemoteModal');
  if (!createModal || !editModal) return;      // 弹窗不存在就别继续，免得后面报错
  if (createModal.parentElement !== document.body) document.body.appendChild(createModal);
  if (editModal.parentElement !== document.body) document.body.appendChild(editModal);
  function $(id) { return document.getElementById(id); }   // 弹窗已移到 body、ID 唯一，直接按 ID 取

  function closeSongModal(modal) {
    if (!modal || modal.classList.contains('closing')) return;
    modal.classList.add('closing');
    var onEnd = function() {
      modal.removeEventListener('animationend', onEnd);
      modal.classList.remove('closing');
      modal.style.display = 'none';
    };
    modal.addEventListener('animationend', onEnd);
    setTimeout(function() {
      if (modal.style.display !== 'none') {
        modal.classList.remove('closing');
        modal.style.display = 'none';
      }
    }, 400);
  }

  function apiPost(action, data, cb) {
    data._csrf = csrf;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/admin/api/?action='+encodeURIComponent(String(action).replace(/\/+$/,'')), true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onload = function() {
      try { cb(JSON.parse(xhr.responseText)); } catch(e) { cb({ok:false, msg:'解析失败: ' + xhr.responseText.substring(0, 200)}); }
    };
    xhr.onerror = function() { cb({ok:false, msg:'网络错误'}); };
    xhr.send(JSON.stringify(data));
  }

  function formPost(action, data, cb) {
    var fd = new FormData();
    fd.append('_csrf', csrf);
    for (var k in data) fd.append(k, data[k]);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/admin/api/?action='+encodeURIComponent(String(action).replace(/\/+$/,'')), true);
    xhr.onload = function() {
      try { cb(JSON.parse(xhr.responseText)); } catch(e) { cb({ok:false, msg:'解析失败: ' + xhr.responseText.substring(0, 200)}); }
    };
    xhr.onerror = function() { cb({ok:false, msg:'网络错误'}); };
    xhr.send(fd);
  }

  function toast(msg, ok) {
    var t = document.getElementById('toast');
    if (!t) return;
    t.textContent = msg;
    t.style.opacity = '1';
    t.style.transform = 'translateX(-50%) translateY(0)';
    t.style.background = ok ? 'rgba(40,200,64,.12)' : 'rgba(255,95,87,.12)';
    t.style.color = ok ? '#28c840' : '#ff5f57';
    t.style.borderColor = ok ? 'rgba(40,200,64,.2)' : 'rgba(255,95,87,.2)';
    clearTimeout(t._tid);
    t._tid = setTimeout(function() { t.style.opacity = '0'; t.style.transform = 'translateX(-50%) translateY(-20px)'; }, 2000);
  }

  /* 带超时的 POST：apiPost 本身没有超时，同步类请求不能依赖它
     （一次卡住就会永久占住骨架屏 —— 与"回收池卡死"同类）。
     抽到外层，是因为「进页面自动同步」和「密钥卡片上的手动强制同步」都要用它。 */
  function postSync(action, data, timeoutMs, cb) {
    data._csrf = csrf;
    var xhr = new XMLHttpRequest();
    var done = false;
    function finish(res) { if (done) return; done = true; cb(res); }
    xhr.open('POST', '/admin/api/?action=' + encodeURIComponent(String(action).replace(/\/+$/, '')), true);
    xhr.timeout = timeoutMs;                       // 到点 abort → ontimeout
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onload = function() {
      try { finish(JSON.parse(xhr.responseText)); }
      catch (e) { finish({ ok: false, msg: '解析失败' }); }
    };
    xhr.onerror = function() { finish({ ok: false, msg: '网络错误' }); };
    xhr.ontimeout = function() { finish({ ok: false, msg: '同步超时' }); };
    xhr.onabort = function() { finish({ ok: false, msg: '同步中断' }); };
    xhr.send(JSON.stringify(data));
  }

  /* ═══ 远程歌单自动同步（每次进配置页触发；服务端按歌单各自 10 分钟冷却防滥用）═══
     流程：① 问服务端"哪些远程歌单该同步"（冷却中的不返回）
           ② 给这些卡片上骨架屏（**占住原有结构**，避免空一下再跳出来）
           ③ 按服务端给的顺序**逐个**同步，完成一个就解除那个卡片的骨架屏
     为什么逐个而不是并发：并发打上游容易被限流，且骨架屏"依次解除"正是要的观感。
     任何一步失败都只影响它自己那张卡片，绝不阻断后面的。
     ⚠ 必须有超时：否则一次卡住的请求会让卡片永远停在骨架屏（这正是之前"回收池卡死"同类问题）。 */
  (function(){
    var cards = document.querySelectorAll('.playlist-card-remote[data-pl-id]');
    if (!cards.length) return;                       // 没有远程歌单：什么都不做（自建歌单立刻可见）
    var byId = {};
    cards.forEach(function(c){ byId[String(c.dataset.plId)] = c; });

    // postSync 已提到外层作用域（自动同步与密钥卡片上的手动同步共用同一份）

    // 上骨架屏：**不改 innerHTML、不换节点**，只加类 + 盖一层提示。
    // 为什么不用「innerHTML 换成占位块」的写法：卡片上挂着两类监听（点卡片弹编辑框、
    // 删除按钮），innerHTML 会销毁删除按钮那个元素，换节点会让外层点击监听一起失效 ——
    // 同步完用户就点不开编辑框、也删不掉歌单了。所以这里用"内容隐藏 + 流光遮罩"，
    // 布局尺寸完全不变（结构照样被占住，不跳动），DOM 与监听全部原样保留。
    function toSkeleton(card) {
      if (!card || card.dataset.skel === '1') return;
      card.dataset.skel = '1';
      card.classList.add('pl-card-skeleton');       // CSS 负责隐藏内容 + 流光
      var tip = document.createElement('div');
      tip.className = 'pl-skel-tip';
      tip.textContent = '同步中…';
      card.appendChild(tip);
      card.style.pointerEvents = 'none';            // 同步中别让用户点到编辑框/删除
    }
    // 解除骨架屏：去掉类与提示，恢复可点。note 会短暂显示（如"同步失败"）。
    function release(card, note) {
      if (!card || card.dataset.skel !== '1') return;
      card.dataset.skel = '0';
      card.classList.remove('pl-card-skeleton');
      card.style.pointerEvents = '';
      var tip = card.querySelector('.pl-skel-tip');
      if (!tip) return;
      if (note) {
        tip.textContent = note;
        setTimeout(function(){ if (tip.parentNode) tip.parentNode.removeChild(tip); }, 3000);
      } else {
        if (tip.parentNode) tip.parentNode.removeChild(tip);
      }
    }

    postSync('playlist-sync-queue', {}, 20000, function(q){
      if (!q || !q.ok || !q.queue || !q.queue.length) return;   // 冷却中或没有远程歌单
      var queue = q.queue.slice();
      // 先一次性把所有该同步的卡片都占住（"把卡片结构占住"），再逐个解除
      queue.forEach(function(item){ toSkeleton(byId[String(item.id)]); });
      var i = 0;
      function next() {
        if (i >= queue.length) return;
        var item = queue[i++];
        var card = byId[String(item.id)];
        postSync('playlist-sync', { id: item.id, auto: 1 }, 60000, function(r){
          // 无论成功、冷却跳过还是失败，都要解除这一张的骨架屏（否则永远停在占位）
          if (r && r.ok) release(card);
          else release(card, (r && r.msg) ? String(r.msg).substring(0, 12) : '同步失败');
          next();                                     // 依次推进下一张
        });
      }
      next();
    });
  })();

  /* ═══ 密钥卡片右上角的「强制同步」按钮 ═══
     与上面的自动同步共用 postSync，但**故意不带 auto**：
     服务端 handler 是 `$auto = !empty($input['auto'])` + `force = !$auto`，
     所以不带 auto ⇒ force=true ⇒ **绕过 10 分钟冷却**。
     这正是"用户明确点击"的语义：我就是要现在同步，别拿冷却拦我。
     仍然逐个同步（不并发）：与自动同步同因 —— 并发打上游容易被限流。 */
  (function(){
    var btns = document.querySelectorAll('.key-sync-btn');
    if (!btns.length) return;

    btns.forEach(function(btn){
      var origHTML = btn.innerHTML;
      btn.addEventListener('click', function(){
        if (btn.disabled) return;                                   // 防连点
        var kid = btn.dataset.keyId;
        var grid = document.querySelector('.playlist-grid[data-key-id="' + kid + '"]');
        if (!grid) return;
        // **远程 + 自建都要同步**：后端按类型分流（远程拉上游清单做三方合并，自建逐首回源刷单曲）。
        // 选择器必须覆盖两类卡片 —— 远程卡是 <div class="playlist-card playlist-card-remote">，
        // 自建卡是 <a class="playlist-card">；共同点是都有 .playlist-card 和 data-pl-id。
        var cards = [].slice.call(grid.querySelectorAll('.playlist-card[data-pl-id]'));
        if (!cards.length) { toast('该密钥下没有可同步的歌单', false); return; }

        var total = cards.length, i = 0, okN = 0, skipN = 0, failN = 0, synced = [];
        btn.disabled = true;
        btn.classList.add('is-busy');

        // 与自动同步一致：先把要同步的卡片**全部占住**（结构不跳），再逐个解除
        cards.forEach(function(c){
          c.dataset.skel = '1';
          c.classList.add('pl-card-skeleton');
          c.style.pointerEvents = 'none';
        });
        function unskin(card) {
          card.dataset.skel = '0';
          card.classList.remove('pl-card-skeleton');
          card.style.pointerEvents = '';
          var tip = card.querySelector('.pl-skel-tip');
          if (tip && tip.parentNode) tip.parentNode.removeChild(tip);
        }
        function finish() {
          cards.forEach(unskin);
          btn.disabled = false;
          btn.classList.remove('is-busy');
          btn.innerHTML = origHTML;
          var msg = '同步完成：成功 ' + okN;
          if (skipN) msg += ' · 跳过 ' + skipN;
          if (failN) msg += ' · 失败 ' + failN;
          toast(msg, failN === 0);
          // ⚠ **禁止整页刷新**。同步可能改变的只有「封面」：
          //   · 远程卡片：配置页显示"歌单名 + QQ/网易 远程"，两者都不随同步变化；
          //   · 自建卡片：显示"歌单名 + N 首" —— 刷新只改歌名/歌手/下架标记，**不增减歌曲**，
          //     所以 N 也不变（下架的那条记录仍在 mapi_songs 里，不会从列表消失）。
          //   因此只需**就地换封面**，复用页面已有的 playlist-fetch-cover
          //   （与 [data-need-cover] 那段同一接口）。
          //   只动 .playlist-card-cover 这个**子节点**，绝不碰卡片本身
          //   （卡片上挂着"点开编辑框""删除"两组监听，innerHTML 会把它们毁了）。
          synced.forEach(function(card){
            var wrap = card.querySelector('.playlist-card-cover');
            if (!wrap) return;
            postSync('playlist-fetch-cover', { id: parseInt(card.dataset.plId, 10) }, 20000, function(r){
              if (!r || !r.ok || (!r.cover_b64 && !r.cover_url)) return;   // 取不到就保持原样
              var img = document.createElement('img');
              img.src = r.cover_b64 || r.cover_url;
              img.alt = '';
              img.loading = 'lazy';
              wrap.innerHTML = '';                        // 只清封面容器，卡片结构与监听不动
              wrap.appendChild(img);
            });
          });
        }
        function next() {
          if (i >= total) { finish(); return; }
          var card = cards[i++];
          btn.innerHTML = origHTML + '<span class="key-sync-num">' + i + '/' + total + '</span>';
          // 关键：**不带 auto** ⇒ 服务端 force=true ⇒ 无视 10 分钟冷却
          postSync('playlist-sync', { id: card.dataset.plId }, 60000, function(r){
            if (r && r.ok && r.skipped) skipN++;
            else if (r && r.ok) { okN++; synced.push(card); }         // 只有真同步过的才需要换封面
            else failN++;
            unskin(card);                                            // 这张完事就解除它
            next();
          });
        }
        next();
      });
    });
  })();

  // 抽成具名函数：新建远程歌单后**就地插入**的卡片也要绑上，否则新卡片删不掉。
  function bindDeleteBtn(btn) {
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      var plId = parseInt(this.dataset.plId);
      if (!confirm('确定删除此歌单？')) return;
      apiPost('playlist-delete', { id: plId }, function(r) {
        toast(r.msg, r.ok);
        if (r.ok) setTimeout(function() { if(typeof navigateTo==='function')navigateTo(location.href,false);else location.reload(); }, 500);
      });
    });
  }
  document.querySelectorAll('.playlist-card-delete').forEach(bindDeleteBtn);

  document.querySelectorAll('.playlist-card-add').forEach(function(btn) {
    btn.addEventListener('click', function() {
      activeKeyId = parseInt(this.dataset.keyId);
      document.getElementById('createName').value = '';
      document.getElementById('createRemoteName').value = '';
      document.getElementById('createRemoteId').value = '';
      document.getElementById('createCoverUrl').value = '';
      document.querySelector('input[name="create_type"][value="custom"]').checked = true;
      document.querySelector('input[name="create_cover"][value="auto"]').checked = true;
      toggleCreateType();
      toggleCoverFields();
      createModal.style.display = 'flex';
    });
  });

  createModal.querySelector('.song-modal-backdrop').addEventListener('click', function() { closeSongModal(createModal); });
  createModal.querySelector('.song-modal-close').addEventListener('click', function() { closeSongModal(createModal); });

  createModal.querySelectorAll('input[name="create_type"]').forEach(function(r) {
    r.addEventListener('change', toggleCreateType);
  });
  createModal.querySelectorAll('input[name="create_cover"]').forEach(function(r) {
    r.addEventListener('change', toggleCoverFields);
  });

  function toggleCreateType() {
    var isRemote = document.querySelector('input[name="create_type"][value="remote"]').checked;
    document.getElementById('createCustomFields').style.display = isRemote ? 'none' : '';
    document.getElementById('createRemoteFields').style.display = isRemote ? '' : 'none';
  }

  function toggleCoverFields() {
    var isUrl = document.querySelector('input[name="create_cover"][value="url"]').checked;
    document.getElementById('createCoverUrl').style.display = isUrl ? '' : 'none';
  }

  /* ═══ 远程歌单创建后的「就地插入」═══
     后端 playlist-create 现在**只落库就返回**（不再同步抓歌/抓封面），所以新卡片要在这里
     凭空建出来：占住骨架屏 → 立刻触发一次**强制同步**（不带 auto ⇒ 绕过 10 分钟冷却）
     → 同步完换封面、解除骨架屏。**全程不重渲染页面、不刷新。**
     为什么不用 navigateTo 重渲染：那会把整页内容换一遍，用户看到的仍是"闪一下"。
     ⚠ 卡片一律 createElement + textContent 构建，**绝不拼 innerHTML**（歌单名是用户输入）。 */
  function insertRemoteCard(id, data) {
    var grid = document.querySelector('.playlist-grid[data-key-id="' + activeKeyId + '"]');
    if (!grid || !id) return;

    var card = document.createElement('div');
    card.className = 'playlist-card playlist-card-remote pl-card-skeleton';
    card.dataset.plId = String(id);
    card.dataset.plName = data.name || '';
    card.dataset.remoteId = data.remote_id || '';
    card.dataset.server = data.server || 'netease';
    card.dataset.coverMode = data.cover_mode || 'auto';
    card.dataset.skel = '1';
    card.style.pointerEvents = 'none';                 // 同步中别让用户点到编辑框/删除

    var cover = document.createElement('div');
    cover.className = 'playlist-card-cover';
    var ph = document.createElement('div');
    ph.className = 'playlist-card-placeholder playlist-card-initial';
    ph.textContent = (data.name || '?').substring(0, 1).toUpperCase();
    cover.appendChild(ph);

    var body = document.createElement('div');
    body.className = 'playlist-card-body';
    var nm = document.createElement('span');
    nm.className = 'playlist-card-name';
    nm.textContent = data.name || '';
    var meta = document.createElement('span');
    meta.className = 'playlist-card-meta';
    meta.textContent = (data.server === 'netease' ? '网易' : 'QQ') + ' 远程';
    body.appendChild(nm);
    body.appendChild(meta);

    var del = document.createElement('button');
    del.type = 'button';
    del.className = 'playlist-card-delete danger';
    del.dataset.plId = String(id);
    del.title = '删除';
    del.textContent = '✕';

    card.appendChild(cover);
    card.appendChild(body);
    card.appendChild(del);
    var tip = document.createElement('div');
    tip.className = 'pl-skel-tip';
    tip.textContent = '同步中…';
    card.appendChild(tip);

    var addBtn = grid.querySelector('.playlist-card-add');
    if (addBtn) grid.insertBefore(card, addBtn); else grid.appendChild(card);

    // 页面上原有卡片都是"加载时一次性绑定"的，新卡片必须补绑这两处（拖动是委托绑定，无需处理）
    bindRemoteCard(card);
    bindDeleteBtn(del);

    // 角标「N 歌单」就地 +1
    var keyCard = grid.closest('.key-card');
    var badge = keyCard ? keyCard.querySelector('.key-card-badges .key-badge') : null;
    if (badge) {
      var m = /(\d+)/.exec(badge.textContent || '');
      if (m) badge.textContent = (parseInt(m[1], 10) + 1) + ' 歌单';
    }

    function unskeleton() {
      card.dataset.skel = '0';
      card.classList.remove('pl-card-skeleton');
      card.style.pointerEvents = '';
      var t = card.querySelector('.pl-skel-tip');
      if (t && t.parentNode) t.parentNode.removeChild(t);
    }

    // 立刻在线同步（不带 auto ⇒ force=true ⇒ 绕过冷却）
    postSync('playlist-sync', { id: id }, 90000, function(r) {
      unskeleton();                                    // 无论成败都必须解除，不能留永久占位
      if (!r || !r.ok) {
        toast('歌单已创建，但首次同步失败：' + ((r && r.msg) || '未知错误') + '（可点右上角 ↻ 重试）', false);
        return;
      }
      toast(r.msg || '同步完成', true);
      // 封面已在服务端重算，就地换掉
      postSync('playlist-fetch-cover', { id: id }, 20000, function(cr) {
        if (!cr || !cr.ok || (!cr.cover_b64 && !cr.cover_url)) return;
        var img = document.createElement('img');
        img.src = cr.cover_b64 || cr.cover_url;
        img.alt = '';
        img.loading = 'lazy';
        cover.innerHTML = '';                          // 只清封面容器，卡片结构与监听不动
        cover.appendChild(img);
      });
    });
  }

  document.getElementById('createSubmit').addEventListener('click', function() {
    var type = document.querySelector('input[name="create_type"]:checked').value;
    var coverMode = document.querySelector('input[name="create_cover"]:checked').value;
    var coverUrl = document.getElementById('createCoverUrl').value.trim();
    var data = { key_id: activeKeyId, type: type, cover_mode: coverMode, cover_url: coverUrl };

    if (type === 'custom') {
      data.name = document.getElementById('createName').value.trim();
      data.server = 'netease';
      if (!data.name) { toast('请输入歌单名称', false); return; }
    } else {
      data.name = document.getElementById('createRemoteName').value.trim();
      data.remote_id = document.getElementById('createRemoteId').value.trim();
      data.server = document.getElementById('createRemoteServer').value;
      if (!data.name || !data.remote_id) { toast('名称和 ID 必填', false); return; }
    }

    apiPost('playlist-create', data, function(r) {
      toast(r.msg, r.ok);
      if (!r.ok) return;
      closeSongModal(createModal);
      if (type === 'custom' && r.id) {
        // 自建歌单：目的是进去加歌，所以跳转详情页（这是页面跳转，不是刷新当前页）
        if(typeof navigateTo==='function')navigateTo('/admin/playlist-detail/?id='+r.id);else location.href='/admin/playlist-detail/?id='+r.id;
        return;
      }
      // 远程歌单：**就地插入卡片 + 骨架屏 + 在线同步**，全程不重渲染页面、不刷新。
      // （后端已改为只落库就返回，所以这里拿到 id 时歌还没抓完 —— 正是要的：先让用户看到卡片。）
      insertRemoteCard(r.id, data);
    });
  });

  // 同样抽成具名函数：就地插入的新卡片要能点开编辑框。
  function bindRemoteCard(card) {
    card.addEventListener('click', function(e) {
      if (e.target.closest('.playlist-card-delete')) return;
      if (Date.now() - dragGuardAt < 600) return;                      // 刚拖动过：不要顺手弹出编辑框
      document.getElementById('editPlId').value = this.dataset.plId;
      document.getElementById('editName').value = this.dataset.plName;
      document.getElementById('editRemoteId').value = this.dataset.remoteId;
      document.getElementById('editServer').value = this.dataset.server;
      document.getElementById('editCoverUrl').value = '';
      var cm = this.dataset.coverMode || 'auto';
      document.querySelector('input[name="edit_cover"][value="auto"]').checked = cm !== 'url';
      document.querySelector('input[name="edit_cover"][value="url"]').checked = cm === 'url';
      document.getElementById('editCoverUrl').style.display = cm === 'url' ? '' : 'none';
      editModal.style.display = 'flex';
    });
  }
  document.querySelectorAll('.playlist-card-remote').forEach(bindRemoteCard);

  editModal.querySelector('.song-modal-backdrop').addEventListener('click', function() { closeSongModal(editModal); });
  editModal.querySelector('.song-modal-close').addEventListener('click', function() { closeSongModal(editModal); });

  editModal.querySelectorAll('input[name="edit_cover"]').forEach(function(r) {
    r.addEventListener('change', function() {
      document.getElementById('editCoverUrl').style.display = document.querySelector('input[name="edit_cover"][value="url"]').checked ? '' : 'none';
    });
  });

  document.getElementById('editSubmit').addEventListener('click', function() {
    var pid = parseInt(document.getElementById('editPlId').value);
    var name = document.getElementById('editName').value.trim();
    var remoteId = document.getElementById('editRemoteId').value.trim();
    var server = document.getElementById('editServer').value;
    var coverMode = document.querySelector('input[name="edit_cover"]:checked').value;
    var coverUrl = document.getElementById('editCoverUrl').value.trim();
    if (!name || !remoteId) { toast('名称和 ID 必填', false); return; }
    apiPost('playlist-update', { id: pid, name: name, remote_id: remoteId, server: server, cover_mode: coverMode, cover_url: coverUrl }, function(r) {
      toast(r.msg, r.ok);
      if (r.ok) setTimeout(function() { if(typeof navigateTo==='function')navigateTo(location.href,false);else location.reload(); }, 500);
    });
  });

  document.querySelectorAll('[data-need-cover]').forEach(function(el) {
    var plId = el.getAttribute('data-need-cover');
    if (!plId) return;
    apiPost('playlist-fetch-cover', { id: parseInt(plId) }, function(r) {
      if (r.ok && (r.cover_b64 || r.cover_url)) {
        var coverDiv = el.parentElement;
        var img = document.createElement('img');
        img.src = r.cover_b64 || r.cover_url;
        img.alt = '';
        img.loading = 'lazy';
        coverDiv.innerHTML = '';
        coverDiv.appendChild(img);
      }
    });
  });
  /* ---------- 密钥歌单：左右拖动排序（松手自动保存，播放器歌单顺序同步） ---------- */
  (function () {
    var grids = document.querySelectorAll('.playlist-grid[data-key-id]');
    if (!grids.length) return;

    // 拖动时的视觉样式（注入式，避免改动公共样式文件）。
    // ⚠ 每次都重写 textContent（不是"已存在就跳过"）：SPA 切页时旧 <style> 会残留在 DOM 里，
    //   若跳过更新，这里修正过的 touch-action 就永远不生效。
    var st = document.getElementById('mapi-pl-drag-style');
    if (!st) {
      st = document.createElement('style');
      st.id = 'mapi-pl-drag-style';
      document.head.appendChild(st);
    }
    // touch-action 必须允许**横向平移**。旧值 pan-y 把横向手势彻底禁掉了 ——
    // 这就是"加了拖动后，手指落在卡片上左右滑不动歌单"的根因。
    // 现在拖动由「长按 + touchmove.preventDefault()」接管，不再依赖 touch-action 去拦手势。
    st.textContent = '.playlist-card[data-pl-id]{touch-action:manipulation;cursor:grab;user-select:none;-webkit-user-select:none;-webkit-user-drag:none}' +
      '.playlist-card[data-pl-id] img{-webkit-user-drag:none;user-drag:none;pointer-events:none}' +
      '.playlist-card.pl-dragging{z-index:6;transition:none!important;box-shadow:0 8px 22px rgba(0,0,0,.18);opacity:.94;cursor:grabbing}' +
      '.playlist-card.pl-dragging:hover{transform:none}' +
      // 拖动中关掉 scroll-snap：否则换位时容器会把卡片"吸"回去，看起来像乱跳
      '.playlist-grid.pl-sorting{cursor:grabbing;user-select:none;scroll-snap-type:none}';

    function cardsOf(grid) { return [].slice.call(grid.querySelectorAll('.playlist-card[data-pl-id]')); }
    function orderOf(grid) { return cardsOf(grid).map(function (c) { return c.dataset.plId; }); }

    grids.forEach(function (grid) {
      var card = null, startX = 0, startY = 0, grabDX = 0, cardW = 0, slots = [], origIndex = -1,
          dragging = false, movedFar = false, orderBefore = '', justDragged = 0, listening = false,
          pressTimer = null, dragActive = false, isTouch = false;

      // 触屏：长按这么久才进入拖动（期间手指移开就当作"想滚动歌单"，放行原生手势）
      var LONG_PRESS_MS = 350;
      // 未进入拖动前，手指移动超过这个距离就认为用户是要横向滚动，放弃拖动
      var CANCEL_DIST = 10;

      // 关键：浏览器默认把封面图片/链接当作可拖拽元素，原生拖放会触发 pointercancel 打断我们的拖动，全部禁掉
      [].slice.call(grid.querySelectorAll('img')).forEach(function (im) { im.draggable = false; });
      grid.addEventListener('dragstart', function (e) { e.preventDefault(); e.stopPropagation(); });
      // 长按在移动端会弹系统菜单（iOS 的 callout / 安卓的上下文菜单），拖动期间屏蔽
      grid.addEventListener('contextmenu', function (e) { if (card || dragActive) e.preventDefault(); });
      grid.addEventListener('mousedown', function (e) {
        var t = e.target;
        if (!t || !t.closest) return;
        if (t.closest('.playlist-card-delete')) return;
        if (t.closest('.playlist-card[data-pl-id]')) e.preventDefault();   // 阻止原生拖拽与文字选中（点击仍然照常触发）
      });

      // ⚠ 非 passive，且**只在进入拖动后**才 preventDefault。
      //   拖动一旦开始，用它掐断容器的横向滚动；未进入拖动时绝不拦截 ——
      //   这样"手指落在卡片上左右滑歌单"才能正常滚（这正是本次要修的 bug）。
      grid.addEventListener('touchmove', function (e) {
        if (dragActive && e.cancelable) e.preventDefault();
      }, { passive: false });

      // 兜底：容器真的开始滚动了，就说明用户是想翻歌单而不是拖动排序 —— 取消待触发的长按。
      // （有些浏览器在接管滚动时不一定先发 pointermove/pointercancel，只靠位移判断会漏。）
      grid.addEventListener('scroll', function () {
        if (!dragActive) { clearPressTimer(); card = null; stopListen(); }
      }, { passive: true });

      function clearPressTimer() {
        if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; }
      }

      // 进入拖动模式：触屏由长按触发；鼠标由移动触发（桌面手感保持不变）
      function beginDrag() {
        if (dragActive || !card) return;
        dragActive = true;
        dragging = true;
        card.classList.add('pl-dragging');
        grid.classList.add('pl-sorting');
        // 双保险：拖动期间把容器的 touch-action 也关掉，
        // 免得某些浏览器在 preventDefault 之前就已经把横向滚动"接走"了。
        grid.style.touchAction = 'none';
      }

      function onMove(e) {
        if (!card) return;
        var dx = e.clientX - startX, dy = e.clientY - startY;

        if (!dragActive) {
          if (Math.abs(dx) > 8 || Math.abs(dy) > 8) movedFar = true;

          if (isTouch) {
            // 触屏未长按成功前：一旦明显移动，就说明用户想滚动歌单 → 取消长按、放行原生滚动。
            // 这里**不**设 justDragged：滚动本身不会产生 click，没必要再压掉后续 600ms 的点击
            //（否则"滑一下歌单再马上点某首歌"会被误吞）。
            if (Math.abs(dx) > CANCEL_DIST || Math.abs(dy) > CANCEL_DIST) {
              clearPressTimer();
              card = null;
              stopListen();
            }
            return;                       // 未进入拖动前不拦截任何手势
          }

          // 鼠标：保留原手感 —— 动一点点就开始拖；纵向为主则放弃（只允许左右拖）
          if (Math.abs(dx) < 5 && Math.abs(dy) < 5) return;
          if (Math.abs(dy) > Math.abs(dx)) {
            if (movedFar) { justDragged = Date.now(); dragGuardAt = justDragged; }
            card = null;
            stopListen();
            return;
          }
          beginDrag();
        }
        if (e.cancelable) e.preventDefault();

        // 1) 卡片始终贴着光标走（保持按下时的抓取偏移）
        var wantLeft = e.clientX - grabDX;
        var center = wantLeft + cardW / 2;

        // 2) 只在同一排内比较：哪一格的中心离卡片中心最近就去哪一格（越过邻居中心才换位，不会乱跳）
        var rowTop = slots[origIndex] ? slots[origIndex].top : 0;
        var best = origIndex, bestDist = Infinity;
        for (var i = 0; i < slots.length; i++) {
          if (Math.abs(slots[i].top - rowTop) > 2) continue;              // 不同排不参与（只允许左右拖）
          var d = Math.abs(slots[i].centerX - center);
          if (d < bestDist) { bestDist = d; best = i; }
        }
        var sibs = cardsOf(grid), cur = sibs.indexOf(card);
        if (best !== cur) {
          if (best > cur) grid.insertBefore(card, sibs[best].nextSibling);
          else grid.insertBefore(card, sibs[best]);
          var addBtn = grid.querySelector('.playlist-card-add');
          if (addBtn) grid.appendChild(addBtn);                          // “添加歌单”始终排在最后
        }
        // 3) 落在新格子后重新算位移，保证卡片依旧在光标下（拖动全程不保存）
        card.style.transform = 'translateX(' + (wantLeft - slots[best].left) + 'px)';
      }

      function stopListen() {
        if (!listening) return;
        listening = false;
        document.removeEventListener('pointermove', onMove, true);
        document.removeEventListener('pointerup', onUp, true);
        document.removeEventListener('pointercancel', onUp, true);
      }

      // 只有抬手（松手）才结束拖动并保存一次；拖动过程中的换位不写库
      function onUp() {
        clearPressTimer();
        stopListen();
        var wasActive = dragActive;
        dragActive = false;
        // 拖动过、或长按进入过拖动模式：这次抬起都不当作点击（避免误进歌单/误弹编辑框）
        if (movedFar || wasActive) { justDragged = Date.now(); dragGuardAt = justDragged; }
        if (!card) return;
        var c = card; card = null;
        c.style.transform = '';
        c.classList.remove('pl-dragging');
        grid.classList.remove('pl-sorting');
        grid.style.touchAction = '';                 // 还原，让歌单重新可横向滚动
        if (!wasActive || !dragging) return;
        dragging = false;
        var order = orderOf(grid);
        if (order.join(',') === orderBefore) return;                    // 顺序没变就不发请求
        apiPost('playlist-reorder', { key_id: grid.dataset.keyId, order: order }, function (r) {
          toast((r && r.ok) ? '歌单顺序已保存' : ((r && r.msg) || '顺序保存失败'), !!(r && r.ok));
        });
      }

      grid.addEventListener('pointerdown', function (e) {
        if (e.button !== undefined && e.button !== 0) return;
        if (e.target.closest && e.target.closest('.playlist-card-delete')) return;
        var c = e.target.closest ? e.target.closest('.playlist-card[data-pl-id]') : null;
        if (!c) return;
        clearPressTimer();
        card = c; startX = e.clientX; startY = e.clientY;
        dragging = false; dragActive = false; movedFar = false;
        isTouch = (e.pointerType === 'touch');
        var r = c.getBoundingClientRect();
        grabDX = e.clientX - r.left; cardW = r.width;
        var all = cardsOf(grid);
        // 记住每一格的几何位置: 横向用 rect (悬停只有纵向位移) , 纵向用 offsetTop (不受 hover/拖动的 transform 影响) ,
        slots = all.map(function (el) {
          var b = el.getBoundingClientRect();
          return { left: b.left, top: el.offsetTop, centerX: b.left + b.width / 2 };
        });
        origIndex = all.indexOf(c);
        orderBefore = orderOf(grid).join(',');
        // 用 document 级监听：卡片在换位时会被重新插入 DOM，pointer capture 会因此丢失（那会导致“挪一格就结束”）
        if (!listening) {
          listening = true;
          document.addEventListener('pointermove', onMove, true);
          document.addEventListener('pointerup', onUp, true);
          document.addEventListener('pointercancel', onUp, true);
        }
        // 触屏必须长按才进入拖动；鼠标不在这里进入（由 onMove 按位移触发，保持桌面手感）
        if (isTouch) {
          pressTimer = setTimeout(function () {
            pressTimer = null;
            if (card && !movedFar) beginDrag();
          }, LONG_PRESS_MS);
        }
      });

      // 拖动过之后抑制这一次点击（自建歌单卡片本身是链接，SPA 在 document 冒泡阶段接管跳转）
      grid.addEventListener('click', function (e) {
        if (justDragged && Date.now() - justDragged < 600) { e.preventDefault(); e.stopPropagation(); }
      }, true);
    });
  })();
})();
/* ══════════ 歌单配置导出 / 导入（配置页 · 每个密钥卡） ══════════
   导出：GET keys-export?key_id=… → 浏览器直接下载 JSON（只含歌单与歌曲，不含密钥）。
   导入：POST keys-import（表单上传 JSON）→ 追加式导入，同名歌单跳过。 */

/* 导出确认 → 放行下载。不带 csrf：GET 下载，无写操作。 */
function plExport(kid) {
  if (!confirm('导出该密钥的歌单配置？\n\n· 只含歌单与歌曲列表，不含密钥与密钥名\n· 文件是明文 JSON，请妥善保管')) return;
  location.href = '/admin/api/?action=keys-export&key_id=' + kid;
}

/* 导入弹窗：记住目标密钥，重置文件选择，聚焦输入框。 */
var __plImportKid = 0;
function plImportOpen(kid) {
  __plImportKid = kid;
  var m = document.getElementById('importPlModal');
  if (!m) return;
  if (m.parentElement !== document.body) document.body.appendChild(m);
  var ki = document.getElementById('importPlKid'); if (ki) ki.value = kid;
  var kn = document.getElementById('importPlName');
  if (kn) {
    /* 从当前密钥卡拿掩码名（.key-card[data-api-key] 里的 code 文本） */
    var label = '密钥 #' + kid;
    var card = document.querySelector('.key-card[data-kid="' + kid + '"]');
    if (card) {
      var code = card.querySelector('.key-card-code');
      if (code) label = '密钥 #' + kid + ' · ' + code.textContent.trim();
    }
    kn.textContent = label;
  }
  var f = document.getElementById('importPlFile'); if (f) f.value = '';
  m.style.display = 'flex';
  if (f) setTimeout(function () { try { f.focus(); } catch (e) {} }, 30);
}
function plImportClose() {
  var m = document.getElementById('importPlModal');
  if (!m || m.style.display === 'none' || m.classList.contains('closing')) return;
  m.classList.add('closing');
  var done = function () { m.classList.remove('closing'); m.style.display = 'none'; };
  m.addEventListener('animationend', function onEnd() { m.removeEventListener('animationend', onEnd); done(); });
  setTimeout(function () { if (m.classList.contains('closing')) done(); }, 400);
}
/* 提交前兜底：必须选了 .json 文件；服务端另有 CSRF / 归属 / 格式校验。 */
function plImportSubmit(e) {
  var f = document.getElementById('importPlFile');
  if (!f || !f.files || !f.files.length) { e.preventDefault(); toast('请选择 JSON 文件', false); return false; }
  if (!/\.json$/i.test(f.files[0].name)) { e.preventDefault(); toast('只能导入 .json 文件', false); return false; }
  var btn = document.querySelector('#importPlForm [type="submit"]');
  if (btn) {
    if (btn.disabled) { e.preventDefault(); return false; }
    btn.disabled = true; btn.textContent = '导入中…';
  }
  return true;
}
/* 弹窗关闭交互：backdrop / ✕ / Esc（与添加歌单弹窗同一套 .song-modal 约定）。 */
(function () {
  if (window.__plImportBound) return;
  window.__plImportBound = 1;
  document.addEventListener('click', function (e) {
    var m = document.getElementById('importPlModal');
    if (!m || m.style.display === 'none') return;
    var t = e.target;
    if (!t || !m.contains(t) || !t.closest) return;
    if (t.closest('.song-modal-backdrop') || t.closest('.song-modal-close')) plImportClose();
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') plImportClose(); });
})();