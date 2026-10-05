var _toast=document.getElementById('toast'),_tt=null;
function showToast(m,t){_toast.textContent=m;_toast.className='show '+(t||'ok');clearTimeout(_tt);_tt=setTimeout(function(){_toast.className=''},2500)}
// 确认框：确认后用 requestSubmit 重新触发 submit 事件，交给下面的 AJAX 提交拦截。
// （直接 f.submit() 不触发 submit 事件 → 浏览器整页 POST → 页内测试播放器会被刷掉）
function showConfirm(e,f,m){
  if(f&&f.dataset&&f.dataset.confirmed==='1'){delete f.dataset.confirmed;return true}   // 第二次进来放行
  e.preventDefault();
  if(!confirm(m))return false;
  if(f&&f.dataset)f.dataset.confirmed='1';
  if(f.requestSubmit)f.requestSubmit();else f.submit();
  return false;
}

var _navigating=false,_navAbort=null,_navTimer=null;
(function(){
history.scrollRestoration='manual';
var navActions=['dashboard','keys','users','config','domains','settings','settings-site','settings-mail','settings-security','settings-api','settings-storage','profile','playlist-detail'];

// 子菜单 → 父菜单映射：子项激活时父菜单高亮、底栏水珠停在父项上
var navParents={'settings-site':'settings','settings-mail':'settings','settings-security':'settings','settings-api':'settings','settings-storage':'settings'};
function navKeyOf(action){return navParents[action]||action}

function setGroupOpen(open,save){
  var grp=document.querySelector('.sidebar .sb-group');
  if(!grp)return;
  grp.classList.toggle('open',!!open);
  var btn=grp.querySelector('.sb-parent');
  if(btn)btn.setAttribute('aria-expanded',open?'true':'false');
  if(save){try{localStorage.setItem('sb_group_open',open?'1':'0')}catch(e){}}
}

function setActiveNav(action){
  var key=navKeyOf(action);
  document.querySelectorAll('.sidebar .sb-item').forEach(function(el){
    var href=el.getAttribute('href');
    el.classList.toggle('active',!!href&&href==='?action='+key);
  });
  var grp=document.querySelector('.sidebar .sb-group');
  if(!grp)return;
  var child=grp.querySelector('.sb-sub-item[href="?action='+action+'"]');
  grp.querySelectorAll('.sb-sub-item').forEach(function(el){
    el.classList.toggle('active',el.getAttribute('href')==='?action='+action);
  });
  if(child)setGroupOpen(true);
  grp.classList.toggle('has-active',!!child);
}

// 分组默认收起（记住上次选择）；当前页在分组内则自动展开；折叠态下点父菜单先展开侧栏
function initSidebarGroup(){
  var grp=document.querySelector('.sidebar .sb-group');
  if(!grp)return;
  var stored=null;try{stored=localStorage.getItem('sb_group_open')}catch(e){}
  setGroupOpen(!!grp.querySelector('.sb-sub-item.active')||stored==='1',false);
  var btn=grp.querySelector('.sb-parent');
  if(!btn)return;
  btn.addEventListener('click',function(e){
    e.preventDefault();e.stopPropagation();
    var sbEl=document.getElementById('sidebar');
    if(sbEl&&sbEl.classList.contains('collapsed')){
      sbEl.classList.remove('collapsed');
      try{localStorage.setItem('sidebar_collapsed','0')}catch(err){}
      var brand=document.getElementById('sbBrand');
      if(brand){brand.setAttribute('title','折叠侧边栏');brand.setAttribute('aria-label','折叠侧边栏')}
      setGroupOpen(true,true);return;
    }
    setGroupOpen(!grp.classList.contains('open'),true);
  });
}
initSidebarGroup();

// 页面样式原子切换: 新样式就绪后才替换旧样式, 避免"CSS 丢失 / 无样式"的中间态.
function swapPageCss(href,proceed){
  var old=document.getElementById('page-css');
  var proceeded=false,settled=false;
  function goOn(){if(proceeded)return;proceeded=true;proceed()}
  if(!href){if(old&&old.parentNode)old.parentNode.removeChild(old);goOn();return}
  if(old&&old.getAttribute('href')===href){goOn();return}
  var next=document.createElement('link');
  next.rel='stylesheet';next.setAttribute('href',href);next.media='not all';
  function apply(ok){
    if(settled)return;settled=true;
    if(ok){
      next.media='all';
      if(old&&old.parentNode)old.parentNode.removeChild(old);
      next.id='page-css';
    }else if(next.parentNode){next.parentNode.removeChild(next)}
  }
  next.onload=function(){apply(true);goOn()};
  next.onerror=function(){apply(false);goOn()};
  // 插在旧样式原位，保持层叠顺序不变
  if(old&&old.parentNode){old.parentNode.insertBefore(next,old.nextSibling)}else{document.head.appendChild(next)}
  setTimeout(goOn,1500);
  setTimeout(function(){apply(false)},8000);
}

function loadPageScripts(src,cb){
  var cur=document.getElementById('page-js');
  if(!src){if(cur)cur.remove();cb();return}
  if(cur&&cur.getAttribute('src')===src&&cur.getAttribute('data-loaded')==='1'){cb();return}
  if(cur)cur.remove();
  var s=document.createElement('script');
  s.id='page-js';s.src=src;
  s.onload=function(){s.setAttribute('data-loaded','1');cb()};
  s.onerror=cb;document.body.appendChild(s);
}

  // 皮肤卡片：事件委托挂在 document 上。
  // 后台换页 / 保存都会替换页面内容，内联脚本与逐元素绑定都会随之失效（这个 bug 已复发多次），
  // 而 document 不会被替换 —— 委托一次，永久有效。位置控件区 .rs-pos-zone 不参与选中。
  if(!window.__skinCardDelegated){
    window.__skinCardDelegated=1;
    document.addEventListener('click',function(e){
      var t=e.target;
      if(!t||!t.closest)return;
      if(t.closest('.rs-pos-zone'))return;
      var card=t.closest('[data-skin-card]');
      if(!card)return;
      var id=card.getAttribute('data-skin');
      var radio=document.getElementById(id);
      if(radio)radio.checked=true;
      var cards=document.querySelectorAll('[data-skin-card]');
      for(var i=0;i<cards.length;i++){
        var on=(cards[i].getAttribute('data-skin')===id);
        cards[i].style.borderColor=on?'rgba(108,92,231,.85)':'rgba(128,128,128,.35)';
        cards[i].style.background=on?'rgba(108,92,231,.10)':'transparent';
      }
    });
  }function runPageScripts(scope){
  scope.querySelectorAll('script').forEach(function(s){
    if(!s.textContent.trim())return;
    var m=s.textContent.match(/showToast\('((?:[^'\\]|\\.)*)','((?:[^'\\]|\\.)*)'/);
    if(m){showToast(m[1],m[2]);return}
    try{
      // 用真实 <script> 元素执行。不能用 new Function(code)()：
      // 那是把代码当函数体跑，页面里的 function xxx(){} 只会成为那个包装函数的局部函数，
      // 不会挂到 window —— 于是 onclick="xxx()" 报未定义、typeof xxx==='function' 恒为 false。
      // （症状：无刷新切到该页面时按钮点不动，刷新后才正常。）
      var el=document.createElement('script');
      el.textContent=s.textContent;
      (document.head||document.body).appendChild(el);
      if(el.parentNode)el.parentNode.removeChild(el);
    }catch(e){console.warn('[navigateTo] 页面内联脚本执行失败:',e)}
  });
  try{if(typeof updateTestPlayerButtons==='function')updateTestPlayerButtons()}catch(e){}
  try{if(typeof initDebugExpand==='function')initDebugExpand()}catch(e){}
  try{initCodeMirror()}catch(e){}
}

function navPending(on){document.body.style.cursor=on?'progress':''}

// 播放器后台预加载的暂停/恢复：切页期间先取消在途的预加载请求（单线程服务器下能显著降低切页等待）
function pausePlayerPreload(){
  var MP=window.__mapiPlayer;
  if(MP&&typeof MP.pausePreload==='function'){try{MP.pausePreload();return}catch(e){}}
  window.__mapiPrefetchPaused=true;
}
function resumePlayerPreload(){
  var MP=window.__mapiPlayer;
  if(MP&&typeof MP.resumePreload==='function'){try{MP.resumePreload();return}catch(e){}}
  window.__mapiPrefetchPaused=false;
}

function finishNav(ctrl){
  if(_navAbort!==ctrl)return;          // 已被更新的导航接管，状态由新导航收尾
  _navAbort=null;_navigating=false;
  clearTimeout(_navTimer);_navTimer=null;
  navPending(false);
  resumePlayerPreload();               // 切页结束，恢复后台预加载（带 0.8s 安静期）
}

function applyPage(wrap,html,doc,url,push,keepScroll){
  var scr=keepScroll?wrap.scrollTop:0;
  // 切页前清掉上一页挪到 body 下的弹窗：否则重复 ID 越积越多（编辑弹窗填不进内容、旧浮层还会挡住点击）
  document.querySelectorAll('body > .song-modal').forEach(function(el){el.remove()});
  // 页面内容每次都是全新的 DOM，页面脚本必须重新执行，否则新 DOM 上没有任何监听（点“编辑/添加”会没反应）
  var pj=document.getElementById('page-js');if(pj)pj.remove();
  wrap.innerHTML=html;
  wrap.classList.remove('nav-out');
  wrap.classList.remove('nav-in');
  void wrap.offsetHeight;
  wrap.classList.add('nav-in');
  setTimeout(function(){wrap.classList.remove('nav-in')},420);
  document.title=doc.title;
  var a=new URL(url,location.origin).searchParams.get('action');
  if(a&&navActions.indexOf(a)!==-1)setActiveNav(a);
  if(push!==false&&url!==location.href)history.pushState(null,'',url);
  if(keepScroll){wrap.scrollTop=scr}else{wrap.scrollTo(0,0)}
  // 内容刚换上来时先刷一次播放器按钮: 此时页面 JS 可能还没加载完,
  try{if(typeof updateTestPlayerButtons==='function')updateTestPlayerButtons()}catch(e){}
}

function navigateTo(url,push){
  var wrap=document.querySelector('.wrap');
  if(!wrap){window.location.href=url;return}
  // 取消上一次未完成的切换，而不是把界面锁死（原来 _navigating 一旦置位就会吞掉所有点击）
  if(_navAbort){try{_navAbort.abort()}catch(e){}}
  var ctrl=('AbortController' in window)?new AbortController():null;
  _navAbort=ctrl;_navigating=true;navPending(true);
  pausePlayerPreload();                  // 取消/暂停播放器后台预加载，切页请求不用排队等它
  clearTimeout(_navTimer);
  _navTimer=setTimeout(function(){if(ctrl){try{ctrl.abort()}catch(e){}}},15000);
  // 不在请求期间隐藏页面：切换等待期间用户仍能看到、滚动当前页面
  fetch(url,{signal:ctrl?ctrl.signal:undefined,credentials:'same-origin'})
    .then(function(r){if(!r.ok)throw Error('http '+r.status);return r.text()})
    .then(function(html){
      if(_navAbort!==ctrl)return;
      var doc=new DOMParser().parseFromString(html,'text/html');
      var nw=doc.querySelector('.wrap');
      if(!nw){console.error('[navigateTo] .wrap not found, staying on current page.');finishNav(ctrl);return}
      var nc=doc.querySelector('#page-css');
      var nj=doc.getElementById('page-js');
      // 内容立刻切换（不再等新样式下载完）：样式在后台原子替换，新样式就绪前旧样式继续生效，不会丢样式
      applyPage(wrap,nw.innerHTML,doc,url,push,false);
      swapPageCss(nc?nc.getAttribute('href'):null,function(){});
      loadPageScripts(nj?nj.getAttribute('src'):null,function(){
        if(_navAbort!==ctrl)return;
        runPageScripts(wrap);
        finishNav(ctrl);
      });
    })
    .catch(function(e){
      if(_navAbort!==ctrl)return;
      console.warn('[navigateTo] 切换失败，保留当前页面:',e);
      finishNav(ctrl);
    });
}
window.navigateTo=navigateTo;

var sb=document.getElementById('sidebar'),sbb=document.getElementById('sbBrand');
function applyCollapse(c){sb.classList.toggle('collapsed',c);if(sbb){var t=c?'展开侧边栏':'折叠侧边栏';sbb.setAttribute('title',t);sbb.setAttribute('aria-label',t)}}
if(sb&&sbb){var isCollapsed=localStorage.getItem('sidebar_collapsed')==='1';applyCollapse(isCollapsed);sbb.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();var c=!sb.classList.contains('collapsed');applyCollapse(c);localStorage.setItem('sidebar_collapsed',c?'1':'0')})}

document.addEventListener('click',function(e){
  var link=e.target.closest('a[href^="?action="]');
  if(!link)return;
  var a=new URL(link.href,location.origin).searchParams.get('action');
  if(a==='logout'||navActions.indexOf(a)===-1)return;
  if(link.classList.contains('active')){e.preventDefault();return;}
  e.preventDefault();
  navigateTo(link.href);
});

document.addEventListener('submit',function(e){
  if(e.defaultPrevented)return;
  var f=e.target;
  if(f.method!=='post')return;
  if(f.hasAttribute('data-modal'))return;
  var act=(f.getAttribute('action')||'').trim()||location.href;
  if(act.indexOf('?action=')===-1)return;
  e.preventDefault();
  var btn=f.querySelector('[type="submit"],button:not([type]),button[type="submit"]');
  var origTxt=btn?btn.textContent:'';
  if(btn){btn.disabled=true;btn.textContent='保存中…'}
  var ow=document.querySelector('.wrap');
  if(!ow){window.location.href=f.action||location.href;return}
  var targetAct=new URL(act,location.origin).searchParams.get('action')||'';
  var currentAct=new URL(location.href).searchParams.get('action')||'';
  var samePage=targetAct===currentAct;
  // 不再在请求期间隐藏页面：等结果回来再切换，避免“点一下页面就消失、迟迟不回来”
  navPending(true);
  pausePlayerPreload();
  fetch(f.action||location.href,{method:'POST',body:new FormData(f)})
    .then(function(r){if(!r.ok)throw Error();var u=r.url;return r.text().then(function(h){return{html:h,url:u}})})
    .then(function(res){
      var doc=new DOMParser().parseFromString(res.html,'text/html');
      var nw=doc.querySelector('.wrap');if(!nw){window.location.href=f.action||location.href;return}
      var nc=doc.querySelector('#page-css');
      var nj=doc.getElementById('page-js');
      var cssHref=nc?nc.getAttribute('href'):null;
      var jsSrc=nj?nj.getAttribute('src'):null;
      if(samePage){
        var scr=ow.scrollTop;
        ow.innerHTML=nw.innerHTML;
        document.title=doc.title;
        var a0=new URL(res.url,location.origin).searchParams.get('action');
        if(a0&&navActions.indexOf(a0)!==-1)setActiveNav(a0);
        if(res.url!==location.href)history.pushState(null,'',res.url);
        swapPageCss(cssHref,function(){});
        loadPageScripts(jsSrc,function(){
          runPageScripts(ow);
          ow.scrollTop=scr;
        });
        return;
      }
      applyPage(ow,nw.innerHTML,doc,res.url,true,false);
      swapPageCss(cssHref,function(){});
      loadPageScripts(jsSrc,function(){ runPageScripts(ow); });
    })
    .catch(function(){window.location.href=f.action||location.href})
    .finally(function(){navPending(false);resumePlayerPreload();if(btn){btn.disabled=false;btn.textContent=origTxt}});
});

window.addEventListener('popstate',function(){
  navigateTo(location.href,false);
});
})();

function copyEmbedKey(key){
  var code='<script src="'+window.location.origin+(window.RELAY&&window.RELAY.embed_js||'/api.php')+'?key='+encodeURIComponent(key)+'" defer><\/script>';
  navigator.clipboard.writeText(code);
}

function initCodeMirror() {
  if (typeof CodeMirror === 'undefined') return;
  var ta = document.querySelector('textarea[name="mail_tpl_body"]');
  if (!ta) return;
  if (ta.nextSibling && ta.nextSibling.classList && ta.nextSibling.classList.contains('CodeMirror')) return;

  var isMobile = window.innerWidth < 768;
  var editor = CodeMirror.fromTextArea(ta, {
    mode: 'htmlmixed',
    theme: 'monokai',
    lineNumbers: !isMobile,
    lineWrapping: false,
    tabSize: 2,
    indentUnit: 2,
    matchBrackets: true,
    autoCloseTags: true,
    styleActiveLine: true
  });
  editor.setSize(null, isMobile ? 280 : 400);
  editor.on('change', function(){ editor.save(); });
  var wrapper = editor.getWrapperElement();
  wrapper.style.borderRadius = '10px';
  wrapper.style.overflow = 'hidden';
  var gutter = wrapper.querySelector('.CodeMirror-gutters');
  if (gutter) gutter.style.borderRadius = '10px 0 0 10px';
  var scroll = wrapper.querySelector('.CodeMirror-scroll');
  if (scroll) {
    scroll.style.scrollbarWidth = 'none';
    scroll.style.msOverflowStyle = 'none';
    if (isMobile) scroll.style.webkitOverflowScrolling = 'touch';
  }
}

initCodeMirror();

(function(){
  var curUser = (document.querySelector('.sb-username') || {}).textContent || '';
  var pusher = new Pusher('333723b6068a283d1b6b', { cluster: 'ap3', channelAuthorization: { endpoint: '?action=pusher-auth', transport: 'ajax' } });
  var channel = pusher.subscribe('presence-admin-online');

  channel.bind('user-online', function(data) {
    if(data.username && data.username !== curUser) showFloatingToast(data.username + ' 上线了');
  });

  channel.bind('user-offline', function(data) {
    if(data.username && data.username !== curUser) showFloatingToast(data.username + ' 离线了');
  });

  window.pusherOnlineIds = {};
  window.pusherOnlineReady = false;

  channel.bind('user-online', function(data) {
    if(data.user_id){
      window.pusherOnlineIds[data.user_id] = true;
      window.pusherOnlineReady = true;
      var el = document.querySelector('.online-dot[data-uid="'+data.user_id+'"]');
      if(el){ el.style.background='#27ae60'; el.title='在线'; }
    }
  });

  channel.bind('user-offline', function(data) {
    if(data.user_id){
      delete window.pusherOnlineIds[data.user_id];
      var el = document.querySelector('.online-dot[data-uid="'+data.user_id+'"]');
      if(el){ el.style.background='#b2bec3'; el.title='离线'; }
    }
  });
})();

function showFloatingToast(msg){
  var el = document.createElement('div');
  el.className = 'float-toast';
  el.textContent = msg;
  document.body.appendChild(el);
  requestAnimationFrame(function(){ el.classList.add('show'); });
  setTimeout(function(){ el.classList.remove('show'); setTimeout(function(){ el.remove(); }, 400); }, 3000);
}
window.showFloatingToast = showFloatingToast;