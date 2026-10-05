/* domains.js — 宿主域名授权页（桌面端）
   表单优先走 AJAX 保存，失败自动回退成普通提交，保证没有 fetch 也能用。 */
(function () {
  'use strict';
  window.DomainsUI = { host: 'pc', onReady: [] };

  function toast(msg, ok) {
    var t = (typeof window.Toast === 'function') ? window.Toast
          : (typeof window.showToast === 'function') ? window.showToast
          : (typeof window.toast === 'function') ? window.toast : null;
    if (t) { t(msg, ok === false ? 'error' : 'success'); return; }
    if (ok === false) { alert(msg); }
  }

  function ready(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function val(sel, root) { var el = $(sel, root); return el ? el.value : ''; }

  function setSubmitLabel(label) {
    var btn = $('#domSubmit');
    if (!btn) return;
    if (!btn.dataset.origin) btn.dataset.origin = btn.innerHTML;
    btn.innerHTML = label;
  }

  // ── 编辑 / 取消编辑 ───────────────────────────────────────────────
  function fillForm(d) {
    var form = $('#domForm');
    if (!form) return;
    $('#domId').value = d.id || '0';
    $('#domDomain').value = d.domain || '';
    $('#domLyrics').value = (d.lyrics === '' || d.lyrics == null) ? '0' : d.lyrics;
    $('#domPlayer').value = (d.player === '' || d.player == null) ? '' : d.player;
    $('#domNote').value = d.note || '';
    var a = form.querySelector('input[name=authorized]');
    var au = form.querySelector('input[name=auto]');
    if (a) a.checked = (d.authorized === '1' || d.authorized === 1);
    if (au) au.checked = (d.auto === '1' || d.auto === 1);
    setSubmitLabel('保存修改');
    var reset = $('#domReset');
    if (reset) reset.hidden = false;
  }

  function resetForm() {
    var form = $('#domForm');
    if (!form) return;
    $('#domId').value = '0';
    $('#domDomain').value = '';
    $('#domLyrics').value = '0';
    $('#domPlayer').value = '';
    $('#domNote').value = '';
    var a = form.querySelector('input[name=authorized]');
    var au = form.querySelector('input[name=auto]');
    if (a) a.checked = true;
    if (au) au.checked = true;
    var btn = $('#domSubmit');
    if (btn && btn.dataset.origin) btn.innerHTML = btn.dataset.origin;
    var reset = $('#domReset');
    if (reset) reset.hidden = true;
  }

  function bindEdit() {
    $all('.dom-edit').forEach(function (btn) {
      btn.addEventListener('click', function () {
        fillForm(btn.dataset);
        var form = $('#domForm');
        if (form && form.scrollIntoView) form.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });
    var reset = $('#domReset');
    if (reset) reset.addEventListener('click', resetForm);
  }

  // ── 保存（先 AJAX，失败回退普通提交）──────────────────────────────
  function bindSubmit() {
    var form = $('#domForm');
    if (!form || !form.dataset.ajax) return;
    if (typeof window.fetch !== 'function' || typeof window.FormData !== 'function') return;

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = $('#domSubmit');
      if (btn) btn.disabled = true;

      fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok) {
            toast(j.message || '已保存', true);
            // 不整页刷新（页内测试播放器会被刷掉）：走 SPA 软切换，只换 .wrap 内容并重跑页面脚本
            setTimeout(function () {
              if (typeof navigateTo === 'function') navigateTo(location.href, false);
              else location.reload();
            }, 400);
            return;
          }
          if (btn) btn.disabled = false;
          toast((j && (j.message || j.error)) || '保存失败', false);
        })
        .catch(function () {
          // 拿不到 JSON（网络 / 会话过期）就交回浏览器按普通表单提交
          if (btn) btn.disabled = false;
          form.dataset.ajax = '';
          if (form.requestSubmit) form.requestSubmit(); else form.submit();
        });
    });
  }

  // ── 开关表单（授权域名 / 自动登记）──────────────────────────────
  // 这两个开关原来是行内 onchange="this.form.submit()"：form.submit() 不触发 submit 事件，
  // 会绕过 base.js 的 AJAX 提交拦截 → 整页 POST → 页内测试播放器被刷掉。
  // 改为绑 change 后用 requestSubmit()，走统一拦截，页面不真刷新。
  function bindSwitchForms() {
    if (window.__domSwitchBound) return;
    window.__domSwitchBound = 1;
    // 委托挂在 document 上：软切换只换 .wrap 内容，绑在元素上的监听会随旧节点一起失效
    document.addEventListener('change', function (e) {
      var cb = e.target;
      if (!cb || cb.type !== 'checkbox') return;
      if (!cb.closest || !cb.closest('.dom-switch-form')) return;
      var form = cb.form || cb.closest('form');
      if (!form) return;
      if (form.requestSubmit) form.requestSubmit(); else form.submit();
    });
  }

  // ── 点域名复制 ───────────────────────────────────────────────────
  function bindCopy() {
    $all('.dom-host').forEach(function (el) {
      el.addEventListener('click', function () {
        var text = el.dataset.host || el.textContent.trim();
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(function () { toast('已复制 ' + text, true); }, function () { toast('复制失败，请手动选中', false); });
        } else {
          toast('请手动选中复制：' + text, true);
        }
      });
    });
  }

  ready(function () {
    bindEdit();
    bindSubmit();
    bindSwitchForms();
    bindCopy();
    (window.DomainsUI.onReady || []).forEach(function (fn) { try { fn(); } catch (e) {} });
  });
})();