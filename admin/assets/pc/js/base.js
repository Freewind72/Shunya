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
// ⚠️ 必须与 admin/layout/header.php 的 $navParents 保持一致（两处不同步会导致"刷新有高亮、切页没了"）。
var navParents={'settings-site':'settings','settings-mail':'settings','settings-security':'settings','settings-api':'settings','settings-storage':'settings'};
function navKeyOf(action){return navParents[action]||action}

// —— 路由形态（2026-10-20 起两类）：页面 = /admin/pages/<page>/（目录索引，nginx 零配置直配）
//    动作 = /admin/api/?action=<动作>（单一入口 admin/api/index.php）。旧形态 /admin/?action=<action> 由服务端 301 收口。——
//    ⚠️ 页面地址**带 pages 段**：/admin/pages/keys/ 是真实目录，靠 nginx 自带的 `index index.php`
//       直接命中 admin/pages/keys/index.php —— 不需要任何 rewrite / try_files。
//    ⚠️ navActions / isActionHref / actionOfUrl 仍然只认**页面路径**：动作 URL 不走软导航。
// 判断一律走"路径段"，不要再用 searchParams.get('action')：切页是 pushState 改地址栏的，
// 路径才是当前页的真实来源。ADMIN_BASE 可由页面覆盖（子目录部署时用）。
var ADMIN_BASE=window.ADMIN_BASE||'/admin';
// 页面目录段 —— 必须与 admin/lib/url.php 的 ADMIN_PAGES_DIR 逐字一致
var PAGE_DIR='pages';
function actionOfUrl(u){
  try{
    var p=new URL(u,location.origin).pathname;
    if(p!==ADMIN_BASE&&p.indexOf(ADMIN_BASE+'/')!==0)return '';
    var rest=p.slice(ADMIN_BASE.length).replace(/^\/+|\/+$/g,'');
    if(!rest||/^index\.php$/i.test(rest))return '';
    var seg=rest.split('/');
    // 剥页面目录段：/admin/pages/keys/ → keys（大小写不敏感）
    if(seg.length>1&&String(seg[0]).toLowerCase()===PAGE_DIR)seg.shift();
    var seg0=seg[0]||'';
    return seg0.length>4&&/\.php$/i.test(seg0)?seg0.slice(0,-4):seg0;
  }catch(e){return ''}
}
// 链接的 href 与"某个 action 的规范路径"是否同一个页面。
// ⚠️ 必须与 admin/lib/url.php 的 admin_url() 生成规则**逐字一致**，这里由 PAGE_DIR + PAGE_SUFFIX 决定：
//   服务端生成 /admin/pages/<action>/（带尾斜杠的目录索引形态，零 rewrite 配置），判定也必须是同一个形态，
//   否则 toggle(..., false) 会把高亮全部清掉（症状：只有刷新时服务端渲染的高亮在，切页就没了）。
var PAGE_SUFFIX='/';
function isActionHref(href,action){
  if(!href)return false;
  var want=ADMIN_BASE+'/'+PAGE_DIR+'/'+action+PAGE_SUFFIX;
  return href===want||href===location.origin+want;
}

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
    el.classList.toggle('active',isActionHref(el.getAttribute('href'),key));
  });
  var grp=document.querySelector('.sidebar .sb-group');
  if(!grp)return;
  var child=null;
  grp.querySelectorAll('.sb-sub-item').forEach(function(el){
    var on=isActionHref(el.getAttribute('href'),action);
    el.classList.toggle('active',on);
    if(on)child=el;
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

/* 按**文档顺序**依次加载一组外部 JS，最后一个 onload（或任何 onerror）之后才回调。
   串行是刻意的：页面里的外部脚本可能被后面的脚本依赖（页面区先定义 window.XXX，
   同页的 page-js 随后就读它）。并行加载不保证先后，会随机"库没到就先用"。 */
function loadExternalScripts(list,cb){
  if(!list||!list.length){cb();return}
  var i=0;
  function next(){
    if(i>=list.length){cb();return}
    var s=document.createElement('script');
    s.src=list[i++];
    s.onload=next;
    s.onerror=next;        // 单个脚本失败不阻断后面的，也不阻断页面 JS
    document.body.appendChild(s);
  }
  next();
}

/* 收集软导航响应**页面区里**的外部脚本，按文档顺序返回。
   ⚠ 必须排除 #page-js：它由 footer.php 独立产出（在 .wrap 之外），已由 loadPageScripts
   按同一时序单独加载；若这里再跑一次，页面 JS 会被执行两遍（重复建连、重复绑事件）。 */
function externalScriptSrcs(scope){
  var out=[];
  scope.querySelectorAll('script[src]').forEach(function(s){
    if(s.id==='page-js')return;
    var u=s.getAttribute('src');
    if(!u)return;
    if(out.indexOf(u)===-1)out.push(u);     // 同一地址去重：避免重复执行
  });
  return out;
}

function loadPageScripts(src,cb){
  var cur=document.getElementById('page-js');
  if(!src){if(cur)cur.remove();cb();return}
  /* ⚠ 不能因为"src 没变"就跳过执行：**两个不同页面可以共用同一份页面 JS**。
     内容已被 applyPage 换掉，页面 JS 就必须为新内容重新 boot 一次；旧的跳过逻辑让第二个页面
     永远"没有 JS"——表现为软导航进该页后只显示空态（硬刷新却正常）。
     重新插入同 src 的 <script> 会走浏览器缓存 + 重新执行，不会多一次网络往返。 */
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
  }function runPageScripts(scope,ext){
  var hasExt=false;
  scope.querySelectorAll('script').forEach(function(s){
    /* 带 src 的外部脚本**不能**在这里执行：el.textContent 为空 ⇒ 旧代码直接 return 掉，
       于是它永远不执行（外链静默丢失）。收集出去交给 loadExternalScripts 按序加载；
       带 ext（=软导航）时才收集，表单保存后的重执行按原样跳过，不改既有时序。 */
    if(s.getAttribute('src')||s.hasAttribute('src')){
      if(s.id!=='page-js')hasExt=true;
      return;
    }
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
  return ext?hasExt:false;      // 调用方据此决定"是否还要按序加载外部脚本"
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
  var a=actionOfUrl(url);
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
      /* ⚠ 顺序要紧，且**唯一出口**只有一个（别把 loadPageScripts 复制成两份，会漂移）：
         ① 先执行新页的内联脚本（`window.__XXX` 这类"页面 → 页面 JS"的数据契约）；
         ② 再按文档顺序补跑页面里的**外部脚本**（旧引擎只重放内联、外链被静默丢掉）；
         ③ 最后才加载 #page-js。
         反过来的话，页面 JS 起来时读到的还是**上一页**的内联数据
         （症状：软导航进某页后拿不到页面数据、只显示空态；硬刷新却正常，因为整页加载时内联脚本先跑）。 */
      var __needExt=runPageScripts(wrap,true);
      var __extRun=__needExt?loadExternalScripts:function(_l,next){next()};
      __extRun(externalScriptSrcs(wrap),function(){
        if(_navAbort!==ctrl)return;
        loadPageScripts(nj?nj.getAttribute('src'):null,function(){
          if(_navAbort!==ctrl)return;
          finishNav(ctrl);
        });
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
  var link=e.target.closest('a[href]');
  if(!link||!link.href||link.target)return;
  if(new URL(link.href,location.origin).origin!==location.origin)return;
  var a=actionOfUrl(link.href);
  if(a==='logout'||navActions.indexOf(a)===-1)return;
  // 点「当前页自己」的导航项 → 不重复加载（保持原行为）。但「父项高亮」的页面不在此列：
  // 例如某个子页面的高亮项是它的父菜单，此时点父菜单应当真的回列表页，否则侧栏那一项
  // 在子页上变成死链（点了没反应）。判据用 action 比当前页更准。
  if(a===actionOfUrl(location.href)){e.preventDefault();return;}
  e.preventDefault();
  navigateTo(link.href);
});

document.addEventListener('click',function(e){
  // 记住"最后点击的提交按钮"：new FormData(f) 按规范**不含**提交按钮的 name/value，
  // 而 e.submitter 不是所有浏览器都有 —— 这里留个兜底，提交时补发按钮名。
  var el=e.target;
  if(!el||!el.closest)return;
  var b=el.closest('button:not([type]),button[type="submit"],input[type="submit"]');
  if(b&&b.name)window.__lastSubmitter=b;
},true);

document.addEventListener('submit',function(e){
  if(e.defaultPrevented)return;
  var f=e.target;
  if(f.method!=='post')return;
  if(f.hasAttribute('data-modal'))return;
  var act=(f.getAttribute('action')||'').trim()||location.href;
  if(act.indexOf('?action=')===-1&&!actionOfUrl(act))return;
  e.preventDefault();
  // **提交按钮的 name/value 必须一起发**：后端大量分支是靠 isset($_POST['_xxx_submit']) 判断
  // "用户点了哪个按钮"的，而 new FormData(f) 不含它 → 分支永远不跑、页面弹回旧值
  //（实测踩到：Redis 卡的"保存"点了没反应、配置看着像回弹）。
  var sub=e.submitter||window.__lastSubmitter||null;
  var btn=(sub&&sub.form===f)?sub:f.querySelector('[type="submit"],button:not([type]),button[type="submit"]');
  var origTxt=btn?btn.textContent:'';
  if(btn){btn.disabled=true;btn.textContent='保存中…'}
  var ow=document.querySelector('.wrap');
  if(!ow){window.location.href=f.action||location.href;return}
  var targetAct=actionOfUrl(act)||'';
  var currentAct=actionOfUrl(location.href)||'';
  var samePage=targetAct===currentAct;
  // 不再在请求期间隐藏页面：等结果回来再切换，避免“点一下页面就消失、迟迟不回来”
  navPending(true);
  pausePlayerPreload();
  var fd=new FormData(f);
  if(sub&&sub.name)fd.append(sub.name,sub.value);      // ← 关键一行（缺失就是"保存回弹"）
  fetch(f.action||location.href,{method:'POST',body:fd,credentials:'same-origin'})
    .then(function(r){
      // ★ 不再对任何非 2xx 一律"跳回 action"：那是个纯 GET，等于**静默清空用户刚填的内容**
      //   —— 报错页面看起来像"刷新了一下、内容回弹"，用户完全不知道发生了什么。
      //   改成：先尽力渲染服务器返回的页面（后台的报错页多半也是正常页面），
      //   渲染不了就**原地保留表单** + Toast 提示，绝不跳转、绝不丢数据。
      if(!r.ok) return {html:null,url:r.url,error:'服务器返回 '+r.status+(r.statusText?' '+r.statusText:'')};
      return r.text().then(function(h){return{html:h,url:r.url,error:''}});
    })
    .then(function(res){
      var nw=null,doc=null;
      if(res.html){
        doc=new DOMParser().parseFromString(res.html,'text/html');
        nw=doc.querySelector('.wrap');
      }
      if(!nw){
        // 拿不到页面骨架（非 2xx / 断网后的兜底 / 返回的不是后台页面）
        showToast((res.error||'保存失败：请求没有拿到页面') + '，你填的内容还在，请重试', 'err');
        return null;                       // 什么都不做：表单、按钮、滚动位置全部保持原样
      }
      var nc=doc.querySelector('#page-css');
      var nj=doc.getElementById('page-js');
      var cssHref=nc?nc.getAttribute('href'):null;
      var jsSrc=nj?nj.getAttribute('src'):null;
      if(samePage){
        var scr=ow.scrollTop;
        ow.innerHTML=nw.innerHTML;
        document.title=doc.title;
        var a0=actionOfUrl(res.url);
        if(a0&&navActions.indexOf(a0)!==-1)setActiveNav(a0);
        if(res.url!==location.href)history.pushState(null,'',res.url);
        swapPageCss(cssHref,function(){});
        loadPageScripts(jsSrc,function(){
          runPageScripts(ow);
          ow.scrollTop=scr;
        });
        return null;
      }
      applyPage(ow,nw.innerHTML,doc,res.url,true,false);
      swapPageCss(cssHref,function(){});
      loadPageScripts(jsSrc,function(){ runPageScripts(ow); });
      return null;
    })
    .catch(function(err){
      // 网络层失败：断网 / 服务器没响应 / 请求被中断。
      // fetch 只有"整个请求没拿到响应"时才 reject，此时**什么都没提交**，
      // 所以最正确的处理就是留在原地、明确告诉用户，让他自己决定要不要重试。
      console.warn('[submit] 提交失败：',err);
      var off=('onLine' in navigator)&&!navigator.onLine;
      showToast(off?'网络已断开：这次保存没有提交，内容还在，恢复网络后请重试'
                   :'请求失败：这次保存可能没有提交，内容还在，请重试','err');
      return null;
    })
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


function showFloatingToast(msg){
  var el = document.createElement('div');
  el.className = 'float-toast';
  el.textContent = msg;
  document.body.appendChild(el);
  requestAnimationFrame(function(){ el.classList.add('show'); });
  setTimeout(function(){ el.classList.remove('show'); setTimeout(function(){ el.remove(); }, 400); }, 3000);
}
window.showFloatingToast = showFloatingToast;