/* ═══ 极验验证码 ═══ */
(function(){
  var CAPTCHA_ID = typeof GEETEST_CAPTCHA_ID !== 'undefined' ? GEETEST_CAPTCHA_ID : '';
  var _captchaObj = null;
  var _ready = false;
  var _inited = false;
  var _failed = false;
  var _activeFormId = '';

  // 极验初始化
  initGeetest4({ captchaId: CAPTCHA_ID, product: 'bind' }, function(captchaObj) {
    _captchaObj = captchaObj;

    captchaObj.onReady(function() {
      _ready = true;
      _inited = true;
    });

    captchaObj.onSuccess(function() {
      var result = _captchaObj.getValidate();
      if (!result) return;
      // 写入两个表单
      ['loginFormPc', 'loginFormMb'].forEach(function(fid) {
        var form = document.getElementById(fid);
        if (!form) return;
        form.querySelector('input[name=geetest_lot_number]').value = result.lot_number || '';
        form.querySelector('input[name=geetest_captcha_output]').value = result.captcha_output || '';
        form.querySelector('input[name=geetest_pass_token]').value = result.pass_token || '';
        form.querySelector('input[name=geetest_gen_time]').value = result.gen_time || '';
      });
      // 提交
      var form = document.getElementById(_activeFormId);
      if (form) form.submit();
      captchaObj.reset();
    });

    captchaObj.onError(function(err) {
      _failed = true;
    });
  });

  // 极验超时：15 秒后如果还没就绪，标记为失败
  setTimeout(function() {
    if (!_ready) _failed = true;
  }, 15000);

  // 绑定登录按钮（无论极验是否就绪）
  function bindLoginBtn(formId, btnId) {
    var btn = document.getElementById(btnId);
    if (!btn) return;
    btn.addEventListener('click', function() {
      var form = document.getElementById(formId);
      if (!form) return;
      var u = form.querySelector('input[name=username]').value.trim();
      var p = form.querySelector('input[name=password]').value;
      if (!u || !p) { showToast('请填写账号和密码', 'err'); return; }
      if (_failed) {
        showToast('验证服务不可用，请刷新重试', 'err');
        return;
      }
      if (!_ready) {
        showToast('验证服务加载中，请稍候', 'err');
        return;
      }
      _activeFormId = formId;
      _captchaObj.showCaptcha();
    });
  }

  bindLoginBtn('loginFormPc', 'loginBtnPc');
  bindLoginBtn('loginFormMb', 'loginBtnMb');
})();

/* ═══ 用户名输入检测头像 ═══ */
(function(){
    var _t;
    document.querySelectorAll('input[name=username]').forEach(function(inp){
        inp.addEventListener('input', function(){
            var u = this.value.trim();
            clearTimeout(_t); _t = setTimeout(function(){
                var el = document.querySelector('.avatar');
                if (!u) { if (el) el.remove(); return; }
                fetch('/admin/api/?action=get-avatar&username='+encodeURIComponent(u)).then(function(r){return r.json()}).then(function(d){
                    if (!d || !d.avatar) { var e=document.querySelector('.avatar'); if(e)e.remove(); return; }
                    var e = document.querySelector('.avatar');
                    if (!e) { e=document.createElement('img'); e.className='avatar'; var fl=document.querySelector('.push-login .fl,.glass .fl'); if(fl&&fl.querySelector('input[name=username]'))fl.appendChild(e); }
                    if (e) e.src = d.avatar;
                }).catch(function(){});
            }, 1000);
        });
    });
})();

/* ═══ 翻转动画 ═══ */
var fi=document.getElementById('flipInner');
function flip(t){fi.classList.toggle('reg',t==='reg');document.getElementById('tabLogin').classList.toggle('act',t==='login');document.getElementById('tabReg').classList.toggle('act',t==='reg');document.getElementById('tabLogin2').classList.toggle('act',t==='login');document.getElementById('tabReg2').classList.toggle('act',t==='reg');}
history.scrollRestoration='manual';

/* ═══ 推压式面板切换 ═══ */
function pushTo(t){
  var lg=document.querySelector('.push-login'),rg=document.querySelector('.push-register');
  var toReg=t==='reg';
  lg.classList.toggle('pushed',toReg);
  rg.classList.toggle('pushed',toReg);
}

/* ═══ WebAuthn 工具 ═══ */
function b64urlToBuf(s){var bin=atob(s.replace(/-/g,'+').replace(/_/g,'/'));var buf=new ArrayBuffer(bin.length);var v=new Uint8Array(buf);for(var i=0;i<bin.length;i++)v[i]=bin.charCodeAt(i);return buf}
function bufToB64url(buf){var v=new Uint8Array(buf);var bin='';for(var i=0;i<v.length;i++)bin+=String.fromCharCode(v[i]);return btoa(bin).replace(/=/g,'').replace(/\+/g,'-').replace(/\//g,'_')}

/* ═══ 通行密钥登录 ═══ */
function pkErrMsg(e){
  var m={
    'NotAllowedError':'操作已取消或超时',
    'SecurityError':'安全限制：请使用域名或localhost访问，不支持IP地址',
    'TypeError':'请求参数异常，请刷新页面重试',
    'NetworkError':'网络连接失败，请检查网络',
    'AbortError':'操作被中断',
    'UnknownError':'认证器发生未知错误'
  };
  if(m[e.name])return m[e.name];
  if(e.message){
    if(e.message.indexOf('invalid domain')!==-1)return '域名无效：请使用域名或localhost访问，不支持IP地址';
    if(e.message.indexOf('timed out')!==-1)return '操作超时，请重试';
    if(e.message.indexOf('User consent')!==-1||e.message.indexOf('user canceled')!==-1||e.message.indexOf('cancelled')!==-1)return '操作已取消';
    if(e.message.indexOf('not available')!==-1||e.message.indexOf('not supported')!==-1)return '当前浏览器或设备不支持通行密钥';
  }
  return '登录失败：'+(e.message||e.name||'未知错误');
}
function passkeyLogin(){
  fetch('/admin/api/?action=pk-login-begin',{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(d){
    if(d.error){alert(d.error);return}
    if(!d.challenge){alert('获取挑战失败，请刷新重试');return}
    return navigator.credentials.get({publicKey:{challenge:b64urlToBuf(d.challenge),rpId:d.rpId,timeout:d.timeout||120000,userVerification:d.userVerification||'preferred'}});
  }).then(function(cred){
    if(!cred)return;
    var ad=bufToB64url(cred.response.authenticatorData),sig=bufToB64url(cred.response.signature),cjd=bufToB64url(cred.response.clientDataJSON),cid=bufToB64url(cred.rawId);
    var fd=new FormData();fd.append('authenticatorData',ad);fd.append('signature',sig);fd.append('clientDataJSON',cjd);fd.append('credentialId',cid);
    return new Promise(function(r){setTimeout(r,200)}).then(function(){return fetch('/admin/api/?action=pk-login-complete',{method:'POST',body:fd,credentials:'same-origin'})}).then(function(r){return r.json()});
  }).then(function(d){
    if(d&&d.ok)window.location.href='/admin/pages/dashboard/';
    else if(d)alert('登录失败：'+(d.err||'未知错误'));
  }).catch(function(e){
    alert(pkErrMsg(e));
  });
}

/* ═══ 发送验证码 ═══ */
function startCountdown(btn){
  var s=60;btn.disabled=true;
  btn.textContent=s+'s 后重发';
  var t=setInterval(function(){
    s--;
    if(s<=0){clearInterval(t);btn.textContent='发送验证码';btn.disabled=false}
    else{btn.textContent=s+'s 后重发'}
  },1000);
}
function sendCode(){
  var e=document.querySelector('.login-mobile input[name=email]').value.trim();
  if(!e||!e.includes('@')){showToast('请输入有效邮箱','err');return}
  var btn=document.getElementById('sendCodeBtn');btn.textContent='发送中...';btn.disabled=true;
  var f=new FormData();var ci=document.querySelector('input[name=_csrf]');f.append('email',e);f.append('send_code','1');if(ci)f.append('_csrf',ci.value);
  fetch('/admin/api/?action=register',{method:'POST',body:f,credentials:'same-origin'}).then(function(r){
    if(!r.ok)throw Error('HTTP '+r.status);
    return r.json();
  }).then(function(d){
    if(d.ok){showToast('验证码已发送','ok');startCountdown(btn)}
    else{showToast(d.err||'发送失败','err');btn.textContent='发送验证码';btn.disabled=false}
  }).catch(function(e){
    var fd=new FormData();fd.append('name','send_code_err');fd.append('message',e.message||'unknown');
    navigator.sendBeacon('/admin/api/?action=js-log',fd);
    showToast('请求失败','err');btn.textContent='发送验证码';btn.disabled=false
  });
}
function sendCodeD(){
  var e=document.querySelector('.push-register input[name=email]').value.trim();
  if(!e||!e.includes('@')){showToast('请输入有效邮箱','err');return}
  var btn=document.getElementById('sendCodeBtnD');btn.textContent='发送中...';btn.disabled=true;
  var f=new FormData();var ci=document.querySelector('input[name=_csrf]');f.append('email',e);f.append('send_code','1');if(ci)f.append('_csrf',ci.value);
  fetch('/admin/api/?action=register',{method:'POST',body:f,credentials:'same-origin'}).then(function(r){
    if(!r.ok)throw Error('HTTP '+r.status);
    return r.json();
  }).then(function(d){
    if(d.ok){showToast('验证码已发送','ok');startCountdown(btn)}
    else{showToast(d.err||'发送失败','err');btn.textContent='发送验证码';btn.disabled=false}
  }).catch(function(e){
    var fd=new FormData();fd.append('name','sendCodeD_err');fd.append('message',e.message||'unknown');
    navigator.sendBeacon('/admin/api/?action=js-log',fd);
    showToast('请求失败','err');btn.textContent='发送验证码';btn.disabled=false
  });
}

/* ═══ Toast ═══ */
var _toast=document.getElementById('toast'),_tt=null;
function showToast(m,t){_toast.textContent=m;_toast.className='show '+(t||'ok');clearTimeout(_tt);_tt=setTimeout(function(){_toast.className=''},2500)}

/* ═══ 悬浮弹窗 ═══ */
function showModal(msg){
  var d=document.getElementById('modalOverlay');
  if(!d){
    d=document.createElement('div');d.id='modalOverlay';
    d.innerHTML='<div class="modalBox"><p id="modalMsg"></p><button class="btn" onclick="closeModal()">确定</button></div>';
    document.body.appendChild(d);
  }
  d.style.display='flex';
  d.querySelector('#modalMsg').textContent=msg;
}
function closeModal(){document.getElementById('modalOverlay').style.display='none'}