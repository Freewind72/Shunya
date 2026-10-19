/* playlist-detail.js — 歌单详情页面脚本 */
(function(){
  var D = document.getElementById('playlist-detail-data');
  if (!D) return;
  var P = JSON.parse(D.dataset.page);
  var csrf = P.csrf;
  var playlistId = P.playlistId;
  var searchToken = P.searchToken;
  var apiBase = P.apiBase;
  var pId = P.pId;

  // 将弹窗移到 body 下, 使其 fixed 定位基于视口, 可覆盖侧边栏
  ['songModal', 'manualModal', 'editPlModal'].forEach(function(id) {
    var stale = document.querySelectorAll('body > #' + id);
    for (var i = 0; i < stale.length; i++) stale[i].remove();
  });
  var songModal = document.getElementById('songModal');
  var manualModal = document.getElementById('manualModal');
  var editPlModal = document.getElementById('editPlModal');
  if (songModal) document.body.appendChild(songModal);
  if (manualModal) document.body.appendChild(manualModal);
  if (editPlModal) document.body.appendChild(editPlModal);
  var pServer = P.pServer;

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
  var pType = P.pType;
  var plName = P.plName;
  var plCoverMode = P.plCoverMode;

  var _previewAudio = null;
  var _previewPlayingId = null;
  function stopPreview() {
    if (_previewAudio) { _previewAudio.pause(); _previewAudio = null; }
    _previewPlayingId = null;
    document.querySelectorAll('.song-result-pic.playing').forEach(function(el) { el.classList.remove('playing'); });
  }
  function previewSong(songId, server, imgEl) {
    if (_previewPlayingId === songId) {
      stopPreview();
      return;
    }
    stopPreview();
    if (!searchToken) { toast('未获取到 Token', false); return; }
    imgEl.classList.add('playing');
    _previewPlayingId = songId;
    var url = '/admin/api/api.php?action=url&server=' + encodeURIComponent(server || 'netease') + '&id=' + encodeURIComponent(songId) + '&token=' + encodeURIComponent(searchToken);
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onload = function() {
      try {
        var d = JSON.parse(xhr.responseText);
        var audioUrl = d.url || '';
        if (!audioUrl) { toast('未获取到音频', false); stopPreview(); return; }
        _previewAudio = new Audio(audioUrl);
        _previewAudio.play().catch(function() { toast('播放失败', false); });
        _previewAudio.addEventListener('ended', function() { stopPreview(); });
        _previewAudio.addEventListener('error', function() { toast('音频加载失败', false); stopPreview(); });
      } catch(e) { toast('解析失败', false); stopPreview(); }
    };
    xhr.onerror = function() { toast('请求失败', false); stopPreview(); };
    xhr.send();
  }

  function post(action, data, cb) {
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

  // 歌曲排序
  document.querySelectorAll('.song-ord-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      var rowId = this.dataset.rowId, dir = this.dataset.dir;
      var card = this.closest('.song-card');
      if (!card || !rowId) return;
      post('song-reorder', { playlist_id: playlistId, row_id: rowId, dir: dir }, function(r) {
        toast(r.msg, r.ok);
        if (!r.ok || !r.order) return;
        var grid = card.parentNode;
        if (!grid) return;
        r.order.forEach(function(id) {
          var el = grid.querySelector('.song-card[data-row-id="' + id + '"]');
          if (el) grid.appendChild(el);
        });
      });
    });
  });

  // 同步远程歌单快照
  var syncBtn = document.getElementById('syncPlBtn');
  if (syncBtn) {
    syncBtn.addEventListener('click', function() {
      var old = syncBtn.textContent;
      syncBtn.disabled = true;
      syncBtn.textContent = '同步中…';
      post('playlist-sync', { id: playlistId }, function(r) {
        syncBtn.disabled = false;
        syncBtn.textContent = old;
        toast(r.msg, r.ok);
        if (r.ok) setTimeout(function() {
          if (typeof navigateTo === 'function') navigateTo(location.href, false); else location.reload();
        }, 900);
      });
    });
  }

  document.querySelectorAll('.song-card-remove').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      var rowId = this.dataset.rowId;
      if (!confirm('确定删除此歌曲？')) return;
      post('song-remove', { song_row_id: rowId }, function(r) {
        toast(r.msg, r.ok);
        if (r.ok) {
          var card = btn.closest('.song-card');
          if (card) {
            card.style.opacity = '0';
            card.style.transform = 'scale(.85)';
            card.style.transition = 'all .25s';
            setTimeout(function() { if(typeof navigateTo==='function')navigateTo(location.href,false);else location.reload(); }, 300);
          }
        }
      });
    });
  });

  document.getElementById('openSearchBtn').addEventListener('click', function() {
    document.getElementById('songSearchInput').value = '';
    document.getElementById('songSearchResults').innerHTML = '<div class="song-modal-hint">输入关键词搜索网易云音乐</div>';
    songModal.style.display = 'flex';
    setTimeout(function() { document.getElementById('songSearchInput').focus(); }, 100);
  });

  document.getElementById('openManualBtn').addEventListener('click', function() {
    document.getElementById('manualSongId').value = '';
    document.getElementById('manualSongName').value = '';
    document.getElementById('manualSongArtist').value = '';
    manualModal.style.display = 'flex';
    setTimeout(function() { document.getElementById('manualSongId').focus(); }, 100);
  });

  songModal.querySelector('.song-modal-backdrop').addEventListener('click', function() { stopPreview(); closeSongModal(songModal); });
  songModal.querySelector('.song-modal-close').addEventListener('click', function() { stopPreview(); closeSongModal(songModal); });
  manualModal.querySelector('.song-modal-backdrop').addEventListener('click', function() { closeSongModal(manualModal); });
  manualModal.querySelector('.song-modal-close').addEventListener('click', function() { closeSongModal(manualModal); });

  if (editPlModal) {
    var editPlName = document.getElementById('editPlName');
    var editPlCoverUrl = document.getElementById('editPlCoverUrl');
    document.getElementById('openEditPlBtn').addEventListener('click', function() {
      editPlName.value = plName;
      editPlCoverUrl.value = '';
      var cm = plCoverMode;
      document.querySelector('input[name="edit_pl_cover"][value="auto"]').checked = cm !== 'url';
      document.querySelector('input[name="edit_pl_cover"][value="url"]').checked = cm === 'url';
      editPlCoverUrl.style.display = cm === 'url' ? '' : 'none';
      editPlModal.style.display = 'flex';
      setTimeout(function() { editPlName.focus(); }, 100);
    });
    editPlModal.querySelector('.song-modal-backdrop').addEventListener('click', function() { closeSongModal(editPlModal); });
    editPlModal.querySelector('.song-modal-close').addEventListener('click', function() { closeSongModal(editPlModal); });
    document.querySelectorAll('input[name="edit_pl_cover"]').forEach(function(r) {
      r.addEventListener('change', function() {
        editPlCoverUrl.style.display = document.querySelector('input[name="edit_pl_cover"][value="url"]').checked ? '' : 'none';
      });
    });
    document.getElementById('editPlSubmit').addEventListener('click', function() {
      var name = editPlName.value.trim();
      var coverMode = document.querySelector('input[name="edit_pl_cover"]:checked').value;
      var coverUrl = editPlCoverUrl.value.trim();
      if (!name) { toast('名称必填', false); return; }
      var payload = { _csrf: csrf, id: playlistId, name: name, remote_id: '', server: 'netease', cover_mode: coverMode, cover_url: coverUrl };
      var xhr = new XMLHttpRequest();
      xhr.open('POST', '/admin/api/?action=playlist-update', true);
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.onload = function() {
        try { var r = JSON.parse(xhr.responseText); toast(r.msg, r.ok); if (r.ok) { closeSongModal(editPlModal); setTimeout(function() { if(typeof navigateTo==='function')navigateTo(location.href,false);else location.reload(); }, 500); } } catch(e) { toast('解析失败', false); }
      };
      xhr.onerror = function() { toast('网络错误', false); };
      xhr.send(JSON.stringify(payload));
    });
  }

  document.getElementById('songSearchBtn').addEventListener('click', doSearch);
  document.getElementById('songSearchInput').addEventListener('keydown', function(e) { if (e.key === 'Enter') doSearch(); });

  function animatePanelHeight(panel, cb) {
    var oldH = panel.offsetHeight;
    panel.style.transition = 'none';
    panel.style.height = oldH + 'px';
    panel.style.overflow = 'hidden';
    if (cb) cb();
    var newH = panel.scrollHeight;
    if (newH > parseFloat(getComputedStyle(panel).maxHeight)) newH = parseFloat(getComputedStyle(panel).maxHeight);
    panel.style.transition = 'height .3s cubic-bezier(.4,0,.2,1)';
    panel.offsetHeight;
    panel.style.height = newH + 'px';
    var done = function() { panel.style.height = ''; panel.style.transition = ''; panel.style.overflow = ''; panel.removeEventListener('transitionend', done); };
    panel.addEventListener('transitionend', done);
    setTimeout(done, 350);
  }

  function doSearch() {
    var kw = document.getElementById('songSearchInput').value.trim();
    if (!kw) return;
    var results = document.getElementById('songSearchResults');
    var panel = songModal.querySelector('.song-modal-panel');
    results.innerHTML = '<div class="song-modal-hint">搜索中…</div>';
    var url = '/admin/api/api.php?action=search&keyword=' + encodeURIComponent(kw) + '&server=netease&limit=20&token=' + encodeURIComponent(searchToken);
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onload = function() {
      try {
        var list = JSON.parse(xhr.responseText);
        if (!list.length) { results.innerHTML = '<div class="song-modal-hint">未找到结果</div>'; return; }
        animatePanelHeight(panel, function() {
          results.innerHTML = '';
          list.forEach(function(s) {
            var div = document.createElement('div');
            div.className = 'song-result-item';
            var picHtml = '';
            if (s.pic) {
              picHtml = '<div class="song-result-pic-wrap"><img class="song-result-pic" src="' + esc(s.pic) + '" alt="" loading="lazy" onerror="this.style.display=\'none\'"><span class="song-result-play-icon"></span></div>';
            }
            div.innerHTML = picHtml + '<div class="song-result-info"><span class="song-result-name">' + esc(s.name) + '</span><span class="song-result-artist">' + esc(s.artist) + '</span></div><button class="btn-sm song-result-add" style="flex-shrink:0;font-size:10px;padding:3px 10px">添加</button>';
            div.querySelector('.song-result-add').addEventListener('click', function() {
              post('song-add', { playlist_id: playlistId, song_id: s.id, name: s.name, artist: s.artist, server: 'netease' }, function(r) {
                toast(r.msg, r.ok);
                if (r.ok) {
                  div.querySelector('.song-result-add').textContent = '\u2713';
                  div.querySelector('.song-result-add').style.pointerEvents = 'none';
                  div.querySelector('.song-result-add').style.opacity = '.5';
                  setTimeout(function() { if(typeof navigateTo==='function')navigateTo(location.href,false);else location.reload(); }, 800);
                }
              });
            });
            var picWrap = div.querySelector('.song-result-pic-wrap');
            if (picWrap) {
              picWrap.addEventListener('click', function(e) {
                e.stopPropagation();
                previewSong(String(s.id), s.server || 'netease', picWrap.querySelector('.song-result-pic'));
              });
            }
            results.appendChild(div);
          });
        });
      } catch(e) { results.innerHTML = '<div class="song-modal-hint">搜索失败</div>'; }
    };
    xhr.onerror = function() { results.innerHTML = '<div class="song-modal-hint">网络错误</div>'; };
    xhr.send();
  }

  document.getElementById('manualSongSubmit').addEventListener('click', function() {
    var sid = document.getElementById('manualSongId').value.trim();
    var sn = document.getElementById('manualSongName').value.trim();
    var sa = document.getElementById('manualSongArtist').value.trim();
    var sv = document.getElementById('manualSongServer').value;
    if (!sid || !sn) { toast('ID 和名称必填', false); return; }
    post('song-add', { playlist_id: playlistId, song_id: sid, name: sn, artist: sa, server: sv }, function(r) {
      toast(r.msg, r.ok);
      if (r.ok) { closeSongModal(manualModal); setTimeout(function() { if(typeof navigateTo==='function')navigateTo(location.href,false);else location.reload(); }, 500); }
    });
  });

  function esc(s) { var d = document.createElement('span'); d.textContent = s; return d.innerHTML; }
})();