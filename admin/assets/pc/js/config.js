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
      return fetch('?action=' + action, {
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
    xhr.open('POST', '?action=' + action, true);
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
    xhr.open('POST', '?action=' + action, true);
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

  document.querySelectorAll('.playlist-card-delete').forEach(function(btn) {
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
  });

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
      if (r.ok) {
        closeSongModal(createModal);
        if (type === 'custom' && r.id) {
          if(typeof navigateTo==='function')navigateTo('?action=playlist-detail&id='+r.id);else location.href='?action=playlist-detail&id='+r.id;
        } else {
          setTimeout(function() { if(typeof navigateTo==='function')navigateTo(location.href,false);else location.reload(); }, 500);
        }
      }
    });
  });

  document.querySelectorAll('.playlist-card-remote').forEach(function(card) {
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
  });

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

    // 拖动时的视觉样式（注入式，避免改动公共样式文件；脚本可能被重新执行，只注入一次）
    if (!document.getElementById('mapi-pl-drag-style')) {
      var st = document.createElement('style');
      st.id = 'mapi-pl-drag-style';
      st.textContent = '.playlist-card[data-pl-id]{touch-action:pan-y;cursor:grab;user-select:none;-webkit-user-select:none;-webkit-user-drag:none}' +
        '.playlist-card[data-pl-id] img{-webkit-user-drag:none;user-drag:none;pointer-events:none}' +
        '.playlist-card.pl-dragging{z-index:6;transition:none!important;box-shadow:0 8px 22px rgba(0,0,0,.18);opacity:.94;cursor:grabbing}' +
        '.playlist-card.pl-dragging:hover{transform:none}' +
        '.playlist-grid.pl-sorting{cursor:grabbing;user-select:none}';
      document.head.appendChild(st);
    }

    function cardsOf(grid) { return [].slice.call(grid.querySelectorAll('.playlist-card[data-pl-id]')); }
    function orderOf(grid) { return cardsOf(grid).map(function (c) { return c.dataset.plId; }); }

    grids.forEach(function (grid) {
      var card = null, startX = 0, startY = 0, grabDX = 0, cardW = 0, slots = [], origIndex = -1,
          dragging = false, movedFar = false, orderBefore = '', justDragged = 0, listening = false;

      // 关键：浏览器默认把封面图片/链接当作可拖拽元素，原生拖放会触发 pointercancel 打断我们的拖动，全部禁掉
      [].slice.call(grid.querySelectorAll('img')).forEach(function (im) { im.draggable = false; });
      grid.addEventListener('dragstart', function (e) { e.preventDefault(); e.stopPropagation(); });
      grid.addEventListener('mousedown', function (e) {
        var t = e.target;
        if (!t || !t.closest) return;
        if (t.closest('.playlist-card-delete')) return;
        if (t.closest('.playlist-card[data-pl-id]')) e.preventDefault();   // 阻止原生拖拽与文字选中（点击仍然照常触发）
      });

      function onMove(e) {
        if (!card) return;
        var dx = e.clientX - startX, dy = e.clientY - startY;
        if (Math.abs(dx) > 8 || Math.abs(dy) > 8) movedFar = true;        // 移动过就不算点击（避免竖滑误进歌单）
        if (!dragging) {
          if (Math.abs(dx) < 5 && Math.abs(dy) < 5) return;
          if (Math.abs(dy) > Math.abs(dx)) { if (movedFar) { justDragged = Date.now(); dragGuardAt = justDragged; } card = null; stopListen(); return; }  // 只允许左右拖：纵向手势放弃（且不算点击）
          dragging = true;
          card.classList.add('pl-dragging');
          grid.classList.add('pl-sorting');
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
        stopListen();
        if (movedFar) { justDragged = Date.now(); dragGuardAt = justDragged; }   // 动过了：这次抬起不当作点击
        if (!card) return;
        var c = card; card = null;
        c.style.transform = '';
        c.classList.remove('pl-dragging');
        grid.classList.remove('pl-sorting');
        if (!dragging) return;
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
        card = c; startX = e.clientX; startY = e.clientY; dragging = false; movedFar = false;
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
      });

      // 拖动过之后抑制这一次点击（自建歌单卡片本身是链接，SPA 在 document 冒泡阶段接管跳转）
      grid.addEventListener('click', function (e) {
        if (justDragged && Date.now() - justDragged < 600) { e.preventDefault(); e.stopPropagation(); }
      }, true);
    });
  })();
})();