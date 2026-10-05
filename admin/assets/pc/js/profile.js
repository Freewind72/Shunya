function toggleTheme(){
  var btn=document.getElementById('themeSwitch');if(!btn)return;
  var isDark=!btn.classList.contains('active');
  fetch('?action=profile',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'_theme_toggle=1&_csrf='+encodeURIComponent(document.querySelector('input[name=_csrf]').value)+'&mode='+(isDark?'dark':'light')})
  .then(function(r){if(r.ok){btn.classList.toggle('active',isDark);document.body.setAttribute('data-theme',isDark?'dark':'light');}});
}

function saveBgUrl(){
  var url=document.getElementById('bgUrlInput');var btn=document.getElementById('bgUrlSaveBtn');var st=document.getElementById('bgStatus');
  if(!url||!btn||!st)return;url=url.value.trim();btn.disabled=true;btn.textContent='保存中…';var _c=document.querySelector('input[name=_csrf]');
  fetch('?action=bg-url-save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({url:url,_csrf:_c&&_c.value})})
  .then(function(r){return r.json()}).then(function(d){
    if(!d.ok)throw Error(d.error||'保存失败');
    st.style.display='block';st.style.color='#27ae60';st.textContent='壁纸链接已保存';
    if(url)document.body.style.background='#e8e8ec url('+url+') center/cover no-repeat fixed';
    else document.body.style.background='';   // 清空链接就地生效，不整页刷新（会刷掉页内播放器）
  }).catch(function(e){st.style.display='block';st.style.color='#d63031';st.textContent=e.message||'保存失败';})
  .finally(function(){btn.disabled=false;btn.textContent='保存链接';});
}

var bgUploading=false;
function uploadBg(){var _c=document.querySelector('input[name=_csrf]');
  if(bgUploading)return;var file=document.getElementById('bgFileInput');
  if(!file||!file.files[0]){alert('请选择文件');return}file=file.files[0];
  if(file.size>5*1024*1024){alert('文件大小不能超过5MB');return}
  if(['image/jpeg','image/png','image/webp','image/gif'].indexOf(file.type)===-1){alert('仅支持JPG/PNG/WebP/GIF');return}
  bgUploading=true;var btn=document.getElementById('bgUploadBtn');var st=document.getElementById('bgStatus');
  btn.disabled=true;btn.textContent='获取签名…';st.style.display='block';st.style.color='#666';st.textContent='';
  fetch('?action=bg-presign',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({mime:file.type,_csrf:_c&&_c.value})})
  .then(function(r){return r.json()}).then(function(d){
    if(!d.ok)throw Error(d.error||'获取签名失败');st.textContent='正在上传…';
    return fetch(d.url,{method:'PUT',body:file,headers:{'Content-Type':file.type}}).then(function(s3r){if(!s3r.ok)throw Error('上传失败HTTP '+s3r.status);return d.key;});
  }).then(function(key){st.textContent='正在保存…';
    return fetch('?action=bg-confirm',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({key:key,_csrf:_c&&_c.value})}).then(function(r){return r.json()});
  }).then(function(d){
    if(!d.ok)throw Error(d.error||'保存失败');st.style.color='#27ae60';st.textContent='上传成功！';
    var prev=document.getElementById('bgPreview');if(!prev){prev=document.createElement('div');prev.id='bgPreview';prev.style.cssText='margin-bottom:12px;border-radius:8px;overflow:hidden;max-height:160px';var card=document.querySelector('.card:nth-child(2)');if(card)card.insertBefore(prev,card.children[1]);}
    prev.innerHTML='<img src="'+d.url+'?t='+Date.now()+'" alt="当前背景" style="width:100%;height:auto;display:block;object-fit:cover;max-height:160px">';prev.style.display='block';
    document.body.style.background='#e8e8ec url('+d.url+'?t='+Date.now()+') center/cover no-repeat fixed';
  }).catch(function(e){st.style.color='#d63031';st.textContent=e.message||'上传失败';})
  .finally(function(){bgUploading=false;btn.disabled=false;btn.textContent='上传背景';});
}

function b64urlToBuf(s){var bin=atob(s.replace(/-/g,'+').replace(/_/g,'/'));var buf=new ArrayBuffer(bin.length);var v=new Uint8Array(buf);for(var i=0;i<bin.length;i++)v[i]=bin.charCodeAt(i);return buf}
function bufToB64url(buf){var v=new Uint8Array(buf);var bin='';for(var i=0;i<v.length;i++)bin+=String.fromCharCode(v[i]);return btoa(bin).replace(/=/g,'').replace(/\+/g,'-').replace(/\//g,'_')}

function logErr(tag,msg){
  var fd=new FormData();
  fd.append('name',tag);
  fd.append('message',String(msg).substring(0,500));
  navigator.sendBeacon('?action=js-log',fd);
}

function pkErrMsg(e){
  var m={
    'NotAllowedError':'操作已取消或超时',
    'InvalidStateError':'该通行密钥已绑定过',
    'SecurityError':'安全限制：请使用域名或localhost访问，不支持IP地址',
    'TypeError':'请求参数异常，请刷新页面重试',
    'NetworkError':'网络连接失败，请检查网络',
    'AbortError':'操作被中断',
    'ConstraintError':'当前设备不支持通行密钥',
    'UnknownError':'认证器发生未知错误'
  };
  if(m[e.name])return m[e.name];
  if(e.message){
    if(e.message.indexOf('invalid domain')!==-1)return '域名无效：请使用域名或localhost访问，不支持IP地址';
    if(e.message.indexOf('The operation either timed out')!==-1||e.message.indexOf('timed out')!==-1)return '操作超时，请重试';
    if(e.message.indexOf('The authenticator was already registered')!==-1)return '该通行密钥已绑定过';
    if(e.message.indexOf('User consent')!==-1||e.message.indexOf('user canceled')!==-1||e.message.indexOf('cancelled')!==-1)return '操作已取消';
    if(e.message.indexOf('not available')!==-1||e.message.indexOf('not supported')!==-1)return '当前浏览器或设备不支持通行密钥';
  }
  return '绑定失败：'+(e.message||e.name||'未知错误');
}

function registerPasskey(){
  var btn=document.getElementById('pkRegBtn');btn.disabled=true;btn.textContent='绑定中...';
  var csrfToken='';
  fetch('?action=pk-begin',{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(d){
    if(d.error){alert(d.error);btn.disabled=false;btn.textContent='绑定通行密钥';return}
    if(!d.challenge){alert('获取挑战失败，请刷新重试');btn.disabled=false;btn.textContent='绑定通行密钥';return}
    csrfToken=d._csrf||'';
    var rpId=d.rpId||location.hostname;
    var uid=new TextEncoder().encode(''+d.userId);
    return navigator.credentials.create({publicKey:{
      challenge:b64urlToBuf(d.challenge),
      rp:{id:rpId,name:'顺雅音乐'},
      user:{id:uid,name:d.userName,displayName:d.userName},
      pubKeyCredParams:[{type:'public-key',alg:-7},{type:'public-key',alg:-257}],
      excludeCredentials:d.excludeCredentials?d.excludeCredentials.map(function(c){return {type:'public-key',id:b64urlToBuf(c)}}):[],
      authenticatorSelection:{residentKey:'required',userVerification:'preferred'},
      timeout:d.timeout||300000
    }});
  }).then(function(cred){
    if(!cred){btn.disabled=false;btn.textContent='绑定通行密钥';return}
    var ao=bufToB64url(cred.response.attestationObject),cjd=bufToB64url(cred.response.clientDataJSON);
    var fd=new FormData();fd.append('attestationObject',ao);fd.append('clientDataJSON',cjd);fd.append('_csrf',csrfToken);
    return fetch('?action=pk-complete',{method:'POST',body:fd}).then(function(r){return r.json()});
  }).then(function(d){
    btn.disabled=false;btn.textContent='绑定通行密钥';
    if(d&&d.ok){   // 就地软刷新重画密钥列表，不整页刷新
      if(typeof navigateTo==='function')navigateTo(location.href,false);
      else location.reload();
    }
    else if(d&&d.err){logErr('err','complete:'+d.err);alert('绑定失败：'+d.err);}
  }).catch(function(e){
    btn.disabled=false;btn.textContent='绑定通行密钥';
    logErr('catch',e.name+': '+e.message);
    alert(pkErrMsg(e));
  });
}