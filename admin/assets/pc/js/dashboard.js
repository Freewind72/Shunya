function initDebugExpand() {
  var dt = document.getElementById('debugToggle');
  if (dt && dt.checked) {
    var card = dt.closest('.card');
    if (card) card.classList.add('debug-expanded');
  }
}

initDebugExpand();

if (!window.__dashDebugChangeBound) { window.__dashDebugChangeBound = 1; document.addEventListener('change', function (e) {
  var dt = e.target;
  if (dt.id !== 'debugToggle') return;
  var on = dt.checked ? 1 : 0;
  var card = dt.closest('.card');
  if (card) {
    if (on) card.classList.add('debug-expanded');
    else card.classList.remove('debug-expanded');
  }
  var fd = new FormData();
  fd.append('debug', on);
  fd.append('_csrf', dt.getAttribute('data-csrf') || '');
  fetch('?action=debug-toggle', { method: 'POST', body: fd })
    .then(function (r) { return r.text(); })
    .then(function (txt) {
      if (txt.trim() !== 'ok') showToast('切换失败', 'err');
      else {
        var logList = document.getElementById('logList');
        if (logList) {
          fetch('?action=dashboard')
            .then(function (r) { return r.text(); })
            .then(function (html) {
              var doc = new DOMParser().parseFromString(html, 'text/html');
              var newLogList = doc.getElementById('logList');
              if (newLogList) logList.innerHTML = newLogList.innerHTML;
            });
        }
      }
    })
    .catch(function () { showToast('请求失败', 'err'); });
}); }