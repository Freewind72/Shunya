(function(){var m=document.getElementById('mailTemplateModal');if(m&&m.parentElement!==document.body){var s=document.body.querySelector('#mailTemplateModal');if(s)s.remove();document.body.appendChild(m)}})()

function openMailTemplateModal(id){
  var idEl=document.getElementById('tplId'),
      nm=document.getElementById('tplName'),sj=document.getElementById('tplSubject'),
      ht=document.getElementById('tplHtml'),title=document.getElementById('tplModalTitle'),
      ta=document.querySelector('textarea[name="mail_tpl_body"]');
  var modal=document.getElementById('mailTemplateModal');
  if(modal&&modal.parentElement!==document.body){var s=document.body.querySelector('#mailTemplateModal');if(s)s.remove();document.body.appendChild(modal)}

  if(id>0){
    var card=document.getElementById('tplItem'+id);
    if(card){
      nm.value=card.getAttribute('data-name')||'';
      sj.value=card.getAttribute('data-subject')||'';
      var bodyEl=card.querySelector('.tpl-body-data');
      var body=bodyEl?bodyEl.value:'';
      ht.value=card.getAttribute('data-html')||'0';
      idEl.value=id;
      title.textContent='编辑邮件模板';
      if(ta&&ta.nextSibling&&ta.nextSibling.CodeMirror){ta.nextSibling.CodeMirror.setValue(body)}
      else if(ta){ta.value=body}
    }
  }else{
    nm.value='';sj.value='';ht.value='0';idEl.value='0';
    title.textContent='新建邮件模板';
    if(ta&&ta.nextSibling&&ta.nextSibling.CodeMirror){ta.nextSibling.CodeMirror.setValue('')}
    else if(ta){ta.value=''}
  }

  modal.style.display='flex';
  if(typeof initCodeMirror==='function')initCodeMirror();
  if(ta&&ta.nextSibling&&ta.nextSibling.CodeMirror){ta.nextSibling.CodeMirror.refresh()}
}

function closeMailTemplateModal(){document.getElementById('mailTemplateModal').style.display='none'}

if(!window.__mailEscBound){window.__mailEscBound=1;document.addEventListener('keydown',function(e){if(e.key==='Escape'){var m=document.getElementById('mailTemplateModal');if(m&&m.style.display==='flex')closeMailTemplateModal()}});}

function handleMailTemplateSubmit(e){
  e.preventDefault();e.stopPropagation();
  var f=e.target,btn=document.querySelector('.tpl-modal-footer [type="submit"]'),orig=btn.textContent;
  var ta=document.querySelector('textarea[name="mail_tpl_body"]');
  if(ta&&ta.nextSibling&&ta.nextSibling.CodeMirror){ta.nextSibling.CodeMirror.save()}
  btn.disabled=true;btn.textContent='保存中…';
  var fd=new FormData(f);
  fetch(f.action,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){return r.json()})
    .then(function(res){
      btn.disabled=false;btn.textContent=orig;
      if(res.ok){
        if(typeof showToast==='function')showToast('邮件模板已保存','ok');closeMailTemplateModal();
        // 名字优先用服务端回传的：服务端可能已把空名字兜底成「未命名模板」，
        // 用 DOM 里的空值去回显就会与库里不一致。
        var tplId=parseInt(document.getElementById('tplId').value)||parseInt(res.id)||0;
        var nm=(res.name||document.getElementById('tplName').value||'').trim()||'未命名模板';
        var sj=(res.subject||document.getElementById('tplSubject').value||'').trim()||'未设置';
        var bd=document.getElementById('tplBody').value;
        var ht=parseInt(document.getElementById('tplHtml').value)||0;
        var preview=bd.replace(/<[^>]*>/g,'');
        if(preview.length>60)preview=preview.substring(0,60)+'\u2026';
        if(!preview)preview='空正文';
        // ★ 没有有效 id 就不要造卡片：updateTplCard(0,…) 会生成 id="tplItem0" 的**幽灵卡片**，
        //   名字还可能取到空值 → 用户看到的就是"保存后多了个未命名模板、还删不干净"。
        //   这种情况下交给服务端重新渲染列表，保证与库一致。
        if(tplId>0){
          updateTplCard(tplId,nm,sj,preview,ht,bd)
        }else{
          if(typeof showToast==='function')showToast('模板已保存，但服务端未返回 id，正在刷新列表','err');
          if(typeof navigateTo==='function')navigateTo(location.href,false);else location.reload();
        }
      }else{if(typeof showToast==='function')showToast(res.error||'保存失败','err')}
    })
    .catch(function(e){btn.disabled=false;btn.textContent=orig;if(typeof showToast==='function')showToast(failMsg(e),'err')})
  return false
}

function updateTplCard(id,name,subject,preview,isHtml,body){
  var card=document.getElementById('tplItem'+id);
  if(!card){
    var grid=document.querySelector('.tpl-list-grid');
    if(!grid){
      var wrap=document.getElementById('tplCardBody');
      var empty=wrap.querySelector('.empty');
      if(empty)empty.remove();
      grid=document.createElement('div');grid.className='tpl-list-grid';wrap.insertBefore(grid,wrap.lastElementChild)
    }
    card=document.createElement('div');card.className='tpl-item';card.id='tplItem'+id;
    grid.appendChild(card)
  }
  card.setAttribute('data-name',name);card.setAttribute('data-subject',subject);card.setAttribute('data-html',isHtml?'1':'0');
  var isDef=card.classList.contains('tpl-item-default');
  var defHtml='<span class="tpl-badge-fmt">'+(isHtml?'HTML':'纯文本')+'</span>'+(isDef?'<span class="tpl-badge-def">默认</span>':'');
  card.innerHTML='<div class="tpl-item-top">'
    +'<span class="tpl-item-name">'+escapeHtml(name)+'</span>'
    +defHtml
    +'</div>'
    +'<div class="tpl-item-subject">主题：'+escapeHtml(subject||'未设置')+'</div>'
    +'<div class="tpl-item-preview">'+escapeHtml(preview)+'</div>'
    +'<textarea class="tpl-body-data" style="display:none">'+escapeHtml(body||'')+'</textarea>'
    +'<div class="tpl-item-actions">'
    +'<button class="btn btn-sm btn-outline" onclick="openMailTemplateModal('+id+')">编辑</button>'
    +(isDef?'':'<button class="btn btn-sm btn-outline" onclick="setDefaultTemplate('+id+')">设默认</button>')
    +'<button class="btn btn-sm btn-outline btn-danger-outline" onclick="deleteTemplate('+id+')">删除</button>'
    +'</div>'
}

function escapeHtml(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML}

function deleteTemplate(id){
  if(!confirm('确认删除该邮件模板？'))return;
  var csrfEl=document.querySelector('input[name="_csrf"]');
  var fd=new FormData();fd.append('_mail_tpl_delete','1');fd.append('_mail_tpl_id',id);
  if(csrfEl)fd.append('_csrf',csrfEl.value);
  fetch('/admin/pages/settings-mail/',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){return r.json()})
    .then(function(res){
      if(res.ok){
        if(typeof showToast==='function')showToast('模板已删除','ok');
        var card=document.getElementById('tplItem'+id);if(card)card.remove();
        var grid=document.querySelector('.tpl-list-grid');
        if(grid&&!grid.querySelector('.tpl-item')){
          var wrap=document.getElementById('tplCardBody');
          grid.remove();
          var empty=document.createElement('div');empty.className='empty';empty.textContent='暂无邮件模板';
          wrap.insertBefore(empty,wrap.lastElementChild)
        }
      }else{if(typeof showToast==='function')showToast('删除失败','err')}
    })
    .catch(function(e){if(typeof showToast==='function')showToast(failMsg(e),'err')})
}

function setDefaultTemplate(id){
  var csrfEl=document.querySelector('input[name="_csrf"]');
  var fd=new FormData();fd.append('_mail_tpl_default','1');fd.append('_mail_tpl_id',id);
  if(csrfEl)fd.append('_csrf',csrfEl.value);
  fetch('/admin/pages/settings-mail/',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){return r.json()})
    .then(function(res){
      if(res.ok){
        if(typeof showToast==='function')showToast('已设为默认模板','ok');
        var items=document.querySelectorAll('.tpl-item');
        for(var i=0;i<items.length;i++){
          var item=items[i];item.classList.remove('tpl-item-default');
          var defBadge=item.querySelector('.tpl-badge-def');if(defBadge)defBadge.remove();
          var act=item.querySelector('.tpl-item-actions');
          if(act){
            var setBtn=act.querySelector('button:nth-child(2)');
            if(setBtn&&setBtn.textContent.includes('设默认'))setBtn.remove();
            var editBtn=act.querySelector('button:nth-child(1)');
            if(editBtn){
              var itemId=parseInt(item.id.replace('tplItem',''));
              if(itemId===id){
                editBtn.insertAdjacentHTML('afterend','<button class="btn btn-sm btn-outline" onclick="setDefaultTemplate('+itemId+')">设默认</button>')
              }
            }
          }
        }
        var target=document.getElementById('tplItem'+id);if(target){
          target.classList.add('tpl-item-default');
          var top=target.querySelector('.tpl-item-top');if(top){
            var fmt=top.querySelector('.tpl-badge-fmt');if(fmt)fmt.insertAdjacentHTML('afterend','<span class="tpl-badge-def">默认</span>')
          }
          var act=target.querySelector('.tpl-item-actions');if(act){
            var setBtn=act.querySelector('button:nth-child(2)');if(setBtn&&setBtn.textContent.includes('设默认'))setBtn.remove()
          }
        }
      }else{if(typeof showToast==='function')showToast('设置失败','err')}
    })
    .catch(function(e){if(typeof showToast==='function')showToast(failMsg(e),'err')})
}

/* ── 封面本地化（设置 → 储存）：手动开关 + 分批迁移 / 预热 ──
   一次请求只处理一批（服务端每批 10 首），前端轮询推进 —— 100 首也不会顶到 PHP 超时；
   中断/刷新后再点一次即可接着跑（服务端每次都重新查「还缺哪些」）。 */
(function(){
  var box=document.getElementById('coverLocalBox');
  if(!box)return;
  var csrf=box.getAttribute('data-csrf')||'',s3ok=box.getAttribute('data-s3')==='1';
  var sw=document.getElementById('coverLocalSwitch'),
      migBtn=document.getElementById('coverMigrateBtn'),
      warmBtn=document.getElementById('coverWarmAllBtn'),
      refBtn=document.getElementById('coverRefreshBtn'),
      st=document.getElementById('coverLocalStatus'),
      bar=document.getElementById('coverLocalBar'),
      barIn=document.getElementById('coverLocalBarIn'),
      cards=document.getElementById('coverLocalCards'),
      gcBtn=document.getElementById('coverGcBtn'),
      purgeBtn=document.getElementById('coverPurgeBtn'),
      gcSt=document.getElementById('coverGcStatus');

  // 提交到封面本地化端点。
  // ① 动作统一走单一入口 /admin/api/?action=<动作>（URL 形态由服务端 lib/url.php 单点决定；
  //    JS 这边按既定约定显式写全路径，不依赖任何全局助手 —— login.js 就不加载 base.js）。
  // ② 不再无条件 r.json()：非 JSON 响应（登录页 / 报错页）解析必抛，被 catch 统称"网络错误"，
  //    真实原因（HTTP 状态、返回了什么）全被掩盖。现在先取文本，明确报告。
  function post(action,data,timeoutMs){
    var payload={_csrf:csrf};
    for(var k in (data||{}))payload[k]=data[k];
    var url='/admin/api/?action='+encodeURIComponent(String(action).replace(/\/+$/,''));
    // 超时兜底：会话锁 / 存储黑洞会让请求**永不返回**。没有这一层时 refresh() 里的 stBusy 永远为真，
    // 状态就再也刷不出来 —— 页面上那行占位文案「· 回收池读取中…」会永久停留（用户看到的就是"卡死"）。
    var ctl=(typeof AbortController!=='undefined')?new AbortController():null;
    var timer=(ctl&&timeoutMs)?setTimeout(function(){try{ctl.abort()}catch(e){}},timeoutMs):0;
    function done(){if(timer)clearTimeout(timer)}
    return fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload),signal:ctl?ctl.signal:undefined})
      .then(function(r){
        return r.text().then(function(t){
          var j=null;try{j=JSON.parse(t)}catch(e){}
          if(j)return j;
          throw new Error('HTTP '+r.status+'：返回的不是 JSON'
            +(r.redirected?'（被重定向到 '+r.url+'，多半是登录态已失效）':'')
            +' · '+String(t).replace(/\s+/g,' ').slice(0,120));
        });
      })
      .then(function(j){done();return j})
      .catch(function(e){
        done();
        if(ctl&&e&&e.name==='AbortError')throw new Error('请求超时（'+Math.round(timeoutMs/1000)+'s 无响应）');
        throw e;
      });
  }
  // 把异常翻译成用户能看懂、且能据此排查的提示：
  // 网络层失败（fetch reject）说"网络"；服务端返回了东西但不是 JSON（登录页/报错页）说清状态码与来源。
  function failMsg(e){
    var m=(e&&e.message)||'';
    if(!m)return '网络错误';
    if(/Failed to fetch|NetworkError|load failed|ERR_/i.test(m))return '网络错误：请求没有发出去或连接被中断';
    return '操作失败 · '+m;
  }

  function toast(msg,ok){if(typeof showToast==='function')showToast(msg,ok?'ok':'err')}
  function busy(btn,on,text){
    if(!btn)return;
    if(on){if(!btn.getAttribute('data-old'))btn.setAttribute('data-old',btn.textContent);btn.textContent=text;btn.disabled=true}
    else{var o=btn.getAttribute('data-old');if(o)btn.textContent=o;btn.disabled=false}
  }
  function progress(pct){
    if(!bar)return;
    if(pct===null){bar.style.display='none';return}
    bar.style.display='block';
    if(barIn)barIn.style.width=Math.max(0,Math.min(100,pct))+'%';
  }
  function fmtBytes(n){n=Number(n)||0;if(n<1024)return n+' B';if(n<1048576)return (n/1024).toFixed(1)+' KB';return (n/1048576).toFixed(2)+' MB'}
  function renderGc(g){
    // 占位文案必须**一定**被替换掉：以前 `if(!g||!gcSt)return` 在服务端没回 gc 字段时直接返回，
    // 页面上就永远停着「· 回收池读取中…」，看着就是卡死。取不到就如实说明，别留悬念。
    if(!gcSt)return;
    if(!g){gcSt.textContent='· 回收池状态未知（点「刷新」重试）';return}
    if(g.orphans||g.index_orphans){
      gcSt.innerHTML='· 回收池 <b>'+g.orphans+'</b> 个（'+fmtBytes(g.orphan_bytes)+'）'
        +(g.reclaimable?' · 可回收 '+g.reclaimable:'');
    }else{
      gcSt.textContent='· 回收池空';
    }
  }
  function render(d){
    var total=0,done=0,pls=d.playlists||[],i;
    for(i=0;i<pls.length;i++){total+=pls[i].total;done+=pls[i].done}
    var mg=d.migrate||{total:0,done:0};
    if(st)st.innerHTML='已本地化 <b>'+done+'</b>/'+total+' · 待迁移 <b>'+mg.total+'</b>';
    if(cards){
      // 歌单卡片：按最新状态更新。两条纪律（"检测不稳"就是这里造成的）：
      //   ① **不删卡**：服务端只返回"有歌的歌单"，被跳过的那种先隐藏、等它有歌了再显示 ——
      //      以前直接 remove，等它再出现时只能 append 到末尾，看着就是卡片乱跳。
      //   ② **始终按服务端顺序排列**：顺序有变化才动 DOM，避免无意义重排。
      var order=[];
      pls.forEach(function(p){
        var pid=String(p.id),el=cards.querySelector('.cover-pl-card[data-pid="'+pid+'"]');
        if(!el){
          el=document.createElement('div');
          el.className='cover-pl-card';
          el.setAttribute('data-pid',pid);
          el.innerHTML='<div class="cover-pl-head"><span class="cover-pl-name"></span><span class="cover-pl-num"></span></div>'
                     +'<div class="cover-pl-bar"><i></i></div>';
          cards.appendChild(el);
        }
        el.removeAttribute('data-empty');
        el.style.display='';
        var full=p.total>0&&p.done>=p.total;
        var q=function(sel){return el.querySelector(sel)};
        if(q('.cover-pl-name'))q('.cover-pl-name').textContent=p.name||('歌单'+p.id);
        // 本地还没有歌（新加的歌单在播放器同步前就是这样）：如实显示，别让人以为"这歌单没了"
        var empty=(parseInt(p.total,10)||0)<=0;
        if(q('.cover-pl-num'))q('.cover-pl-num').textContent=empty?'本地还没有歌':(p.done+'/'+p.total+(full?' \u2713':''));
        if(q('.cover-pl-bar > i'))q('.cover-pl-bar > i').style.width=(p.total?Math.round(p.done/p.total*100):0)+'%';
        el.classList.toggle('done',!!full);
        el.classList.toggle('empty',empty);
        el.title=empty?'本地还没有这个歌单的歌曲：在播放器里打开一次歌单（或在歌单管理里进它的详情页）就会同步进来，之后这里就能预热封面':'已本地化 '+p.done+'/'+p.total;
        order.push(pid);
      });
      var all=[].slice.call(cards.querySelectorAll('.cover-pl-card'));
      all.forEach(function(el){
        if(order.indexOf(el.getAttribute('data-pid'))<0){el.setAttribute('data-empty','1');el.style.display='none'}
      });
      var shown=all.filter(function(el){return !el.hasAttribute('data-empty')})
                   .map(function(el){return el.getAttribute('data-pid')});
      if(shown.join(',')!==order.join(',')){
        order.forEach(function(pid){
          var el=cards.querySelector('.cover-pl-card[data-pid="'+pid+'"]');
          if(el)cards.appendChild(el);
        });
      }
    }
    renderGc(d.gc);
    if(migBtn)migBtn.disabled=!s3ok||mg.total===0;
    if(warmBtn)warmBtn.disabled=!s3ok||!d.enabled;
    if(sw)sw.checked=!!d.enabled;
  }
  // 状态刷新：**同一时刻只发一个、只认最新结果**。
  // 预热/清理过程中每一步都会 refresh，加上手动点"刷新"/拨开关 —— 早先这些请求会并发，
  // 旧响应晚到就把新数字覆盖回去，表现就是数字来回跳（"检测不稳"）。现在：
  // 正在飞的时候把请求合并（stAgain），返回后补一次；序号保证旧响应永不覆盖新结果。
  var stBusy=false,stAgain=false,stSeq=0,stApplied=0;
  function refresh(){
    if(stBusy){stAgain=true;return Promise.resolve(null)}
    stBusy=true;
    var my=++stSeq;
    // 20s 超时：状态查询是纯读、正常在毫秒级；卡住只可能是会话锁或存储黑洞。
    // 超时后 stBusy 会被复位（catch → 后面的 then），占位文案也会被 renderGc 换成明确提示。
    return post('cover-cache-status',{},20000).then(function(d){
      if(my>stApplied){stApplied=my;if(d&&d.ok)render(d)}
      return d;
    }).catch(function(){return null}).then(function(d){
      stBusy=false;
      if(stAgain){stAgain=false;return refresh()}
      return d;
    });
  }

  function loop(action,data,label,btn){
    var guard=0;
    function step(){
      if(guard++>500){busy(btn,false);return}
      // 每批 60s 超时：服务端每批最多 10 首、每首一次上游抓取，正常远低于此；
      // 超时说明这一批卡住了 —— 必须放开按钮，否则整条预热链永久停在「预热中…」。
      post(action,data,60000).then(function(d){
        if(!d||!d.ok){busy(btn,false);toast((d&&d.error)||label+'失败',false);return}
        if(typeof d.total==='number')progress(d.total?d.done/d.total*100:100);
        refresh().then(function(){
          if(d.finished){busy(btn,false);progress(null);toast(label+'完成（成功 '+d.succeeded+'）',true);return}
          step();
        });
      }).catch(function(e){busy(btn,false);toast(failMsg(e),false)});
    }
    busy(btn,true,label+'中…');
    step();
  }

  if(sw)sw.addEventListener('change',function(){
    var on=sw.checked;sw.disabled=true;
    post('cover-local-toggle',{on:on?1:0}).then(function(d){
      sw.disabled=false;
      if(!d||!d.ok){sw.checked=!on;toast((d&&d.error)||'设置失败',false);return}
      toast(on?'封面本地化已开启':'封面本地化已关闭',true);
      refresh();
    }).catch(function(e){sw.disabled=false;sw.checked=!on;toast(failMsg(e),false)});
  });
  // 「清空回收池」：把孤儿对象**立刻**真删，不等默认 7 天保留期 —— 与「清理无用」的区别只有保留期，
  // 判据一样严（refs=0 且已判孤儿）；**有引用的对象服务端一个都不碰**。
  // 破坏性更强（S3 删除不可恢复）⇒ ① 先 dry-run 报清单与字节数，② 二次确认里明说"真删、不可恢复"，
  // ③ 删除前服务端还会逐对象复查一次 refs。
  if(purgeBtn)purgeBtn.addEventListener('click',function(){
    busy(purgeBtn,true,'检查中…');
    post('cover-gc-purge',{dry_run:1},90000).then(function(d){
      busy(purgeBtn,false);
      if(!d||!d.ok){toast((d&&d.error)||'检查失败',false);return}
      var n=Number(d.count)||0;
      if(!n){toast('回收池已经是空的，没有可清空的对象',true);refresh();return}
      var ask='将从存储**真正删除** '+n+' 个孤儿对象（'+fmtBytes(d.bytes)+'）。\n\n'
             +'这一步不可恢复：删除后这些图片不再存在于对象存储里，用户再打开相关歌单会重新回源抓取。\n'
             +'只删「没有任何歌曲引用」的对象，还有引用的一个都不会动。\n\n确定要清空吗？';
      if(!window.confirm(ask))return;
      busy(purgeBtn,true,'清空中…');
      post('cover-gc-purge',{},180000).then(function(r){
        busy(purgeBtn,false);
        if(!r||!r.ok){toast((r&&r.error)||'清空失败',false);return}
        var msg='已删除 '+(r.objects_removed||0)+' 个对象（'+fmtBytes(r.bytes_freed)+'）';
        if(r.objects_failed)msg+='，'+(r.objects_failed)+' 个失败';
        if(r.skipped_referenced)msg+='，'+(r.skipped_referenced)+' 个因仍有引用被跳过';
        if(r.truncated)msg+='（到时间预算，余下的再点一次接着清）';
        toast(msg,r.objects_failed?false:true);
        // 失败必须逐条看得见（不许静默跳过）：把服务端给的 "对象key: 原因" 原样摊在状态行上。
        if(r.objects_failed&&r.errors&&r.errors.length&&gcSt){
          gcSt.textContent='· 有 '+(r.objects_failed)+' 个删除失败：'+r.errors.join('；');
        }
        refresh();
      }).catch(function(e){busy(purgeBtn,false);toast(failMsg(e),false)});
    }).catch(function(e){busy(purgeBtn,false);toast(failMsg(e),false)});
  });
  if(refBtn)refBtn.addEventListener('click',function(){refresh().then(function(){toast('状态已刷新',true)})});
  if(migBtn)migBtn.addEventListener('click',function(){loop('cover-migrate-run',{limit:10},'迁移',migBtn)});
  // 清理无用封面：先干跑看清单 → 用户确认 → 再真删（保留期内的孤儿不动）
  if(gcBtn)gcBtn.addEventListener('click',function(){
    busy(gcBtn,true,'检查中…');
    // 干跑也要超时：存储黑洞时服务端会一直等 —— 没有超时按钮就永久停在「检查中…」。
    post('cover-gc-run',{days:7,dry_run:1},90000).then(function(d){
      busy(gcBtn,false);
      if(!d||!d.ok){toast((d&&d.error)||'检查失败',false);return}
      var n=(d.objects_removed||0)+(d.index_orphans||0);
      if(!n){toast('没有可回收的东西',true);refresh();return}
      var ask='将回收 '+d.objects_removed+' 个封面对象（'+fmtBytes(d.bytes_freed)+'）'
             +(d.index_orphans?'，并清理 '+d.index_orphans+' 行无主索引':'')+'。\n\n'
             +'保留期（7 天）内的孤儿不会删 —— 歌单删了又加回来时，同一张图还能直接复用。\n确定执行？';
      if(!window.confirm(ask))return;
      busy(gcBtn,true,'清理中…');
      // 真删：服务端有 45s 墙钟预算，这里给到 120s（含排队/传输），超时就如实报错并放开按钮
      post('cover-gc-run',{days:7},120000).then(function(r){
        busy(gcBtn,false);
        if(!r||!r.ok){toast((r&&r.error)||'清理失败',false);return}
        toast('已回收 '+r.objects_removed+' 个对象（'+fmtBytes(r.bytes_freed)+'）'+(r.objects_failed?'，'+r.objects_failed+' 个失败待重试':'')+(r.truncated?'（到时间预算，余下的下轮再清）':''),true);
        refresh();
      }).catch(function(e){busy(gcBtn,false);toast(failMsg(e),false)});
    }).catch(function(e){busy(gcBtn,false);toast(failMsg(e),false)});
  });
  if(warmBtn)warmBtn.addEventListener('click',function(){
    // 歌单列表**实时取**（不是页面渲染时的快照）：页面开着的时候新加的歌单也要能预热。
    // 拿不到接口数据时退回页面快照，保证老环境也能用。
    var snapshot=[];
    try{snapshot=JSON.parse((document.getElementById('coverLocalPlaylists')||{}).textContent||'[]')}catch(e){snapshot=[]}
    busy(warmBtn,true,'预热中…');
    post('cover-cache-status',{}).catch(function(){return null}).then(function(d){
      var list=(d&&d.ok&&d.playlists&&d.playlists.length)?d.playlists:snapshot;
      // 本地还没有歌的歌单没东西可预热，跳过（卡片上会显示"本地还没有歌"）
      list=list.filter(function(p){return (parseInt(p.total,10)||0)>0});
      if(!list.length){busy(warmBtn,false);toast('没有需要预热的歌单（本地还没有歌曲）',false);return}
      var pls=list,i=0;
      function nextPl(){
        if(i>=pls.length){busy(warmBtn,false);progress(null);toast('全部歌单预热完成',true);refresh();return}
        var pl=pls[i++],prevDone=-1,idle=0;
        (function run(){
          post('cover-cache-run',{pid:pl.id,limit:10}).then(function(d){
            if(!d||!d.ok){toast('歌单「'+pl.name+'」：'+((d&&d.error)||'失败'),false);nextPl();return}
            if(typeof d.total==='number')progress(d.total?d.done/d.total*100:100);
            if(d.finished){refresh().then(nextPl);return}
            // 连续几轮没有任何进展 = 这几首封面抓不到（源站没有/太大压不动），
            // 跳过并说清楚 —— 否则会一直重试同一批，看起来就是「预热卡住不动了」
            if(d.done===prevDone){ if(++idle>=3){toast('歌单「'+pl.name+'」有 '+Math.max(0,d.total-d.done)+' 首封面抓不到，先跳过',false);nextPl();return} }
            else idle=0;
            prevDone=d.done;
            run();
          }).catch(function(){toast('歌单「'+pl.name+'」请求失败，已跳过（可再点一次续跑）',false);nextPl()});
        })();
      }
      nextPl();
    });
  });
  refresh();
})();

/* ── Redis 卡片的提交标记（按项目惯例用隐藏字段，不依赖全局 AJAX 拦截器是否带上按钮名）──
   new FormData(form) 不含提交按钮的 name/value；见 base.js 里的同一条说明。
   点击时把标记补进表单，老 JS / 缓存页面 / 直连提交都成立。 */
(function(){
  var host=document.querySelector('input[name="redis_host"]');
  var form=host&&host.form?host.form:null;
  if(!form)return;
  function mark(name){
    var old=form.querySelector('input[data-submit-mark="'+name+'"]');
    if(old)old.parentNode.removeChild(old);
    var i=document.createElement('input');
    i.type='hidden';i.name=name;i.value='1';i.setAttribute('data-submit-mark',name);
    form.appendChild(i);
  }
  var save=form.querySelector('button[name="_redis_submit"]');
  var test=form.querySelector('button[name="_redis_test"]');
  if(save)save.addEventListener('click',function(){mark('_redis_submit')});
  if(test)test.addEventListener('click',function(){mark('_redis_test')});
})();