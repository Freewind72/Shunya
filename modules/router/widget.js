(function(MP){
    if (!MP) return;
    var _ = MP._;

MP._css = '*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent;user-select:none;-webkit-user-select:none;font-family:-apple-system,"PingFang SC","Microsoft YaHei","Noto Sans SC",sans-serif}'+
'[data-mp="root"]{position:relative;display:flex;flex-direction:column;align-items:flex-end;pointer-events:none}'+
'[data-mp="root"]>*{pointer-events:auto}'+
'[data-mp="toggle"]{position:absolute;z-index:2147483648;bottom:-28px;right:0;width:48px;height:48px;border-radius:50%;border:2px solid rgba(255,255,255,.85);background:rgba(255,255,255,.4);backdrop-filter:blur(12px) saturate(200%);-webkit-backdrop-filter:blur(12px) saturate(200%);color:#1a1a2e;cursor:pointer;display:none;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(0,0,0,.1),0 0 10px rgba(255,255,255,.35);transform:translateZ(0);transition:transform .3s cubic-bezier(.4,0,.2,1),opacity .3s;touch-action:manipulation;user-select:none}'+
'@keyframes mpPulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.92)}}'+
'@keyframes mpPulseDark{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.92)}}'+
'@keyframes mpSpin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}'+
'[data-mp="toggleSvg"]{display:block}'+
'[data-mp="toggleCover"]{border-radius:50%;object-fit:cover;animation:mpSpin 8s linear infinite;animation-play-state:paused;will-change:transform;transform:translateZ(0)}'+
'[data-mp="toggle"].loading{animation:mpPulse 1.2s ease-in-out infinite}'+
'[data-mp="toggle"].playing [data-mp="toggleCover"]{animation-play-state:running}'+
'@media(min-width:769px){[data-mp="toggle"]:hover{transform:translateX(0) translateZ(0)!important;border-color:rgba(255,255,255,.95);background:rgba(255,255,255,.55)}}'+
'@media(max-width:768px){[data-mp="toggle"]{transform:translateX(0)}}'+
'[data-mp="panel"]{position:relative;background:rgba(255,255,255,.36);backdrop-filter:blur(24px) saturate(200%);border:1px solid rgba(255,255,255,.7);border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,.12);width:320px;margin-bottom:30px;padding:16px;transform:translateY(20px) scale(.95);opacity:0;pointer-events:none;transition:all .3s cubic-bezier(.34,1.56,.64,1);transform-origin:bottom right}'+
'[data-mp="panel"]:not(.open){visibility:hidden;backdrop-filter:none!important;-webkit-backdrop-filter:none!important}'+
'[data-mp="panel"].open{transform:translateY(0) scale(1);opacity:1;pointer-events:auto;visibility:visible}'+
// 顶部停靠（PC）：圆形按钮翻到宿主上沿、面板改为向下浮出，收起动画方向同步翻转。
// 按钮定位从 bottom:-28px 换成 top:-28px，面板外边距从下边换到上边，保证「按钮 → 面板」的间距不变。
'[data-mp="root"][data-dock="top"] [data-mp="toggle"]{bottom:auto;top:-28px}'+
'[data-mp="root"][data-dock="top"] [data-mp="panel"]{margin-bottom:0;margin-top:30px}'+
'[data-mp="root"][data-dock="top"] [data-mp="panel"]:not(.open){transform:translateY(-20px) scale(.95)}'+
'.info{display:flex;align-items:center;gap:12px;margin-bottom:12px}'+
'.cover{width:48px;height:48px;border-radius:10px;background:rgba(0,0,0,.05);flex-shrink:0;overflow:hidden}'+
'.cover img{width:100%;height:100%;object-fit:cover}'+
'.mtext{flex:1;min-width:0}'+
'.title{font-size:15px;font-weight:600;color:#1a1a2e}'+
'.artist{font-size:13px;font-weight:600;color:#666;margin-top:2px}'+
'.mw{overflow:hidden;white-space:nowrap;max-width:100%;min-width:0;text-overflow:clip}'+
'.mw .mi{display:inline-block;white-space:pre;animation:marquee var(--md,0s) linear infinite}'+
'@keyframes marquee{0%{transform:translateX(0)}100%{transform:translateX(var(--mx))}}'+
'.progress{margin-bottom:10px}'+
'.pbar{width:100%;height:4px;border-radius:2px;background:rgba(0,0,0,.1);cursor:pointer;position:relative}'+
'.played{height:100%;border-radius:2px;background:rgba(0,0,0,.25);transition:width .2s;width:0%}'+
'.ptime{display:flex;justify-content:space-between;font-size:12px;font-weight:600;color:#999;margin-top:3px}'+
'.controls{display:flex;align-items:center;justify-content:center;gap:8px}'+
'.cbtn{width:36px;height:36px;border-radius:50%;border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.06);backdrop-filter:blur(8px)saturate(200%);cursor:pointer;display:flex;align-items:center;justify-content:center;color:#1a1a2e;transition:all .2s;padding:0;box-shadow:0 0 10px rgba(255,255,255,.2),0 0 20px rgba(255,255,255,.1)}'+
'.cbtn:hover{background:rgba(255,255,255,.22);border-color:rgba(255,255,255,.6);transform:scale(1.05)}'+
'.cbtn svg{width:18px;height:18px}'+
'.playbtn{width:44px;height:44px;border:1px solid rgba(255,255,255,.4);background:rgba(255,255,255,.08);backdrop-filter:blur(8px)saturate(200%);box-shadow:0 0 12px rgba(255,255,255,.25),0 0 24px rgba(255,255,255,.12)}'+
'.mvol{display:flex;align-items:center;gap:6px;margin-top:8px}'+
'.mvol svg{width:16px;height:16px;color:#999;flex-shrink:0}'+
'.mvol input[type=range]{flex:1;min-width:60px;height:5px;-webkit-appearance:none;appearance:none;background:rgba(0,0,0,.08);border-radius:3px;outline:none;cursor:pointer}'+
'.mvol input::-webkit-slider-thumb{-webkit-appearance:none;width:16px;height:16px;border-radius:50%;background:rgba(0,0,0,.35);cursor:pointer;border:2px solid rgba(255,255,255,.8);box-shadow:0 1px 4px rgba(0,0,0,.12);transition:transform .15s}'+
'.mvol input::-webkit-slider-thumb:hover{transform:scale(1.2)}'+
'.mvol .b-btn{background:none;border:1px solid rgba(0,0,0,.08);border-radius:8px;padding:3px 0;font-size:10px;font-weight:600;color:#999;cursor:pointer;line-height:1.4;transition:all .2s;font-family:inherit;white-space:nowrap;width:54px;text-align:center;flex-shrink:0}'+
'.mvol .b-btn:hover{border-color:rgba(0,0,0,.2);color:#1a1a2e}'+
'.mvol .b-btn.on{background:rgba(255,200,50,.2);border-color:rgba(255,180,0,.4);color:#c89600}'+
'.bottom-row{display:flex;align-items:center;justify-content:space-between;margin-top:8px;padding:0 4px}'+
'.mode-dropdown{position:relative;display:inline-block}'+
'.mode-trigger{font-size:13px;font-weight:600;color:#1a1a2e;border:1px solid rgba(0,0,0,.12);border-radius:8px;padding:4px 20px 4px 8px;background:rgba(255,255,255,.3);cursor:pointer;outline:none;position:relative;line-height:1.4}'+
'.mode-trigger::after{content:\'\\25BE\';position:absolute;right:6px;top:50%;transform:translateY(-50%);font-size:10px;color:#666}'+
'.mode-menu{position:absolute;top:100%;left:0;margin-top:2px;min-width:100%;background:rgba(255,255,255,.85);backdrop-filter:blur(20px)saturate(200%);border:1px solid rgba(255,255,255,.7);border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.1);overflow:hidden;z-index:10;white-space:nowrap;opacity:0;visibility:hidden;transform:translateY(-4px);transition:opacity .2s cubic-bezier(.4,0,.2,1),transform .2s cubic-bezier(.4,0,.2,1),visibility .2s;pointer-events:none}'+
'.mode-menu.open{opacity:1;visibility:visible;transform:translateY(0);pointer-events:auto}'+
'.mode-option{padding:6px 16px;font-size:13px;font-weight:600;color:#333;cursor:pointer;transition:background .15s}'+
'.mode-option:hover{background:rgba(0,0,0,.04)}'+
'.mode-option.active{background:rgba(0,0,0,.08);color:#1a1a2e;font-weight:700}'+
'.im-bottom-row{width:100%;display:flex;align-items:center;margin-top:0;padding:0;justify-content:space-between}'+
'.im-bottom-icon-btn{cursor:pointer;color:#999;display:inline-flex;align-items:center;justify-content:center;padding:4px;background:none;border:none;outline:none;border-radius:4px;transition:color .2s}'+
'.im-bottom-icon-btn:hover{color:#1a1a2e}'+
'.im-slist-dropdown{position:absolute;top:100%;left:0;margin-top:4px;min-width:180px;max-height:200px;overflow-y:auto;background:rgba(255,255,255,.85);backdrop-filter:blur(20px)saturate(200%);border:1px solid rgba(255,255,255,.7);border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.1);z-index:10;opacity:0;visibility:hidden;transform:translateY(-4px);transition:opacity .2s cubic-bezier(.4,0,.2,1),transform .2s cubic-bezier(.4,0,.2,1),visibility .2s;pointer-events:none;padding:4px 0;scrollbar-width:none;-ms-overflow-style:none}'+
'.im-slist-dropdown::-webkit-scrollbar{display:none}'+
'.im-slist-dropdown.open{opacity:1;visibility:visible;transform:translateY(0);pointer-events:auto}'+
'.im-slist-dropdown .songitem{padding:6px 12px;font-size:13px;font-weight:600;color:#333;cursor:pointer;transition:background .15s;display:flex;gap:8px;border-radius:0}'+
'.im-slist-dropdown .songitem:hover{background:rgba(0,0,0,.04)}'+
'.im-slist-dropdown .songitem.active{background:rgba(0,0,0,.08);color:#1a1a2e;font-weight:700}'+
'.im-slist-dropdown .songitem .si-idx{color:#999;font-size:12px;font-weight:600;width:16px;text-align:right;flex-shrink:0}'+
'.im-slist-dropdown .songitem .si-name{flex:1;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}'+
'.im-slist-dropdown .songitem .si-artist{color:#999;font-size:12px;font-weight:600;max-width:60px;overflow:hidden;text-overflow:ellipsis}'+
'.im-mode-btn{cursor:pointer;color:#999;transition:color .2s;display:inline-flex;align-items:center;justify-content:center;padding:4px 0;background:none;border:none;outline:none}'+
'.im-mode-btn:hover{color:#1a1a2e}'+
'.lrc-area{display:flex;align-items:center;gap:8px}'+
'.lrc-label{font-size:13px;font-weight:600;color:#666}'+
'.lrc-toggle{width:36px;height:20px;border-radius:10px;border:1px solid rgba(0,0,0,.12);background:rgba(0,0,0,.08);cursor:pointer;position:relative;transition:all .3s;padding:0;outline:none;flex-shrink:0}'+
'.lrc-toggle::after{content:\'\';position:absolute;top:2px;left:2px;width:14px;height:14px;border-radius:50%;background:#fff;transition:all .3s;box-shadow:0 1px 3px rgba(0,0,0,.15)}'+
'.lrc-toggle.active{background:rgba(0,0,0,.25);border-color:rgba(0,0,0,.2)}'+
'.lrc-toggle.active::after{left:18px}'+
'.pl-back{cursor:pointer;display:none;align-items:center;gap:4px;padding:4px 0;font-size:11px;font-weight:600;color:rgba(0,0,0,.35);margin-bottom:4px;border:none;background:none;font-family:inherit}'+
'.pl-back.visible{display:flex}'+
'.pl-back:hover{color:rgba(0,0,0,.55)}'+
'.pl-back svg{width:14px;height:14px;flex-shrink:0}'+
'.pl-list-item{display:flex;align-items:center;padding:6px 8px;border-radius:6px;cursor:pointer;transition:all .15s;font-size:13px;font-weight:600;gap:8px;color:rgba(0,0,0,.5);margin-bottom:2px}'+
'.pl-list-item:hover{background:rgba(255,255,255,.25);color:rgba(0,0,0,.65)}'+
'.pl-list-item .pl-idx{color:rgba(0,0,0,.2);font-size:12px;font-weight:600;width:16px;text-align:right;flex-shrink:0}'+
'.pl-list-item .pl-cover{width:28px;height:28px;border-radius:4px;object-fit:cover;flex-shrink:0;background:rgba(0,0,0,.06)}'+
'.pl-list-item .pl-name{flex:1;min-width:0;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}'+
'.pl-list-item .pl-count{font-size:10px;color:rgba(0,0,0,.25);flex-shrink:0}'+
'[data-mp="root"].dark .pl-back{color:rgba(255,255,255,.3)}'+
'[data-mp="root"].dark .pl-back:hover{color:rgba(255,255,255,.5)}'+
'[data-mp="root"].dark .pl-list-item{color:rgba(255,255,255,.4)}'+
'[data-mp="root"].dark .pl-list-item:hover{background:rgba(255,255,255,.06);color:rgba(255,255,255,.55)}'+
'[data-mp="root"].dark .pl-list-item .pl-idx{color:rgba(255,255,255,.15)}'+
'[data-mp="root"].dark .pl-list-item .pl-cover{background:rgba(255,255,255,.04)}'+
'[data-mp="root"].dark .pl-list-item .pl-count{color:rgba(255,255,255,.18)}'+
'[data-mp="slistInner"]{transition:opacity .25s,transform .25s}'+
'[data-mp="slistInner"].fading{opacity:0;transform:translateY(6px)}'+
'.songlist{min-height:150px;margin-top:10px;max-height:150px;overflow-y:auto;scrollbar-width:none;border-top:1px solid rgba(0,0,0,.06);padding-top:8px;-ms-overflow-style:none}'+
'.songlist::-webkit-scrollbar{display:none}'+
'.songitem{display:flex;align-items:center;padding:6px 8px;border-radius:6px;cursor:pointer;transition:all .15s;font-size:14px;font-weight:600;gap:8px;color:#333}'+
'.songitem:hover{background:rgba(255,255,255,.3)}'+
'.songitem.active{background:rgba(255,255,255,.35);font-weight:700}'+
'.songitem .si-idx{color:#999;font-size:12px;font-weight:600;width:16px;text-align:right;flex-shrink:0}'+
'.si-cover{width:32px;height:32px;border-radius:4px;object-fit:cover;flex-shrink:0;margin-right:6px}'+
'.songitem .si-name{flex:1;min-width:0;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;color:#333}'+
'.songitem .si-artist{color:#999;font-size:12px;font-weight:600;max-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'+
'.songitem.active .si-name{color:#1a1a2e;font-weight:700}'+
'@media(min-width:769px){[data-mp="panel"]{width:360px}.songlist{min-height:180px;max-height:180px}}'+
'@media(max-width:768px){[data-mp="panel"]{width:280px;padding:14px;margin-bottom:30px}[data-mp="toggle"]{width:42px;height:42px}}'+
'[data-mp="immersiveOverlay"]{position:fixed;inset:0;z-index:2147483646;display:flex;opacity:0;visibility:hidden;transform:scale(0.05);background:rgba(245,245,250,.72);backdrop-filter:blur(40px)saturate(200%);flex-direction:column;align-items:center;justify-content:center;pointer-events:auto;font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;transition:opacity .35s cubic-bezier(.4,0,.2,1),transform .35s cubic-bezier(.4,0,.2,1),visibility .35s;border:1px solid rgba(255,255,255,.75)}'+
'[data-mp="immersiveOverlay"].open{opacity:1!important;visibility:visible!important;transform:scale(1)!important}'+
'.im-close-area{position:absolute;top:0;left:0;right:0;height:60px;display:flex;align-items:center;justify-content:flex-end;padding:0 20px;z-index:1}'+
'.im-close-btn{width:36px;height:36px;border-radius:8px;border:1px solid rgba(0,0,0,.08);background:rgba(255,255,255,.25);backdrop-filter:blur(8px)saturate(200%);cursor:pointer;display:flex;align-items:center;justify-content:center;color:#666;transition:all .2s;padding:0;box-shadow:0 0 10px rgba(255,255,255,.2)}'+
'.im-close-btn:hover{background:rgba(255,255,255,.35);color:#1a1a2e}'+
'.im-content{display:flex;flex-direction:column;align-items:center;gap:24px;padding:0 24px;max-width:400px;width:100%}'+
'.im-cover-wrap{width:280px;height:280px;border-radius:20px;overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,.12);flex-shrink:0}'+
'.im-cover{width:100%;height:100%;object-fit:cover}'+
'.im-info{text-align:center;width:100%}'+
'.im-title{font-size:24px;font-weight:700;color:#1a1a2e;letter-spacing:-.01em;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}'+
'.im-artist{font-size:16px;font-weight:600;color:#666;margin-top:4px}'+
'.im-progress-wrap{width:100%;display:flex;flex-direction:column;gap:4px}'+
'.im-progress-track{width:100%;height:3px;border-radius:2px;background:rgba(0,0,0,.1);cursor:pointer;position:relative}'+
'.im-progress-fill{height:100%;border-radius:2px;background:rgba(0,0,0,.25);transition:width .2s;width:0%}'+
'.im-time{display:flex;justify-content:space-between;font-size:12px;font-weight:600;color:#999}'+
'.im-controls{display:flex;align-items:center;gap:16px;margin-top:8px}'+
'.im-btn{width:48px;height:48px;border-radius:50%;border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.06);backdrop-filter:blur(8px)saturate(200%);cursor:pointer;display:flex;align-items:center;justify-content:center;color:#1a1a2e;transition:all .2s;padding:0;box-shadow:0 0 10px rgba(255,255,255,.2),0 0 20px rgba(255,255,255,.1)}'+
'.im-btn:hover{background:rgba(255,255,255,.22);border-color:rgba(255,255,255,.6);transform:scale(1.05)}'+
'.im-btn svg{width:22px;height:22px}'+
'.im-playbtn{width:64px;height:64px;border:1px solid rgba(255,255,255,.4);background:rgba(255,255,255,.08);backdrop-filter:blur(8px)saturate(200%);box-shadow:0 0 12px rgba(255,255,255,.25),0 0 24px rgba(255,255,255,.12);color:#1a1a2e}'+
'.im-playbtn:hover{background:rgba(255,255,255,.12)}'+
'.im-vol-wrap{position:relative;display:inline-flex}'+
'.im-vol-wrap.pc-only{display:inline-flex}'+
'.im-vol-wrap::before{content:\'\';position:absolute;bottom:100%;left:-10px;right:-10px;height:10px}'+
'.im-vol-slider{position:absolute;bottom:100%;left:50%;transform:translateX(-50%);padding:8px 4px;background:rgba(255,255,255,.85);backdrop-filter:blur(16px)saturate(200%);border:1px solid rgba(255,255,255,.7);border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.1);margin-bottom:0;opacity:0;visibility:hidden;transition:opacity .15s,visibility .15s;pointer-events:none}'+
'.im-vol-wrap:hover .im-vol-slider,.im-vol-slider:hover{opacity:1;visibility:visible;pointer-events:auto}'+
'.im-vol-slider input[type=range]{writing-mode:vertical-lr;direction:rtl;height:80px;width:5px;-webkit-appearance:none;appearance:none;background:rgba(0,0,0,.08);border-radius:3px;outline:none;cursor:pointer}'+
'.im-vol-slider input::-webkit-slider-thumb{-webkit-appearance:none;width:16px;height:16px;border-radius:50%;background:rgba(0,0,0,.35);cursor:pointer;border:2px solid rgba(255,255,255,.8);box-shadow:0 1px 4px rgba(0,0,0,.12);transition:transform .15s}'+
'.im-vol-slider input::-webkit-slider-thumb:hover{transform:scale(1.2)}'+
'.im-lrc{width:100%;height:150px;overflow:hidden;position:relative;text-align:center;margin:4px 0 2px;flex-shrink:0}'+
'.im-lrc-inner{transition:transform .4s cubic-bezier(.4,0,.2,1)}'+
'.im-lrc-line{padding:3px 0;font-size:14px;font-weight:600;line-height:1.6;color:rgba(0,0,0,.2);transition:color .25s,font-size .25s;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'+
'.im-lrc-line.active{color:#1a1a2e;font-size:16px;font-weight:700}'+
'.im-lrc-line.prev{color:rgba(0,0,0,.35)}'+
'.panel-action-btn{position:absolute;top:12px;right:12px;width:30px;height:30px;border-radius:8px;border:1px solid rgba(0,0,0,.08);background:rgba(255,255,255,.25);backdrop-filter:blur(8px)saturate(200%);cursor:pointer;display:flex;align-items:center;justify-content:center;color:#666;transition:all .2s;padding:0;z-index:1}'+
'.panel-action-btn:hover{background:rgba(255,255,255,.35);color:#1a1a2e}'+
'@media(max-width:768px){.im-cover-wrap{width:200px;height:200px}.im-title{font-size:20px}.im-artist{font-size:14px}.im-content{gap:18px}.im-lrc{height:120px}.im-lrc-line{font-size:13px}.im-lrc-line.active{font-size:15px}.im-btn{width:42px;height:42px}.im-btn svg{width:18px;height:18px}.im-playbtn{width:56px;height:56px}.pc-only{display:none!important}.im-slist-dropdown{position:fixed;top:auto;bottom:var(--mapi-inset-player,0px);left:0;right:0;margin:0;min-width:auto;max-height:50vh;width:100%;border-radius:16px 16px 0 0;z-index:2147483647;transform:translateY(100%);padding:8px 0 20px;border-bottom:none;box-shadow:0 -4px 24px rgba(0,0,0,.12)}.im-slist-dropdown.open{transform:translateY(0)}}'+
'@media(min-width:1025px){.im-content{max-width:620px;gap:28px}.im-cover-wrap{width:320px;height:320px;border-radius:24px}.im-title{font-size:28px}.im-artist{font-size:18px;margin-top:6px}.im-lrc{height:160px}.im-lrc-line{font-size:15px;padding:4px 0}.im-lrc-line.active{font-size:17px}.im-btn{width:52px;height:52px}.im-btn svg{width:24px;height:24px}.im-playbtn{width:72px;height:72px}.im-controls{gap:20px}.im-close-btn{width:42px;height:42px;border-radius:10px}.im-close-btn svg{width:22px;height:22px}}'+
'[data-mp="root"].dark [data-mp="toggle"]{background:rgba(65,65,78,.55);border-color:rgba(255,255,255,.08);color:#d0d0d8;box-shadow:0 2px 10px rgba(0,0,0,.18)}'+
'[data-mp="root"].dark [data-mp="toggle"]:hover{border-color:rgba(255,255,255,.2);background:rgba(80,80,95,.62)}'+
'[data-mp="root"].dark [data-mp="panel"]{background:rgba(55,55,68,.55);border-color:rgba(255,255,255,.06);box-shadow:0 8px 32px rgba(0,0,0,.2)}'+
'[data-mp="root"].dark .title{color:#d8d8e0}'+
'[data-mp="root"].dark .artist{color:#999}'+
'[data-mp="root"].dark .pbar{background:rgba(255,255,255,.08)}'+
'[data-mp="root"].dark .played{background:rgba(255,255,255,.28)}'+
'[data-mp="root"].dark .ptime{color:#aaa}'+
'[data-mp="root"].dark .cbtn{border-color:rgba(255,255,255,.06);background:rgba(255,255,255,.06);color:#c0c0c8;box-shadow:none}'+
'[data-mp="root"].dark .cbtn:hover{background:rgba(255,255,255,.12);transform:scale(1.05)}'+
'[data-mp="root"].dark .playbtn{border-color:rgba(255,255,255,.08);background:rgba(255,255,255,.08);box-shadow:none}'+
'[data-mp="root"].dark .mvol svg{color:#999}'+
'[data-mp="root"].dark .mvol input[type=range]{background:rgba(255,255,255,.06)}'+
'[data-mp="root"].dark .mvol input::-webkit-slider-thumb{background:rgba(255,255,255,.45);border:2px solid rgba(255,255,255,.2);box-shadow:0 1px 4px rgba(0,0,0,.25)}'+
'[data-mp="root"].dark .mvol input::-webkit-slider-thumb:hover{transform:scale(1.2)}'+
'[data-mp="root"].dark .mvol .b-btn{border-color:rgba(255,255,255,.08);color:#888;padding:3px 0}'+
'[data-mp="root"].dark .mvol .b-btn:hover{border-color:rgba(255,255,255,.2);color:#ddd}'+
'[data-mp="root"].dark .mvol .b-btn.on{background:rgba(255,200,50,.15);border-color:rgba(255,180,0,.3);color:#e8a800}'+
'[data-mp="root"].dark .mode-trigger{color:#c0c0c8;border-color:rgba(255,255,255,.06);background:rgba(255,255,255,.08)}'+
'[data-mp="root"].dark .mode-trigger::after{color:#777}'+
'[data-mp="root"].dark .mode-menu{background:rgba(55,55,68,.75);border-color:rgba(255,255,255,.06)}'+
'[data-mp="root"].dark .mode-option{color:#aaa}'+
'[data-mp="root"].dark .mode-option:hover{background:rgba(255,255,255,.06)}'+
'[data-mp="root"].dark .mode-option.active{background:rgba(255,255,255,.1);color:#d8d8e0}'+
'[data-mp="root"].dark .lrc-label{color:#888}'+
'[data-mp="root"].dark .lrc-toggle{border-color:rgba(255,255,255,.06);background:rgba(255,255,255,.08)}'+
'[data-mp="root"].dark .lrc-toggle.active{background:rgba(255,255,255,.2);border-color:rgba(255,255,255,.1)}'+
'[data-mp="root"].dark .lrc-toggle::after{background:rgba(255,255,255,.5)}'+
'[data-mp="root"].dark .songlist{border-top-color:rgba(255,255,255,.04)}'+
'[data-mp="root"].dark .songitem{color:#999}'+
'[data-mp="root"].dark .songitem:hover{background:rgba(255,255,255,.06)}'+
'[data-mp="root"].dark .songitem.active{background:rgba(255,255,255,.1)}'+
'[data-mp="root"].dark .songitem .si-name{color:#bbb}'+
'[data-mp="root"].dark .songitem.active .si-name{color:#d8d8e0}'+
'[data-mp="root"].dark .songitem .si-artist{color:#aaa}'+
'[data-mp="root"].dark .songitem .si-idx{color:#888}'+
'[data-mp="root"].dark .panel-action-btn{border-color:rgba(255,255,255,.06);background:rgba(255,255,255,.08);color:#888}'+
'[data-mp="root"].dark .panel-action-btn:hover{background:rgba(255,255,255,.12);color:#c0c0c8}'+
'[data-mp="root"].dark .im-mode-btn{color:#d0d0d8}'+
'[data-mp="root"].dark .im-mode-btn:hover{color:#fff}'+
'[data-mp="root"].dark [data-mp="immersiveOverlay"]{background:rgba(45,45,55,.75);border-color:rgba(255,255,255,.04)}'+
'[data-mp="root"].dark .im-close-btn{border-color:rgba(255,255,255,.06);background:rgba(255,255,255,.08);color:#888;box-shadow:none}'+
'[data-mp="root"].dark .im-close-btn:hover{background:rgba(255,255,255,.12);color:#c0c0c8}'+
'[data-mp="root"].dark .im-cover-wrap{box-shadow:0 12px 36px rgba(0,0,0,.25)}'+
'[data-mp="root"].dark .im-title{color:#d8d8e0}'+
'[data-mp="root"].dark .im-artist{color:#999}'+
'[data-mp="root"].dark .im-progress-track{background:rgba(255,255,255,.08)}'+
'[data-mp="root"].dark .im-progress-fill{background:rgba(255,255,255,.28)}'+
'[data-mp="root"].dark .im-time{color:#aaa}'+
'[data-mp="root"].dark .im-btn{border-color:rgba(255,255,255,.06);background:rgba(255,255,255,.06);color:#c0c0c8;box-shadow:none}'+
'[data-mp="root"].dark .im-btn:hover{background:rgba(255,255,255,.12);transform:scale(1.05)}'+
'[data-mp="root"].dark .im-playbtn{border-color:rgba(255,255,255,.08);background:rgba(255,255,255,.08);color:#d0d0d8;box-shadow:none}'+
'[data-mp="root"].dark .im-playbtn:hover{background:rgba(255,255,255,.12)}'+
'[data-mp="root"].dark .im-mode-btn{color:#d0d0d8}'+
'[data-mp="root"].dark .im-mode-btn:hover{color:#fff}'+
'[data-mp="root"].dark .im-bottom-icon-btn{color:#d0d0d8}'+
'[data-mp="root"].dark .im-bottom-icon-btn:hover{color:#fff}'+
'[data-mp="root"].dark .im-vol-slider{background:rgba(55,55,68,.75);border-color:rgba(255,255,255,.06)}'+
'[data-mp="root"].dark .im-vol-slider input[type=range]{background:rgba(255,255,255,.06)}'+
'[data-mp="root"].dark .im-vol-slider input::-webkit-slider-thumb{background:rgba(255,255,255,.45);border:2px solid rgba(255,255,255,.2);box-shadow:0 1px 4px rgba(0,0,0,.25)}'+
'[data-mp="root"].dark .im-vol-slider input::-webkit-slider-thumb:hover{transform:scale(1.2)}'+
'[data-mp="root"].dark .im-slist-dropdown{background:rgba(55,55,68,.75);border-color:rgba(255,255,255,.06)}'+
'[data-mp="root"].dark .im-slist-dropdown .songitem{color:#aaa}'+
'[data-mp="root"].dark .im-slist-dropdown .songitem:hover{background:rgba(255,255,255,.06)}'+
'[data-mp="root"].dark .im-slist-dropdown .songitem.active{background:rgba(255,255,255,.1);color:#d8d8e0}'+
'[data-mp="root"].dark .im-lrc-line{color:rgba(255,255,255,.12)}'+
'[data-mp="root"].dark .im-lrc-line.active{color:#d0d0d8}'+
'[data-mp="root"].dark .im-lrc-line.prev{color:rgba(255,255,255,.22)}'+
'[data-mp="root"].dark [data-mp="lrc"]{color:#d0d0d8!important;background:rgba(55,55,68,.65)!important;border-color:rgba(255,255,255,.06)!important}';

// 容器立体化：面板 / 沉浸式玻璃层 / 封面托板 / 下拉面板
var B3 = '[data-mp="root"][data-mp="root"] ';
function b3(list, extra) {
    return list.split(',').map(function(s){ return B3 + s.trim() + (extra || ''); }).join(',');
}
MP._css +=
'[data-mp="root"]{--s3-top:rgba(255,255,255,.50);--s3-bot:rgba(255,255,255,.30);--s3-bd:rgba(255,255,255,.88);--s3-hi:rgba(255,255,255,.95);--s3-in:rgba(0,0,0,.04);--s3-lo:rgba(0,0,0,.12);--s3-lo2:rgba(0,0,0,.16);--s3-lo3:rgba(0,0,0,.10);--s3-vig:rgba(0,0,0,.07);--s3-sheen:rgba(255,255,255,.16);--s3-shade:rgba(0,0,0,.04)}'+
'[data-mp="root"].dark{--s3-top:rgba(255,255,255,.10);--s3-bot:rgba(255,255,255,.03);--s3-bd:rgba(255,255,255,.08);--s3-hi:rgba(255,255,255,.10);--s3-in:rgba(0,0,0,.20);--s3-lo:rgba(0,0,0,.35);--s3-lo2:rgba(0,0,0,.40);--s3-lo3:rgba(0,0,0,.28);--s3-vig:rgba(0,0,0,.24);--s3-sheen:rgba(255,255,255,.05);--s3-shade:rgba(0,0,0,.16)}'+
// ── 容器本体立体化：面板 / 沉浸式玻璃层 / 封面托板 / 下拉面板
b3('[data-mp="panel"]')+'{background-image:linear-gradient(180deg,var(--s3-top),var(--s3-bot));border-color:var(--s3-bd);box-shadow:inset 0 1px 0 var(--s3-hi),inset 0 -1px 0 var(--s3-in),0 3px 8px var(--s3-lo),0 16px 36px var(--s3-lo2),0 34px 70px var(--s3-lo3)}'+
// 沉浸式：整屏玻璃层 → 顶部一道光 + 四边轻微暗角，读起来像"浮在页面上的一整块玻璃"
b3('[data-mp="immersiveOverlay"]')+'{background-image:linear-gradient(180deg,var(--s3-sheen),rgba(255,255,255,0) 45%,var(--s3-shade));border-color:var(--s3-bd);box-shadow:inset 0 1px 0 var(--s3-hi),inset 0 0 130px var(--s3-vig)}'+
// 封面托板：两层投影 + 顶部玻璃反光
b3('.im-cover-wrap')+'{box-shadow:0 10px 22px var(--s3-lo2),0 30px 70px var(--s3-lo3),inset 0 1px 0 rgba(255,255,255,.35)}'+
// 下拉面板（播放模式菜单）：浮层投影
b3('.mode-menu')+'{background-image:linear-gradient(180deg,var(--s3-top),var(--s3-bot));border-color:var(--s3-bd);box-shadow:inset 0 1px 0 var(--s3-hi),0 4px 10px var(--s3-lo),0 18px 40px var(--s3-lo2)}'+
// 沉浸式歌单下拉：只加渐变/描边，不动它原有的外投影（移动端是底部抽屉，投影方向朝上）
b3('.im-slist-dropdown')+'{background-image:linear-gradient(180deg,var(--s3-top),var(--s3-bot));border-color:var(--s3-bd)}';

// 浅色模式按钮配色
var LB = '[data-mp="root"]:not(.dark) ';
MP._css +=
// 静止态
LB+'.cbtn,'+LB+'.im-btn{background:rgba(96,102,128,.30);border-color:rgba(0,0,0,.14);box-shadow:0 1px 3px rgba(0,0,0,.06);-webkit-backdrop-filter:none;backdrop-filter:none}'+
LB+'.playbtn,'+LB+'.im-playbtn{background:rgba(96,102,128,.38);border-color:rgba(0,0,0,.16);box-shadow:0 2px 6px rgba(0,0,0,.09);-webkit-backdrop-filter:none;backdrop-filter:none}'+
LB+'.panel-action-btn,'+LB+'.mode-trigger{background:rgba(96,102,128,.32);border-color:rgba(0,0,0,.14);box-shadow:0 1px 3px rgba(0,0,0,.06)}'+
LB+'.mvol .b-btn{background:rgba(96,102,128,.30);border-color:rgba(0,0,0,.14);color:#4a4a55}'+
LB+'.im-close-btn{background:rgba(96,102,128,.30);border-color:rgba(0,0,0,.14)}'+
LB+'[data-mp="toggle"]{background:rgba(96,102,128,.26);border-color:rgba(0,0,0,.14);box-shadow:0 2px 10px rgba(0,0,0,.10)}'+
// 悬停态：比静止再深一档（保留原有的缩放反馈）
LB+'.cbtn:hover,'+LB+'.im-btn:hover{background:rgba(96,102,128,.42);border-color:rgba(0,0,0,.18);transform:scale(1.05)}'+
LB+'.playbtn:hover,'+LB+'.im-playbtn:hover{background:rgba(96,102,128,.50)}'+
LB+'.panel-action-btn:hover,'+LB+'.mode-trigger:hover{background:rgba(96,102,128,.44);border-color:rgba(0,0,0,.18)}'+
LB+'.mvol .b-btn:hover{background:rgba(96,102,128,.40);border-color:rgba(0,0,0,.20);color:#1a1a2e}'+
LB+'.im-close-btn:hover{background:rgba(96,102,128,.42)}'+
LB+'[data-mp="toggle"]:hover{background:rgba(96,102,128,.36);border-color:rgba(0,0,0,.20)}'+
// "增强"已开启的金色状态
LB+'.mvol .b-btn.on{background:rgba(255,200,50,.22);border-color:rgba(255,180,0,.45);color:#c89600}';

// PC 沉浸式尺寸自适应
MP._css +=
'@media(min-width:769px){'+
// 整屏层滚动与居中
  '[data-mp="immersiveOverlay"]{padding:52px 0 14px}'+
  '.im-content{min-height:calc(100vh - 74px);justify-content:center;max-width:min(620px,86vw);gap:clamp(12px,2.2vh,26px)}'+
  '.im-cover-wrap{width:clamp(180px,28vh,320px);height:clamp(180px,28vh,320px);border-radius:clamp(16px,2.4vh,24px)}'+
  '.im-title{font-size:clamp(19px,3.2vh,28px)}'+
  '.im-artist{font-size:clamp(14px,1.9vh,18px);margin-top:clamp(2px,.6vh,6px)}'+
  '.im-lrc{height:clamp(96px,18vh,160px)}'+
  '.im-lrc-line{font-size:clamp(13px,1.9vh,15px);padding:clamp(2px,.5vh,4px) 0}'+
  '.im-lrc-line.active{font-size:clamp(15px,2.2vh,17px)}'+
  '.im-btn{width:clamp(42px,6.4vh,52px);height:clamp(42px,6.4vh,52px)}'+
  '.im-btn svg{width:clamp(18px,2.8vh,24px);height:clamp(18px,2.8vh,24px)}'+
  '.im-playbtn{width:clamp(56px,9.5vh,72px);height:clamp(56px,9.5vh,72px)}'+
  '.im-close-btn{width:clamp(36px,5vh,42px);height:clamp(36px,5vh,42px);border-radius:clamp(8px,1.4vh,10px)}'+
  '.im-close-btn svg{width:clamp(16px,2.4vh,22px);height:clamp(16px,2.4vh,22px)}'+
  '.im-controls{gap:clamp(12px,2.4vh,20px);margin-top:clamp(0px,.8vh,8px)}'+
  '.im-progress-wrap{gap:clamp(2px,.6vh,4px)}'+
  '.im-vol-slider input[type=range]{height:clamp(60px,12vh,80px)}'+
'}'+
// 极矮窗口（分屏 / 小笔记本）：再压一档，保证播放控件始终在视口内
'@media(min-width:769px) and (max-height:620px){'+
  '[data-mp="immersiveOverlay"]{padding:44px 0 12px}'+
  '.im-content{min-height:calc(100vh - 60px);gap:clamp(10px,2vh,18px);padding:0 20px}'+
  '.im-cover-wrap{width:clamp(140px,26vh,200px);height:clamp(140px,26vh,200px)}'+
  '.im-title{font-size:clamp(17px,3vh,22px)}'+
  '.im-lrc{height:clamp(64px,15vh,110px);margin:0}'+
  '.im-progress-wrap{margin-top:0}'+
'}'+
// 更极端（<480px 高）：封面再小、隐藏歌手行，优先保控件
'@media(min-width:769px) and (max-height:480px){'+
  '.im-cover-wrap{width:clamp(110px,24vh,150px);height:clamp(110px,24vh,150px)}'+
  '.im-lrc{height:clamp(48px,13vh,80px)}'+
  '.im-artist{display:none}'+
'}';

    MP.loadCSS = function(cb) { cb(); };

    MP.createWidget = function() {
        var host = document.createElement('div');
        host.id = 'mapi-player-' + Math.random().toString(36).slice(2, 8);
        var mr = window.innerWidth <= 768 ? '4px' : '15px';
        var mb = window.innerWidth <= 768 ? '35px' : '50px';
        var ps = 'right:' + mr;
        host.style.cssText = 'all:initial;position:fixed;bottom:' + mb + ';' + ps + ';z-index:2147483647;pointer-events:none';
        document.body.appendChild(host);
        host.addEventListener('contextmenu',function(e){e.preventDefault();});
        var shadow = host.attachShadow({mode:'closed'});
        shadow.innerHTML = '<style>' + (MP._css || '') + '</style><div data-mp="root">' + MP.getHTML() + '</div>';
        return shadow;
    };

    MP.getHTML = function() { return '<div data-mp="overlay" style="position:fixed;inset:0;z-index:-1;display:none;pointer-events:auto"><\/div><div data-mp="panel">'
  + '<div class="info"><div class="cover" data-mp="cv"><img src="" alt=""><\/div><div class="mtext"><div class="title mw" data-mp="ttl"><span class="mi">\u70b9\u51fb\u64ad\u653e<\/span><\/div><div class="artist mw" data-mp="art"><span class="mi">\u52a0\u8f7d\u4e2d...<\/span><\/div><\/div><\/div>'
  + '<button class="panel-action-btn" data-mp="immersiveBtn" title="\u6c89\u6d78\u5f0f\u64ad\u653e"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3"\/><path d="M21 8V5a2 2 0 0 0-2-2h-3"\/><path d="M16 21h3a2 2 0 0 0 2-2v-3"\/><path d="M3 16v3a2 2 0 0 0 2 2h3"\/><\/svg><\/button>'
  + '<div class="progress"><div class="pbar" data-mp="pbar"><div class="played" data-mp="played"><\/div><\/div><div class="ptime"><span data-mp="cur">00:00<\/span><span data-mp="dur">00:00<\/span><\/div><\/div>'
  + '<div class="controls"><button class="cbtn" data-mp="prevBtn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="19 20 9 12 19 4 19 20"\/><line x1="5" y1="19" x2="5" y2="5"\/><\/svg><\/button>'
  + '<button class="cbtn playbtn" data-mp="playBtn"><svg data-mp="playSvg" viewBox="0 0 24 24" fill="currentColor"><polygon points="6,4 20,12 6,20"\/><\/svg><\/button>'
  + '<button class="cbtn" data-mp="nextBtn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 4 15 12 5 20 5 4"\/><line x1="19" y1="5" x2="19" y2="19"\/><\/svg><\/button><\/div>'
  + '<div class="bottom-row" data-mp="bottomRow"><div class="mode-dropdown" data-mp="modeDropdown"><button class="mode-trigger" data-mp="modeTrigger">\u5217\u8868\u64ad\u653e<\/button><div class="mode-menu" data-mp="modeMenu"><div class="mode-option active" data-mode="list">\u5217\u8868\u64ad\u653e<\/div><div class="mode-option" data-mode="single">\u5355\u66f2\u5faa\u73af<\/div><div class="mode-option" data-mode="random">\u968f\u673a\u64ad\u653e<\/div><\/div><\/div>'
  + '<div class="lrc-area"><span class="lrc-label">\u6b4c\u8bcd<\/span><button class="lrc-toggle active" data-mp="lrcToggle"><\/button><\/div><\/div>'
  + '<div class="mvol"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"\/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"\/><\/svg>'
  + '<input type="range" data-mp="vol" min="0" max="1" step="0.05" value="1"><button class="b-btn" data-mp="boost">\u589e\u5f3a<\/button><\/div>'
  + '<div class="songlist" data-mp="slist"><button class="pl-back" data-mp="plBack"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"\/><\/svg>\u8fd4\u56de\u6b4c\u5355\u5217\u8868<\/button><div data-mp="slistInner"><\/div><\/div><\/div>'
  + '<button data-mp="toggle" draggable="false"><svg data-mp="toggleSvg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"\/><circle cx="6" cy="18" r="3"\/><circle cx="18" cy="16" r="3"\/><\/svg><img data-mp="toggleCover" draggable="false" src="" alt="" style="display:none;position:absolute;top:3px;left:3px;width:calc(100% - 6px);height:calc(100% - 6px);border-radius:50%;object-fit:cover;box-sizing:border-box"><span data-mp="toggleLoading" style="position:absolute;inset:2px;border-radius:50%;border:2px solid rgba(255,255,255,.3);border-top-color:rgba(0,0,0,.5);opacity:0;transition:opacity .3s"><\/span><\/button>'
  + '<div data-mp="apContainer" style="display:none"><\/div>'
  + '<div data-mp="immersiveOverlay"><div class="im-close-area"><button class="im-close-btn" data-mp="imClose"><svg viewBox="0 0 1024 1024" width="18" height="18" fill="currentColor"><path d="M384 128h-85.33v170.67H128V384h256zM896 384v-85.33H725.33V128H640v256zM725.33 725.33H896V640H640v256h85.33zM298.67 896H384V640H128v85.33h170.67z"\/><\/svg><\/button><\/div>'
  + '<div class="im-content" data-mp="imContent"><div class="im-cover-wrap"><img class="im-cover" data-mp="imCover" src="" alt=""><\/div>'
  + '<div class="im-info"><div class="im-title" data-mp="imTitle">\u70b9\u51fb\u64ad\u653e<\/div><div class="im-artist" data-mp="imArtist">\u52a0\u8f7d\u4e2d...<\/div><\/div>'
  + '<div class="im-lrc"><div class="im-lrc-inner" data-mp="imLrc"><\/div><\/div><div class="im-bottom-row"><div style="display:flex;align-items:center;gap:4px">'
  + '<div style="position:relative"><button class="im-bottom-icon-btn" data-mp="imSlistTrigger"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"\/><line x1="8" y1="12" x2="21" y2="12"\/><line x1="8" y1="18" x2="21" y2="18"\/><line x1="3" y1="6" x2="3.01" y2="6"\/><line x1="3" y1="12" x2="3.01" y2="12"\/><line x1="3" y1="18" x2="3.01" y2="18"\/><\/svg><\/button><div class="im-slist-dropdown" data-mp="imSlist"><button class="pl-back" data-mp="imPlBack" style="margin:0 8px"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"\/><\/svg>\u8fd4\u56de\u6b4c\u5355\u5217\u8868<\/button><div data-mp="imSlistInner"><\/div><\/div><\/div>'
  + '<div class="im-vol-wrap pc-only"><button class="im-bottom-icon-btn"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"\/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"\/><\/svg><\/button><div class="im-vol-slider"><input type="range" data-mp="imVol" min="0" max="1" step="0.05" value="1"><\/div><\/div><\/div>'
  + '<button class="im-mode-btn" data-mp="imModeBtn"><svg data-mp="imModeSvg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"\/><path d="M3 11V9a4 4 0 0 1 4-4h14"\/><polyline points="7 23 3 19 7 15"\/><path d="M21 13v2a4 4 0 0 1-4 4H3"\/><\/svg><\/button><\/div>'
  + '<div class="im-progress-wrap" data-mp="imPbar"><div class="im-progress-track"><div class="im-progress-fill" data-mp="imPfill"><\/div><\/div><div class="im-time"><span data-mp="imCur">00:00<\/span><span data-mp="imDur">00:00<\/span><\/div><\/div>'
  + '<div class="im-controls"><button class="im-btn" data-mp="imPrev"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2"><polygon points="19 20 9 12 19 4 19 20"\/><line x1="5" y1="19" x2="5" y2="5"\/><\/svg><\/button>'
  + '<button class="im-btn im-playbtn" data-mp="imPlay"><svg data-mp="imPlaySvg" viewBox="0 0 24 24" width="34" height="34" fill="currentColor"><polygon points="6,4 20,12 6,20"\/><\/svg><\/button>'
  + '<button class="im-btn" data-mp="imNext"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 4 15 12 5 20 5 4"\/><line x1="19" y1="5" x2="19" y2="19"\/><\/svg><\/button><\/div><\/div>'; };

})(window.__mapiPlayer);