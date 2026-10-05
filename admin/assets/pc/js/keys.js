var _testPlayerWatch=null;
var _svgPlay='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
var _svgStop='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2"/></svg>';

// 状态必须放在"页面级内存": SPA 切回密钥页会重新执行本文件, 模块内变量会被重置;
function requestedPlayerKey(){
  return window.__mapiPlayerRequested || null;
}
// 实例存在、未被销毁、启动未失败（可能仍在启动中，宿主元素尚未出现）
function playerExists(){
  var MP=window.__mapiPlayer;
  return !!(MP&&!MP._destroyed&&!MP._bootFailed);
}
// 真正可用：宿主控件已出现在页面上
function playerUsable(){
  return playerExists()&&!!document.querySelector('[id^="mapi-player-"]');
}
// 该按钮是否应显示“卸载”：本页已请求加载该 key，且未明确失败（含仍在加载中）
function playerOn(apiKey){
  if(requestedPlayerKey()!==apiKey) return false;
  var MP=window.__mapiPlayer;
  if(MP&&(MP._destroyed||MP._bootFailed)) return false;
  return true;
}
function stopWatchPlayerBoot(){if(_testPlayerWatch){clearInterval(_testPlayerWatch);_testPlayerWatch=null}}
// 清掉“已请求”状态与播放器资源标签，按钮回到“加载”，保证可以重试
function resetPlayerButton(){
  stopWatchPlayerBoot();
  window.__mapiPlayerRequested=null;
  try{localStorage.removeItem('_mapiPlayerKey')}catch(e){}
  var st=document.getElementById('testPlayerScript');
  if(st)st.remove();
  document.querySelectorAll('script[data-mapi-asset]').forEach(function(el){el.remove()});
  var apCss=document.getElementById('mapi-aplayer-css');
  if(apCss)apCss.remove();
  updateTestPlayerButtons();
}
// 盯着启动过程：宿主出现＝可用（保持“卸载”）；明确失败＝复位；实例始终未出现则 30s 后复位
function watchPlayerBoot(){
  stopWatchPlayerBoot();
  var tries=0;
  _testPlayerWatch=setInterval(function(){
    tries++;
    var MP=window.__mapiPlayer;
    if(MP&&(MP._destroyed||MP._bootFailed)){ resetPlayerButton(); return; }
    if(playerUsable()){ stopWatchPlayerBoot(); updateTestPlayerButtons(); return; }
    if(!MP&&tries>60) resetPlayerButton();
  },500);
}

function toggleTestPlayer(apiKey){
  if(playerOn(apiKey)){unloadTestPlayer()}
  else{loadTestPlayer(apiKey)}
}

function loadTestPlayer(apiKey){
  if(playerOn(apiKey)){updateTestPlayerButtons();return}
  unloadTestPlayer();                       // 同一时刻只允许存在一个实例
  window.__mapiPlayerRequested=apiKey;      // 先记录“已请求”，按钮即刻显示“卸载”（不依赖脚本是否已执行）
  var s=document.createElement('script');
  s.id='testPlayerScript';
  s.src=window.location.origin+(window.RELAY&&window.RELAY.embed_js||'/api.php')+'?key='+encodeURIComponent(apiKey);
  s.setAttribute('key',apiKey);             // 转发壳仍会读它；新入口以 URL 参数为准
  document.body.appendChild(s);
  if(!document.getElementById('testPlayerStyles')){
    var st=document.createElement('style');
    st.id='testPlayerStyles';
    st.textContent='.btn-test-load.active-test{background:rgba(46,204,113,.15);color:#27ae60;border-color:rgba(46,204,113,.3)}.data-item.testing{box-shadow:inset 3px 0 0 #27ae60}';
    document.head.appendChild(st);
  }
  updateTestPlayerButtons();
  watchPlayerBoot();
}

function unloadTestPlayer(){
  stopWatchPlayerBoot();
  // destroy() 会先置销毁标记并中止在途请求/定时器，因此“加载中途卸载”也不会留下残留播放器
  if(window.__mapiPlayer&&typeof window.__mapiPlayer.destroy==='function'){
    try{window.__mapiPlayer.destroy()}catch(e){}
  }
  window.__mapiPlayer=null;
  try{window.__mszeph_config=null}catch(e){}
  document.querySelectorAll('[id^="mapi-player-"]').forEach(function(host){
    if(host.shadowRoot){
      host.shadowRoot.querySelectorAll('audio,video').forEach(function(a){a.pause();a.src='';a.load()});
    }
    host.querySelectorAll('audio,video').forEach(function(a){a.pause();a.src='';a.load()});
    host.remove();
  });
  document.querySelectorAll('audio,video').forEach(function(a){
    if(a.currentSrc&&a.currentSrc.indexOf('api')!==-1){a.pause();a.src='';a.load()}
  });
  var s=document.getElementById('testPlayerScript');
  if(s)s.remove();
  var styles=document.getElementById('testPlayerStyles');
  if(styles)styles.remove();
  var sbStyle=document.getElementById('mapi-scrollbar-style');
  if(sbStyle)sbStyle.remove();
  // 播放器资源标签（模块/APlayer）与 APlayer CSS：一并清掉，反复加载卸载不会让 head 增长
  document.querySelectorAll('script[data-mapi-asset]').forEach(function(el){el.remove()});
  var apCss=document.getElementById('mapi-aplayer-css');
  if(apCss)apCss.remove();
  document.querySelectorAll('style').forEach(function(st){
    if(st.id==='testPlayerStyles')return;
    if(st.textContent.indexOf('html::-webkit-scrollbar{display:none}')===0)st.remove();
  });
  var co=document.getElementById('mapi-consent-overlay');
  if(co)co.remove();
  var sl=document.getElementById('mapi-scroll-lock');
  if(sl)sl.remove();
  var ann=document.querySelector('[data-mp="annWrap"]');
  if(ann)ann.remove();
  var annLock=document.getElementById('mapi-ann-scroll-lock');
  if(annLock)annLock.remove();
  var lrc=document.querySelector('[data-mp="lrc"]');
  if(lrc)lrc.remove();
  window.__mapiPlayerRequested=null;
  try{localStorage.removeItem('_mapiPlayerKey')}catch(e){}
  updateTestPlayerButtons();
}

function updateTestPlayerButtons(){
  document.querySelectorAll('.btn-test-load').forEach(function(btn){
    var key=btn.getAttribute('data-key');
    var item=btn.closest('.data-item');
    if(playerOn(key)){
      btn.innerHTML=_svgStop+' 卸载';
      btn.classList.add('active-test');
      if(item)item.classList.add('testing');
    }else{
      btn.innerHTML=_svgPlay+' 加载';
      btn.classList.remove('active-test');
      if(item)item.classList.remove('testing');
    }
  });
}

(function(){
  // 刷新后页面级内存为空 → 播放器必然已断开：清掉历史遗留的 localStorage 记忆并显示“加载”
  if(!window.__mapiPlayerRequested){
    try{localStorage.removeItem('_mapiPlayerKey')}catch(e){}
    updateTestPlayerButtons();
    return;
  }
  var MP=window.__mapiPlayer;
  if(MP&&(MP._destroyed||MP._bootFailed)){ resetPlayerButton(); return; }
  // 仍在启动中或已就绪：保持“卸载”，并继续盯着启动结果（成功保持 / 失败复位）
  updateTestPlayerButtons();
  watchPlayerBoot();
})();

// ═══ 创建密钥弹窗（名称必填 + 授权域名）═══
// 用公共层的 .song-modal（样式在 base.css，与歌单/配置页弹窗同一套），交互照 config.js 的约定：
// 挪到 body 下（fixed 定位才盖得住侧栏 / 不受页面容器影响）→ 打开切 display=flex → 关闭走 closing 类 + animationend。
(function bindCreateKeyModal(){
  if(window.__keyModalClickBound)return;
  window.__keyModalClickBound=1;
  // 委托挂在 document 上：软切换只换 .wrap 内容（弹窗 DOM 会整份换新），绑在旧节点上的监听会一起失效
  document.addEventListener('click',function(e){
    var m=document.getElementById('createKeyModal');
    if(!m||m.style.display==='none')return;
    var t=e.target;
    if(!t||!m.contains(t)||!t.closest)return;
    if(t.closest('.song-modal-backdrop')||t.closest('.song-modal-close'))closeCreateKeyModal();
  });
})();
function openCreateKeyModal(){
  var m=document.getElementById('createKeyModal');
  if(!m)return;
  if(m.parentElement!==document.body)document.body.appendChild(m);  // 软切换后弹窗会随新页面 DOM 回到 .wrap 里
  m.style.display='flex';
  var i=document.getElementById('createKeyName');
  if(i)setTimeout(function(){try{i.focus()}catch(e){}},30);
}
function closeCreateKeyModal(){
  var m=document.getElementById('createKeyModal');
  if(!m||m.style.display==='none'||m.classList.contains('closing'))return;
  m.classList.add('closing');
  var done=function(){ m.classList.remove('closing'); m.style.display='none'; };
  m.addEventListener('animationend',function onEnd(){ m.removeEventListener('animationend',onEnd); done(); });
  setTimeout(function(){ if(m.classList.contains('closing'))done(); },400);
}
// 名称前端也拦一道（服务端同样会校验）；顺便做去空格/小写与防重复提交
function handleCreateKeySubmit(e){
  var nm=document.getElementById('createKeyName');
  if(nm&&!String(nm.value||'').replace(/\s/g,'')){
    e.preventDefault(); showToast('请先填写密钥名称','err');
    try{nm.focus()}catch(err){}
    return false;
  }
  var dm=document.getElementById('createKeyDomain');
  if(dm){ dm.value=String(dm.value||'').trim().toLowerCase(); }
  var btn=document.querySelector('#createKeyForm [type="submit"]');
  if(btn){
    if(btn.disabled){ e.preventDefault(); return false; }
    btn.disabled=true; btn.textContent='创建中…';
  }
  return true;
}
if(!window.__keyEscBound){window.__keyEscBound=1;document.addEventListener('keydown',function(e){if(e.key==='Escape')closeCreateKeyModal()});}
// 服务端校验失败会带着 ?create=1 回来 → 自动把弹窗重新打开
(function(){
  try{
    if(!/[?&]create=1(&|$)/.test(location.search))return;
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',openCreateKeyModal);
    else openCreateKeyModal();
  }catch(e){}
})();