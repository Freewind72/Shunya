<?php defined('MAPI_ADMIN') or die('禁止直接访问');
require __DIR__ . '/../api/relay.php';
// users.php — 人员管理（仅管理员）

// 用户列表
$users = [];
$r = $db->query("SELECT id,username,qq,is_admin,created_at FROM mapi_users WHERE id<>" . (int)$_SESSION['admin_id'] . " ORDER BY id");
if ($r) while ($row = $r->fetch_assoc()) $users[] = $row;

if ((($_SESSION['admin_is_admin'] ?? 99) > 1)) { echo '<div class="card"><div class="empty">无权限</div></div>'; return; }
?>
<div class="card">
  <div class="card-header"><span class="card-title"><?= svg('user') ?> 人员管理</span></div>
  <?php if (empty($users)): ?><div class="empty">暂无用户</div>
  <?php else: ?>
  <div class="data-grid data-grid-2">
  <?php foreach ($users as $u): ?>
  <div class="user-item">
    <div class="user-avatar"><?php if ($u['qq'] ?? ''): ?><img src="<?= $RELAY['avatar']['qq'] ?>?b=qq&nk=<?= (int)$u['qq'] ?>&s=100" alt=""><?php else: ?><?= htmlspecialchars(mb_substr($u['username'],0,1,'UTF-8')) ?><?php endif; ?></div>
    <div class="user-info">
      <div class="user-name"><?= htmlspecialchars($u['username']) ?> <span class="tag <?= $u['is_admin'] == 0 ? 'tag-super' : ($u['is_admin'] == 1 ? 'tag-admin' : 'tag-user') ?>" style="<?= $u['is_admin'] == 0 ? 'background:rgba(255,215,0,.12);color:#c9a840;font-weight:700' : '' ?>"><?= $u['is_admin'] == 0 ? '超级管理员' : ($u['is_admin'] == 1 ? '管理员' : '用户') ?></span><span class="online-dot" data-uid="<?= $u['id'] ?>" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#b2bec3;margin-left:6px;vertical-align:middle" title="离线"></span></div>
      <div class="user-meta">
        <span>QQ: <?= htmlspecialchars($u['qq'] ?? '-') ?></span>
        <span>注册: <?= substr($u['created_at'],0,10) ?></span>
      </div>
    </div>
    <?php if (($_SESSION['admin_is_admin'] ?? 99) <= 1 && ($_SESSION['admin_is_admin'] ?? 99) < (int)$u['is_admin']): ?>
    <div class="user-actions">
      <form method="post" action="?action=user-admin"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="id" value="<?= $u['id'] ?>">
        <input type="hidden" name="is_admin" value="<?= $u['is_admin'] == 0 ? 2 : ($u['is_admin'] == 1 ? 2 : 1) ?>">
        <button class="btn-sm"><?= $u['is_admin'] == 0 ? '取消超级管理' : ($u['is_admin'] == 1 ? '取消管理' : '设为管理') ?></button>
      </form>
      <form method="post" action="?action=user-delete" onsubmit="return showConfirm(event,this,'确认删除用户 <?= htmlspecialchars(addslashes($u['username']), ENT_QUOTES) ?>？')"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="id" value="<?= $u['id'] ?>">
        <button class="btn-sm danger">删除</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<script>
// 通过 Pusher API 获取在线状态
(function(){
  // 方式1：直接用 Pusher 客户端
  function fromClient(){
    if(window.pusherOnlineReady && window.pusherOnlineIds){
      var ok = false;
      document.querySelectorAll('.online-dot').forEach(function(el){
        var uid = el.getAttribute('data-uid');
        if(uid && window.pusherOnlineIds[uid]){ el.style.background='#27ae60'; el.title='在线'; ok = true; }
      });
      if(ok) return true;
    }
    return false;
  }
  // 方式2：走 PHP API（兜底）
  function fromServer(){
    var x = new XMLHttpRequest();
    x.open('GET', '?action=pusher-online-users', true);
    x.onload = function(){
      if(x.status !== 200) return;
      try{
        var users = JSON.parse(x.responseText);
        users.forEach(function(u){
          var el = document.querySelector('.online-dot[data-uid="'+u.id+'"]');
          if(el){ el.style.background='#27ae60'; el.title='在线'; }
        });
      }catch(e){}
    };
    x.send();
  }
  // 先试客户端，1 秒后还不行就走服务端
  if(!fromClient()) {
    setTimeout(function(){
      if(!fromClient()) fromServer();
    }, 2000);
  }
})();
</script>
  <?php endif; ?>
</div>