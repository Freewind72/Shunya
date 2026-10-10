/* domains.js — 宿主域名授权页（移动端）
   表单优先走 AJAX 保存，失败自动回退成普通提交，保证没有 fetch 也能用。 */
(function () {
  'use strict';
  window.DomainsUI = { host: 'mobile', onReady: [] };

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
  // 底部让出量分 PC / 移动端两套：data-pc-* / data-mo-* 由列表行带过来。
  var DEVS = ['pc', 'mo'];   // mo = 移动端（表单字段前缀）

  function devInputs(pre) {
    return {
      auto:   document.querySelector('input[name="' + pre + '_auto"][type=checkbox]'),
      lyrics: $('#dom' + pre.charAt(0).toUpperCase() + pre.slice(1) + 'Lyrics'),
      player: $('#dom' + pre.charAt(0).toUpperCase() + pre.slice(1) + 'Player'),
      detected: $('#dom' + pre.charAt(0).toUpperCase() + pre.slice(1) + 'Detected')
    };
  }

  // 「最近探测」只在真的有上报值（> 0）时显示，并可一键填进歌词修正
  function showDetected(pre, value) {
    var d = devInputs(pre);
    if (!d.detected) return;
    var v = parseInt(value, 10);
    if (!v || v <= 0) { d.detected.hidden = true; return; }
    d.detected.hidden = false;
    var num = d.detected.querySelector('.dom-detected-val');
    if (num) num.textContent = v;
    var fill = d.detected.querySelector('.dom-fill-detected');
    if (fill) { fill.dataset.value = v; }
  }

  function setDev(pre, auto, lyrics, player, detected) {
    var d = devInputs(pre);
    if (d.auto) d.auto.checked = (auto === '1' || auto === 1 || auto === true);
    if (d.lyrics) d.lyrics.value = (lyrics === '' || lyrics == null) ? '0' : lyrics;
    if (d.player) d.player.value = (player === '' || player == null) ? '' : player;
    showDetected(pre, detected);
  }

  function fillForm(d) {
    var form = $('#domForm');
    if (!form) return;
    $('#domId').value = d.id || '0';
    $('#domDomain').value = d.domain || '';
    $('#domNote').value = d.note || '';
    var keySel = $('#domKeyId');
    if (keySel) {
      var kv = String(d.keyId || '0');
      // 绑定的密钥已被删除时下拉里没有这一项：补一个只读项，避免静默变成「不限」
      if (kv !== '0' && !keySel.querySelector('option[value="' + kv + '"]')) {
        var opt = document.createElement('option');
        opt.value = kv;
        opt.textContent = '密钥 #' + kv + '（已删除）';
        keySel.appendChild(opt);
      }
      keySel.value = kv;
    }
    var a = form.querySelector('input[name=authorized]');
    if (a) a.checked = (d.authorized === '1' || d.authorized === 1);
    DEVS.forEach(function (pre) {
      setDev(pre, d[pre + 'Auto'], d[pre + 'Lyrics'], d[pre + 'Player'], d[pre + 'Detected']);
    });
    setSubmitLabel('保存修改');
    var reset = $('#domReset');
    if (reset) reset.hidden = false;
  }

  function resetForm() {
    var form = $('#domForm');
    if (!form) return;
    $('#domId').value = '0';
    $('#domDomain').value = '';
    $('#domNote').value = '';
    var keySel = $('#domKeyId');
    if (keySel) keySel.value = '0';
    var a = form.querySelector('input[name=authorized]');
    if (a) a.checked = true;
    DEVS.forEach(function (pre) {
      var d = devInputs(pre);
      if (d.auto) d.auto.checked = true;          // 默认两端都开自动
      if (d.lyrics) d.lyrics.value = '0';
      if (d.player) d.player.value = '';
      if (d.detected) d.detected.hidden = true;   // 新增模式下不显示某个域名残留的探测值
    });
    var btn = $('#domSubmit');
    if (btn && btn.dataset.origin) btn.innerHTML = btn.dataset.origin;
    var reset = $('#domReset');
    if (reset) reset.hidden = true;
  }

  // 「填入歌词修正」：把上报的探测值写进同端的歌词修正框（手动模式下等于直接采用实测值）
  function bindFillDetected() {
    if (window.__domFillBound) return;
    window.__domFillBound = 1;
    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('.dom-fill-detected') : null;
      if (!btn) return;
      e.preventDefault();
      var target = btn.dataset.target ? document.getElementById(btn.dataset.target) : null;
      if (!target) return;
      target.value = btn.dataset.value || '0';
      target.focus();
    });
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

      // new FormData(form) 不含"提交按钮"的 name/value —— 后端若靠它判断动作就会落空，
      // 这里按规范补上（e.submitter 为现代浏览器标准字段）
      var fd = new FormData(form);
      var sub = e.submitter || window.__lastSubmitter || null;
      if (sub && sub.name) fd.append(sub.name, sub.value);

      fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' })
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
    bindFillDetected();
    (window.DomainsUI.onReady || []).forEach(function (fn) { try { fn(); } catch (e) {} });
  });
})();