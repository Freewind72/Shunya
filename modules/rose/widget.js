/* rose 皮肤 —— DOM 与样式
 *
 * 版式（严格三行，其余一律不要）：
 *   ① 歌名 ................................. 进度 / 总时长
 *   ② 歌手          [封面 + 环形进度 + 播放键]          播放模式
 *   ③ 🔊音量   歌词开关   ⏮上一首  ⏭下一首        歌曲列表
 * 歌曲列表不在卡片里 —— 点③右端呼出「水平居中弹窗」，弹窗在最上层。
 *
 * 契约：内核用 MP.$() 取第一个匹配，既有 data-mp 一个都不能少
 * （尤其 played / playSvg，缺了内核 updateProgress / updatePlayIcon 会抛错）。
 */

// 与内核的约定：启动脚本执行本文件时把 window.MP 作为参数传进来（同 router 皮肤）。
// 少了这层外壳，裸写 MP 会直接 ReferenceError，皮肤整段空跑（宿主都建不出来）。
(function (MP) {
if (!MP) return;
'use strict';

// 内核挂载时会调用这两个。样式是内联进 shadow root 的（MP._css），没有外部 CSS 要等；
// 少了 createWidget，宿主建不出来 —— 整站播放器直接不出现。
MP.loadCSS = function (cb) { cb(); };

MP.createWidget = function () {
  var host = document.createElement('div');
  host.id = 'mapi-player-' + Math.random().toString(36).slice(2, 8);
  host.style.cssText = 'all:initial;position:fixed;top:50%;left:15px;z-index:2147483647;pointer-events:none';
  document.body.appendChild(host);
  host.addEventListener('contextmenu', function (e) { e.preventDefault(); });
  var shadow = host.attachShadow({ mode: 'closed' });

  var html = MP.getHTML();
  // 内核在挂载流程里可能重写 shadow 内容（连 <style> 一起写掉），所以样式用幂等自愈：
  // 缺了就建、短了就补，已有完整样式时什么都不做。
  function paint() {
    try {
      var st = shadow.querySelector('style');
      if (!st) { st = document.createElement('style'); shadow.insertBefore(st, shadow.firstChild); }
      var css = MP._css || '';
      if ((st.textContent || '').length < css.length) st.textContent = css;
    } catch (e) {}
  }

  // 创建那一刻就带上收起态。若等 bindUI 再补 .collapsed，第一帧是展开的，
  // 会被 transition 动画成「先展开、再收回」地闪一下。
  var _startCollapsed = !window.__rsTestOpen;
  // 侧别也尽量在创建时就定下来：配置若已就绪，首帧就直接是正确的左右镜像，
  // 免得先按默认渲染、再被 syncSide() 纠正成另一边。取不到就交给 CSS 的默认形态。
  var _side0 = 'right';
  try {
    var _raw = (typeof MP._posDefault === 'function') ? MP._posDefault() : (MP._playerPos || '');
    var _m = /^(left|right):\d{1,3}$/.exec(String(_raw));
    if (_m) _side0 = _m[1];
  } catch (e) {}
  shadow.innerHTML = '<style></style><div data-mp="root" data-side="' + _side0 + '"'
    + (_startCollapsed ? ' class="collapsed"' : '') + '>' + html + '</div>';
  paint();
  setTimeout(paint, 0);
  setTimeout(paint, 250);
  setTimeout(paint, 1200);

  // 禁用右键菜单：宿主上拦一次，shadow 里的 root 上再拦一次
  // （事件在 closed shadow 里被重定向，双保险更稳）
  shadow.addEventListener('contextmenu', function (e) { e.preventDefault(); });
  var rootEl = shadow.querySelector('[data-mp="root"]');
  if (rootEl) {
    rootEl.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    rootEl.addEventListener('selectstart', function (e) { e.preventDefault(); });
    rootEl.addEventListener('dragstart', function (e) { e.preventDefault(); });
  }
  return shadow;
};

MP.getHTML = function () {
  return ''
  + '<div data-mp="overlay"></div>'
  + '<div data-mp="panel" class="rs-card">'

    // ① 歌名 + 进度/总时长
    + '<div class="rs-row1">'
      + '<span class="rs-note">\u266a</span>'
      + '<div class="rs-mw rs-ttl" data-mp="ttl"><span class="mi">\u70b9\u51fb\u64ad\u653e</span></div>'
      + '<span class="rs-time"><span data-mp="cur">00:00</span><span class="rs-sl">/</span><span data-mp="dur">00:00</span></span>'
    + '</div>'

    // ② 歌手 | 封面 | 播放模式
    + '<div class="rs-row2">'
      + '<div class="rs-side">'
        + '<span class="rs-lab"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/></svg></span>'
        + '<div class="rs-mw rs-art" data-mp="art"><span class="mi">\u52a0\u8f7d\u4e2d\u2026</span></div>'
      + '</div>'
      + '<div class="rs-cvwrap">'
        + '<svg class="rs-ring" viewBox="0 0 120 120">'
          + '<circle class="rs-ring-bg" cx="60" cy="60" r="55"/>'
          + '<circle class="rs-ring-fg" data-mp="ringFg" cx="60" cy="60" r="55" transform="rotate(-90 60 60)"/>'
        + '</svg>'
        + '<div class="rs-cover" data-mp="cv"><img src="" alt="">'
          + '<button class="rs-play" data-mp="playBtn" draggable="false" title="\u64ad\u653e/\u6682\u505c">'
            + '<svg data-mp="playSvg" viewBox="0 0 24 24" fill="currentColor"><polygon points="7,4 20,12 7,20"/></svg>'
          + '</button>'
        + '</div>'
      + '</div>'
      + '<div class="rs-side rs-side-r">'
        + '<span class="rs-lab"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 2l4 4-4 4M3 11V9a4 4 0 0 1 4-4h14M7 22l-4-4 4-4M21 13v2a4 4 0 0 1-4 4H3"/></svg></span>'
        + '<button class="rs-mode" data-mp="modeTrigger">\u5217\u8868\u64ad\u653e</button>'
        + '<div class="rs-mode-menu" data-mp="modeMenu"></div>'
      + '</div>'
    + '</div>'

    // ③ 音量 | 上一首 下一首 | 歌词 歌曲列表
    + '<div class="rs-row3">'
      + '<span class="rs-grp"><span class="rs-volwrap" data-mp="rsVolWrap">'
        + '<button class="rs-ic" data-mp="rsVolBtn" title="\u97f3\u91cf">'
          + '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M4 9h3l4.5-3.5v13L7 15H4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/><path d="M18 6a8.5 8.5 0 0 1 0 12" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>'
        + '</button>'
        + '<span class="rs-volpop"><input type="range" class="rs-vol" data-mp="vol" min="0" max="1" step="0.05" value="1" title="\u97f3\u91cf"></span>'
      + '</span>'
      + '<button class="rs-boost" data-mp="boost" title="\u97f3\u91cf\u589e\u5f3a">\u589e\u5f3a</button>'
      + '</span>'
        + '<span class="rs-nav"><button class="rs-ic" data-mp="prevBtn" title="\u4e0a\u4e00\u9996"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 6h2.2v12H6z"/><path d="M20 6v12L9.5 12z"/></svg></button>'
        + '<button class="rs-ic" data-mp="nextBtn" title="\u4e0b\u4e00\u9996"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M15.8 6H18v12h-2.2z"/><path d="M4 6v12l10.5-6z"/></svg></button>'
      + '</span><span class="rs-tail"><button class="rs-ic" data-mp="lrcToggle" title="\u6b4c\u8bcd\u5f00\u5173">'
        + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M4 6h10M4 11h7M4 16h9"/><path d="M19 12v6"/><circle cx="17.5" cy="18" r="2" fill="currentColor" stroke="none"/></svg>'
      + '</button>'
      + '<button class="rs-ic" data-mp="rsList" title="\u6b4c\u66f2\u5217\u8868">'
        + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h11"/></svg>'
      + '</button>'
      // 内核要求存在的进度元素：本皮肤用封面环形进度，这里保留但隐藏
      + '</span><span class="rs-hidden"><span data-mp="pbar"><span data-mp="played"></span></span></span>'
    + '</div>'
  + '</div>'

  // 歌曲列表：水平居中弹窗，位于最上层（点遮罩或 ✕ 关闭）
  + '<div class="rs-scrim" data-mp="rsScrim"></div>'
  + '<div class="rs-modal" data-mp="rsModal">'
    + '<div class="rs-modal-hd">'
      + '<button class="rs-mbtn" data-mp="plBack"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>'
      + '<span class="rs-mtitle" data-mp="plName">\u6b4c\u5355</span>'
      + '<button class="rs-mbtn" data-mp="rsModalClose" style="display:flex"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></button>'
    + '</div>'
    + '<div class="rs-modal-body" data-mp="slistInner"></div>'
  + '</div>'

  + '<button class="rs-chev" data-mp="toggle" draggable="false" title="\u6536\u8d77 / \u5c55\u5f00">'
    + '<svg data-mp="toggleSvg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>'
    + '<img data-mp="toggleCover" src="" alt="" style="display:none">'
  + '</button>'
  + '<div data-mp="apContainer" style="display:none"></div>'

  // 沉浸模式占位（本皮肤不用，但契约要求存在）
  + '<div class="rs-im">'
    + '<div data-mp="immersiveBtn"></div><div data-mp="imClose"></div><div data-mp="imPlay"></div><div data-mp="imPrev"></div><div data-mp="imNext"></div>'
    + '<div data-mp="imPbar"></div><div data-mp="imPfill"></div><div data-mp="imCur"></div><div data-mp="imDur"></div>'
    + '<div data-mp="imSlist"></div><div data-mp="imSlistInner"></div><div data-mp="imSlistTrigger"></div><div data-mp="imPlBack"></div>'
    + '<div data-mp="imModeBtn"></div><input data-mp="imVol" type="range" min="0" max="1" step="0.05" value="1">'
    + '<div data-mp="imContent"></div><div data-mp="imCover"></div><div data-mp="imTitle"></div><div data-mp="imArtist"></div>'
  + '</div>';
};

MP._css = ''
+ '[data-mp="root"]{position:relative;pointer-events:none;font:13px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}'
// 禁掉选中/长按菜单/图片拖拽 —— 只作用于播放器自己的 shadow 树，绝不影响宿主页面
+ '[data-mp="root"]{user-select:none;-webkit-user-select:none;-webkit-touch-callout:none;-webkit-tap-highlight-color:transparent}'
+ '.rs-card,.rs-modal,.rs-chev{user-select:none;-webkit-user-select:none}'
+ '.rs-cover img,.rs-modal-body img{-webkit-user-drag:none;user-drag:none;pointer-events:none}'
// ═══════════════════ 位置锚点（整体位置只改这一块）═══════════════════
// 宿主位置由内核按后台「播放器初始位置」摆放；这里是相对那个位置的统一微调。
// 卡片、把柄、弹窗都挂在 root 下，所以改动这里 = 整体移动，不用再翻其它规则。
// 需要按后台/皮肤参数动态改时，也只需覆盖这几个变量（例如 skin.js 里 root.style.setProperty）。
+ '[data-mp="root"]{--rs-width:300px;--rs-gutter:20px;--rs-anchor-x:0px;--rs-anchor-y:0px;--rs-chev-top:79px;'
  + 'margin-left:var(--rs-anchor-x);margin-top:var(--rs-anchor-y)}'
+ '[data-mp="root"]>*{pointer-events:auto}'
+ '[data-mp="overlay"]{display:none}'
+ '.rs-hidden{display:none!important}'
+ '.rs-im{display:none}'
+ '.mi{opacity:.62}'
+ '*{box-sizing:border-box}'

// ── 卡片 ──
+ '.rs-card{width:var(--rs-width);border-radius:14px;padding:10px 12px 9px;color:#fff;'
  + 'background:linear-gradient(160deg,#c2185b 0%,#b3123f 42%,#8c0f33 100%);'
  + 'box-shadow:0 14px 38px rgba(120,10,50,.38),inset 0 1px 0 rgba(255,255,255,.20);'
  + 'transition:opacity .22s ease}'
+ '.rs-mw{overflow:hidden;white-space:nowrap;text-overflow:ellipsis}'

// ── ① 歌名 + 进度/总时长 ──
+ '.rs-row1{display:flex;align-items:center;gap:6px;height:20px}'
+ '.rs-note{flex:none;font-size:14px;opacity:.85}'
+ '.rs-ttl{flex:1 1 auto;min-width:0;font-size:14px;font-weight:700;letter-spacing:.2px}'
+ '.rs-time{flex:none;font-size:12px;font-variant-numeric:tabular-nums;opacity:.92}'
+ '.rs-sl{margin:0 3px;opacity:.5}'

// ── ② 歌手 | 封面 | 播放模式 ──
+ '.rs-row2{display:flex;align-items:center;gap:6px;padding:7px 0 5px}'
+ '.rs-side{flex:1 1 0;min-width:0;display:flex;align-items:center;gap:5px}'
+ '.rs-side-r{justify-content:flex-end;position:relative}'
+ '.rs-lab{flex:none;opacity:.7;display:flex}'
+ '.rs-lab svg{width:13px;height:13px}'
+ '.rs-art{font-size:12px;opacity:.95}'
+ '.rs-cvwrap{flex:none;position:relative;width:84px;height:84px;display:flex;align-items:center;justify-content:center}'
+ '.rs-ring{position:absolute;inset:0;width:100%;height:100%;overflow:visible}'
+ '.rs-ring-bg{fill:none;stroke:rgba(255,255,255,.22);stroke-width:3}'
+ '.rs-ring-fg{fill:none;stroke:#fff;stroke-width:3;stroke-linecap:round;transition:stroke-dashoffset .25s linear}'
+ '.rs-cover{position:relative;width:68px;height:68px;border-radius:50%;overflow:hidden;background:rgba(255,255,255,.14);box-shadow:0 6px 16px rgba(0,0,0,.28)}'
+ '.rs-cover img{width:100%;height:100%;object-fit:cover;display:block;animation:rsSpin 16s linear infinite;animation-play-state:paused}'
+ '.rs-cover.playing img{animation-play-state:running}'
+ '@keyframes rsSpin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}'
+ '.rs-play{position:absolute;inset:0;margin:auto;width:28px;height:28px;border:0;border-radius:50%;cursor:pointer;'
  + 'background:rgba(255,255,255,.94);color:#b3123f;display:flex;align-items:center;justify-content:center;'
  + 'box-shadow:0 3px 10px rgba(0,0,0,.26);transition:transform .12s}'
+ '.rs-play:hover{transform:scale(1.07)}'
+ '.rs-play svg{width:13px;height:13px;margin-left:1px}'
+ '.rs-mode{border:0;background:none;color:#fff;font:inherit;font-size:12px;cursor:pointer;padding:2px 0;opacity:.95;white-space:nowrap}'
+ '.rs-mode:hover{opacity:1;text-decoration:underline}'
+ '.rs-mode-menu{display:none!important}'

// ── ③ 音量 | 歌词 | 上一首 下一首 | 歌曲列表 ──
+ '.rs-row3{display:flex;align-items:center;padding-top:1px}'
// 音量与歌词成一组贴在一起（间距 8px，不紧不散）；上一首/下一首各自独立分布在中间
+ '.rs-grp{order:1;flex:1 1 0;display:flex;align-items:center;justify-content:flex-start;gap:8px;min-width:0}'
+ '.rs-tail{order:3;flex:1 1 0;display:flex;align-items:center;justify-content:flex-end;gap:8px;min-width:0}'
+ '.rs-ic{border:0;background:none;color:#fff;cursor:pointer;padding:3px;border-radius:7px;display:flex;flex:none;opacity:.92}'
+ '.rs-ic:hover{opacity:1;background:rgba(255,255,255,.15)}'
+ '.rs-ic svg{width:17px;height:17px}'
// 上一首/下一首的图形只占 viewBox 的 ~58%（左右有留白），单独放大才和别的图标视觉等重
+ '[data-mp="prevBtn"] svg,[data-mp="nextBtn"] svg{width:22px;height:22px}'
+ '.rs-ic.active{color:#3ddc84;opacity:1}'
+ '.rs-nav{order:2;flex:0 0 auto;display:flex;align-items:center;gap:26px}'
// 上一首/下一首：间距拉开 + 各自左右留出点击区，不再挤在一起
+ '.rs-nav .rs-ic{padding:5px 8px}'
// 音量增强（内核提供逻辑：点一下循环 1x → 2x → 3x，文案由内核写入）
+ '.rs-boost{border:0;background:rgba(255,255,255,.15);color:#fff;font:inherit;font-size:11px;line-height:1;'
  + 'padding:5px 7px;border-radius:7px;cursor:pointer;opacity:.92;white-space:nowrap;flex:none}'
+ '.rs-boost:hover{opacity:1;background:rgba(255,255,255,.24)}'
+ '.rs-boost.on{background:#3ddc84;color:#08341c;font-weight:700;opacity:1}'
// 档位用颜色表达（文案固定“增强”）：2x 绿、3x 黄。放在 .on 之后，权重相同时靠顺序覆盖。
+ '.rs-boost.b2{background:#3ddc84;color:#08341c;font-weight:700;opacity:1}'
+ '.rs-boost.b3{background:#ffd54a;color:#4a3600;font-weight:700;opacity:1}'
+ '.rs-volwrap{position:relative;display:flex;flex:none}'
// 音量按钮在最左端，弹层往右展开
+ '.rs-volpop{position:absolute;left:-4px;bottom:26px;display:none;align-items:center;'
  + 'padding:6px 10px;border-radius:9px;background:rgba(50,4,20,.96);box-shadow:0 8px 22px rgba(0,0,0,.45)}'
+ '.rs-volwrap.open .rs-volpop{display:flex}'
+ '.rs-vol{-webkit-appearance:none;appearance:none;width:104px;height:14px;background:none;cursor:pointer}'
+ '.rs-vol::-webkit-slider-runnable-track{height:4px;border-radius:3px;background:rgba(255,255,255,.32)}'
+ '.rs-vol::-webkit-slider-thumb{-webkit-appearance:none;width:12px;height:12px;margin-top:-4px;border-radius:50%;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.4)}'
+ '.rs-vol::-moz-range-track{height:4px;border-radius:3px;background:rgba(255,255,255,.32)}'
+ '.rs-vol::-moz-range-thumb{width:12px;height:12px;border:0;border-radius:50%;background:#fff}'

// ── 歌曲列表弹窗（水平居中，最上层）──
+ '.rs-scrim{position:fixed;inset:0;background:rgba(18,0,9,.52);display:none;z-index:2147483645}'
+ '.rs-scrim.open{display:block}'
+ '.rs-modal{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(320px,88vw);max-height:72vh;'
  + 'display:none;flex-direction:column;z-index:2147483646;border-radius:14px;color:#fff;overflow:hidden;'
  + 'background:linear-gradient(160deg,#c2185b 0%,#a81140 45%,#7d0d2e 100%);box-shadow:0 24px 60px rgba(0,0,0,.55)}'
+ '.rs-modal.open{display:flex}'
+ '.rs-modal-hd{display:flex;align-items:center;gap:6px;padding:9px 10px;border-bottom:1px solid rgba(255,255,255,.16)}'
+ '.rs-mtitle{flex:1 1 auto;min-width:0;font-size:13px;font-weight:700;color:#ffd54a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
+ '.rs-mbtn{border:0;background:none;color:#ffd54a;cursor:pointer;padding:2px;display:none;flex:none}'
+ '.rs-mbtn.visible{display:flex}'
+ '.rs-mbtn svg{width:14px;height:14px}'
+ '.rs-modal-body{flex:1 1 auto;overflow-y:auto;overscroll-behavior:contain;padding:5px 6px 8px}'
// ── 歌曲列表：固定尺寸（与曲目数量无关）+ 行样式 + 入场动画 ──
// 尺寸用显式 height（不是 max-height），只跟视口有关：1 首和 200 首的面板一样大。
+ '.rs-modal{width:min(340px,88vw);height:min(520px,78vh);max-height:none}'
// 行用 flex 而不是 grid —— 未解析出封面时内核渲染的是空的 si-cover 占位框（避免行高与文字左右跳），
// 空框在 flex 下按基尺寸(34px)占位，网格列宽则会被「有内容/没内容」带偏。
+ '.rs-modal-body .songitem,.rs-modal-body .pl-list-item{display:flex;align-items:center;gap:9px;'
  + 'padding:7px 9px;border-radius:9px;cursor:pointer;position:relative;'
  + 'transition:background .16s ease,transform .16s ease}'
+ '.rs-modal-body .songitem:hover,.rs-modal-body .pl-list-item:hover{background:rgba(255,255,255,.09);transform:translateX(2px)}'
+ '.rs-modal-body .songitem:active,.rs-modal-body .pl-list-item:active{transform:scale(.985)}'
+ '.rs-modal-body .si-idx,.rs-modal-body .pl-idx{flex:0 0 22px;text-align:center;font-size:12px;opacity:.5;'
  + 'position:relative;height:16px;line-height:16px}'
+ '.rs-modal-body .si-cover,.rs-modal-body .pl-cover{flex:0 0 34px;width:34px;height:34px;border-radius:8px;object-fit:cover;'
  + 'box-shadow:inset 0 0 0 1px rgba(255,255,255,.18),0 2px 6px rgba(0,0,0,.25);'
  + 'display:flex;align-items:center;justify-content:center;font-size:12px}'
+ '.rs-modal-body .si-name,.rs-modal-body .pl-name{flex:1 1 auto;min-width:0;font-size:13px;color:#fff;opacity:.92;'
  + 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
+ '.rs-modal-body .si-artist,.rs-modal-body .pl-count{flex:0 1 auto;max-width:42%;font-size:12px;opacity:.5;'
  + 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
// 歌曲行比歌单行再松一点：歌名长、右侧还有歌手，视觉上更密。
// margin 是必须的 —— 相邻两行的圆角底色直接贴在一起时，会看成「两个封面/两块底叠在一起」。
+ '.rs-modal-body .songitem{padding:8px 9px;gap:10px;margin:1px 0}'
// 歌曲封面：contain 而不是 cover —— 歌曲封面常是非正方形（16:9 等），
// cover 会把上下裁掉（看着像被切了一半）；contain 完整显示，留白用深底兜住。
+ '.rs-modal-body .si-cover{object-fit:contain;background:rgba(0,0,0,.22)}'
// 播放中：玫瑰高亮，序号位换成跳动的音柱（不是多加一列）
+ '.rs-modal-body .songitem.active{background:linear-gradient(90deg,rgba(194,24,91,.42),rgba(194,24,91,.12))}'
+ '.rs-modal-body .songitem.active .si-name{font-weight:700;opacity:1}'
// 依次加载：还没解析出来的行（pending 加载中 / failed 失败），点了会优先加载或重试
+ '.rs-modal-body .songitem.pending{opacity:.5}'
+ '.rs-modal-body .songitem.failed{opacity:.35}'
+ '.rs-modal-body .songitem.active .si-idx{color:transparent;font-size:0}'
+ '.rs-modal-body .songitem.active .si-idx::before,.rs-modal-body .songitem.active .si-idx::after{'
  + 'content:"";position:absolute;left:50%;bottom:50%;width:3px;border-radius:2px;background:#ff7fb0;'
  + 'transform-origin:bottom center;animation:rsEq .85s ease-in-out infinite alternate}'
+ '.rs-modal-body .songitem.active .si-idx::before{margin-left:-5px;height:13px}'
+ '.rs-modal-body .songitem.active .si-idx::after{margin-left:1px;height:9px;animation-delay:-.28s}'
// 内核的「加载中/无歌曲/加载失败」是内联灰字，在玫瑰底上太暗
+ '.rs-modal-body>div[style*="color:#999"]{color:rgba(255,255,255,.55)!important}'
+ '.rs-modal-body .pl-retry{color:#ffd54a!important}'
+ '@keyframes rsRowIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}'
+ '@keyframes rsEq{from{transform:scaleY(.35)}to{transform:scaleY(1)}}'
// 入场阶梯：每行 18ms，第 12 行封顶（否则 200 首要等 3.6 秒才看完入场）。
// ⚠ 只挂在「刚打开面板」的 .enter 状态下 —— 内核每次换歌都会 renderSonglist() 重建整个列表，
//   动画若常驻在行上，就会每次重建都重放一遍（选歌时列表闪几下）。
+ '.rs-modal.enter .rs-modal-body>*{animation:rsRowIn .24s cubic-bezier(.2,.9,.25,1) both}'
+ (function () { var s = ''; for (var i = 1; i <= 12; i++) s += '.rs-modal.enter .rs-modal-body>*:nth-child(' + i + '){animation-delay:' + ((i - 1) * 18) + 'ms}'; return s; })()
+ '.rs-modal-body::-webkit-scrollbar{width:5px}'
+ '.rs-modal-body::-webkit-scrollbar-thumb{background:rgba(255,255,255,.32);border-radius:3px}'
// 注意：这里原本还有一整套「旧版列表样式」（.songitem 固定 30px 高、20×20 cover、播放中绿色底…）。
// 它排在前面那套新规则之后，会把新规则全部覆盖掉（改了看不到变化）。已删除，列表样式统一见上方。

// ── 抽屉把柄（与卡片一体：向卡片侧交叠 1px，投影只朝外）──
// 中线对准第二行：卡片上内边距 10 + 第一行 20 + 第二行上边距 7 + 封面 84/2 = 79
+ '.rs-chev{position:absolute;top:var(--rs-chev-top);transform:translateY(-50%)!important;width:20px;height:46px;border:0;cursor:pointer;'
  + 'background:#f6a623;color:#5a3500;display:flex;align-items:center;justify-content:center;padding:0;z-index:8}'
+ '.rs-chev svg{width:13px;height:13px}'
+ '.rs-chev:hover{background:#ffb63a}'
+ '.rs-chev img,[data-mp="toggleCover"]{display:none!important;width:0!important;height:0!important}'
// 默认形态 = 面板靠右（把柄在卡片左侧/内侧）。写成【不带 data-side 的默认规则】，
// 是因为 data-side 要等挂载后 syncSide() 才写上去 —— 挂在 [data-side=...] 上的话，
// 首帧这些规则全都不生效，把柄会没定位、卡片让位失效、箭头方向也是错的，然后"啪"地跳一下。
+ '.rs-chev{left:-1px;border-radius:8px 0 0 8px;box-shadow:-3px 0 10px rgba(0,0,0,.20)}'
+ '.rs-chev svg{transform:rotate(180deg)}'
+ '[data-mp="root"].collapsed .rs-chev svg{transform:rotate(0)}'
+ '[data-mp="root"]{transition:transform .28s ease}'
+ '[data-mp="root"].collapsed{transform:translateX(calc(100% + var(--rs-mr,15px) - 20px))}'
+ '.rs-card{margin-left:var(--rs-gutter)}'
// 面板靠左：以上全部镜像。选择器权重更高，稳定覆盖默认规则。
+ '[data-mp="root"][data-side="left"] .rs-chev{left:auto;right:-1px;border-radius:0 8px 8px 0;box-shadow:3px 0 10px rgba(0,0,0,.20)}'
+ '[data-mp="root"][data-side="left"] .rs-chev svg{transform:rotate(0)}'
+ '[data-mp="root"][data-side="left"].collapsed .rs-chev svg{transform:rotate(180deg)}'
+ '[data-mp="root"][data-side="left"].collapsed{transform:translateX(calc(-100% - var(--rs-mr,15px) + 20px))}'
+ '[data-mp="root"][data-side="left"] .rs-card{margin-left:0;margin-right:var(--rs-gutter)}'
+ '[data-mp="root"].collapsed .rs-card{opacity:0;pointer-events:none}'

// ── 移动端（≤768px，与内核 _applyDefaultPos 的断点一致）──
// 卡片宽度改为按视口收敛，避免窄屏被把柄和边距挤出屏幕；图标与列表行放大到可点尺寸。
+ '@media (max-width:768px){'
  + '.rs-card{width:min(var(--rs-width),calc(100vw - var(--rs-gutter) - var(--rs-mr,4px) - 10px))}'
  + '.rs-ic{padding:6px}'
  + '.rs-ic svg{width:19px;height:19px}'
  + '[data-mp="prevBtn"] svg,[data-mp="nextBtn"] svg{width:25px;height:25px}'
  + '.rs-nav{gap:34px}'
  + '.rs-play{width:32px;height:32px}'
  + '.rs-play svg{width:15px;height:15px}'
  + '.rs-chev{width:22px;height:50px}'
  // 移动端：底部上浮抽屉（不是居中弹窗）。高度仍固定，与曲目数量无关。
  + '.rs-modal{left:0;right:0;bottom:var(--mapi-inset-player,0px);top:auto;transform:none;width:100%;height:46vh;max-height:none;'
    + 'border-radius:20px 20px 0 0;padding-bottom:env(safe-area-inset-bottom,0)}'
  // 顶部拖拽把手（视觉上暗示“可以拖”，也是拖拽热区的一部分）
  + '.rs-modal-hd{position:relative;padding-top:16px;touch-action:none}'
  + '.rs-modal-hd::before{content:"";position:absolute;top:7px;left:50%;margin-left:-19px;'
    + 'width:38px;height:4px;border-radius:2px;background:rgba(255,255,255,.34)}'
  // 全屏档位（往上拖的吸附点）；跟手时关掉过渡，松手才插值
  + '.rs-modal.full{height:94vh}'
  + '.rs-modal.dragging{transition:none}'
  + '.rs-modal.dragging .rs-modal-hd::before{background:rgba(255,255,255,.6)}'
  // 高度也要参与过渡，否则拉伸后不会吸附回去
  + '.rs-modal,.rs-modal:not(.open){transition:transform .26s cubic-bezier(.2,.9,.25,1),opacity .2s ease,'
    + 'height .26s cubic-bezier(.2,.9,.25,1),visibility .26s}'
  // 手指友好的行高与封面
  // 手指友好 ≠ 一味放大：行高只留 44px 左右的触控底线（32 封面 + 上下各 6），不再用 38/10 那套
  + '.rs-modal-body .songitem,.rs-modal-body .pl-list-item{padding:6px 9px;gap:9px}'
  + '.rs-modal-body .si-cover,.rs-modal-body .pl-cover{flex:0 0 32px;width:32px;height:32px}'

// ── 开关动画 ──
// 用 visibility 代替 display:none：display 是硬切，过渡无法生效。
// 桌面：淡入 + 微缩放；移动端：从底部滑出（下浮出）。
+ '.rs-scrim{display:block;opacity:0;visibility:hidden;transition:opacity .18s ease,visibility .18s}'
+ '.rs-scrim.open{opacity:1;visibility:visible}'
+ '.rs-modal{display:flex;opacity:0;visibility:hidden;pointer-events:none;'
  + 'transform:translate(-50%,-50%) scale(.96);'
  + 'transition:opacity .22s cubic-bezier(.2,.9,.25,1),transform .22s cubic-bezier(.2,.9,.25,1),visibility .22s}'
+ '.rs-modal.open{opacity:1;visibility:visible;pointer-events:auto;transform:translate(-50%,-50%) scale(1)}'
// 关闭比打开快（退出要干脆）
+ '.rs-scrim:not(.open){transition-duration:.14s}'
+ '.rs-modal:not(.open){transition-duration:.14s}'
// 移动端：抽屉从屏幕底部升起
+ '@media (max-width:768px){'
  + '.rs-modal{transform:translateY(100%)}'
  + '.rs-modal.open{transform:translateY(0)}'
  // 跟手/吸附的高度过渡，与上面的移动端块合并（不要再开一个 @media，会多出未闭合的块）
  + '.rs-modal{transform:translateY(100%)}'
  + '.rs-modal.open{transform:translateY(0)}'
  + '.rs-modal,.rs-modal:not(.open){transition:transform .26s cubic-bezier(.2,.9,.25,1),opacity .2s ease,'
    + 'height .26s cubic-bezier(.2,.9,.25,1),visibility .26s}'
  // ⚠ 这里不要提前加 '}'：下面的行高/音量规则都属于移动端，必须留在 @media 内。
  //   之前多了一个 '}'，导致 .songitem{height:34px} 泄漏到桌面端 —— 固定 34px 行高装不下
  //   34px 封面 + 22px 内边距，行与行就会叠在一起。
  + '.rs-modal-body .songitem{height:auto;font-size:13px}'
  + '.rs-modal-body .pl-list-item{height:auto;font-size:13px}'
  + '.rs-volpop{padding:8px 12px}'
  + '.rs-vol{width:118px;height:18px}'
  // 补齐：@media 少一个闭合（花括号深度 1）。补在字符串最末尾，
  // 只影响平衡性、不改变任何已有规则的作用范围（后面没有内容了）。
  + '}'
  + '}'
// ══ [PC] 歌曲列表面板放大 ×1.5 ══
// 用 min-width 包起来：移动端完全不参与（上面的 max-width 块才是移动端）。
// 系数统一为 1.5（原 340/520 面板、34 封面、13px 歌名 全部按同一比例算）。
+ '@media (min-width:769px){'
  // 出现动效：照经典皮肤沉浸式那套 —— 从一个点放大铺开 + 淡入。
  // 经典代码：immersiveOverlay{transform:scale(.05);transition:.35s cubic-bezier(.4,0,.2,1)}
  //           .open{transform:scale(1)} —— 这里把同一套搬到菜单面板上。
  // ⚠ display 必须强制为 flex：元素一旦是 display:none，加 .open 时它是「从没渲染直接跳到最终态」，
  //   transition 完全不触发（实测关闭态 transform 计算值是 none、全程 scale(1) 无过渡）。
  //   所以让它在关闭态也参与渲染，可见性/交互交给 visibility + opacity + pointer-events。
  + '.rs-modal{display:flex!important;visibility:hidden;opacity:0;pointer-events:none;'
    + 'transform:translate(-50%,-50%) scale(.05);'
    + 'transition:opacity .35s cubic-bezier(.4,0,.2,1),transform .35s cubic-bezier(.4,0,.2,1),visibility .35s}'
  + '.rs-modal.open{visibility:visible;opacity:1;pointer-events:auto;'
    + 'transform:translate(-50%,-50%) scale(1)}'
  + '.rs-modal:not(.open){transition:opacity .3s cubic-bezier(.4,0,.2,1),'
    + 'transform .3s cubic-bezier(.4,0,.2,1),visibility .3s}'
  + '.rs-scrim{display:block!important;visibility:hidden;opacity:0;'
    + 'transition:opacity .35s cubic-bezier(.4,0,.2,1),visibility .35s}'
  + '.rs-scrim.open{visibility:visible;opacity:1}'
  + '.rs-scrim:not(.open){transition:opacity .3s cubic-bezier(.4,0,.2,1),visibility .3s}'
  + '.rs-modal{width:min(510px,90vw);height:min(640px,76vh)}'
  + '.rs-modal-hd{padding:14px 16px;gap:9px}'
  + '.rs-mtitle{font-size:20px}'
  + '.rs-mbtn svg{width:21px;height:21px}'
  + '.rs-modal-body{padding:8px 9px 12px}'
  + '.rs-modal-body::-webkit-scrollbar{width:8px}'
  // 行
  + '.rs-modal-body .songitem,.rs-modal-body .pl-list-item{gap:10px;padding:8px 12px;border-radius:14px}'
  + '.rs-modal-body .songitem{padding:9px 12px;gap:11px;margin:1px 0}'
  // 序号 / 封面 / 文字
  + '.rs-modal-body .si-idx,.rs-modal-body .pl-idx{flex:0 0 33px;font-size:18px;height:24px;line-height:24px}'
  + '.rs-modal-body .si-cover,.rs-modal-body .pl-cover{flex:0 0 51px;width:51px;height:51px;border-radius:12px;font-size:17px}'
  + '.rs-modal-body .si-name,.rs-modal-body .pl-name{font-size:20px}'
  + '.rs-modal-body .si-artist,.rs-modal-body .pl-count{font-size:18px}'
  // 播放中的两根音柱同步放大
  + '.rs-modal-body .songitem.active .si-idx::before{width:5px;height:20px;margin-left:-8px}'
  + '.rs-modal-body .songitem.active .si-idx::after{width:5px;height:14px;margin-left:1px}'
  // 内核的「加载中/无歌曲/加载失败」是内联 font-size:13px，只能 important 覆盖
  + '.rs-modal-body>div[style*="color:#999"]{font-size:20px!important}'
  + '.rs-modal-body .pl-retry{font-size:20px!important}'
+ '}'
// 依次加载：还没解析出来的行，封面位写「加载」（失败写「失败」）。放在媒体查询之后，
// 且用 .songitem.pending 提高优先级 → 大布局里 17px 的封面字号不会把它撑爆
+ '.rs-modal-body .songitem.pending .si-cover,.rs-modal-body .songitem.failed .si-cover'
+ '{display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:600;line-height:1;color:rgba(255,255,255,.62);object-fit:unset}';

})(window.MP);
