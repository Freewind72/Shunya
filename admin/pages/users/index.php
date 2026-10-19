<?php
// 页面文件引导序列：路由基础设施（非业务板块），除下方 INDEX_ACTION 的值外，
// 16 份页面文件逐字相同。对外地址 /admin/<页面>（无后缀）由根 index.php 路由器经通用桥
// 回退引导（或经 /admin/index.php 裸入口）；直接物理访问本文件时由本引导块声明后台上下文
// 与 action，交给 includes/front.php 跑完整判定链；判定通过后 front.php 会把本文件再载入
// 一趟执行页面主体（此刻 MAPI_ADMIN 已定义，跳过本块），主体恰好执行一次——下方
// return 拦住第一趟继续落到主体。主体内容在引导块之后，判定拦截时不会执行。
if (!defined('MAPI_ADMIN')) {
    define('MAPI_ADMIN', true);
    define('INDEX_ACTION', 'users');
    require __DIR__ . '/../../includes/front.php';
    return;
}

require __DIR__ . '/../../api/relay.php';
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
      <div class="user-name"><?= htmlspecialchars($u['username']) ?> <span class="tag <?= $u['is_admin'] == 0 ? 'tag-super' : ($u['is_admin'] == 1 ? 'tag-admin' : 'tag-user') ?>" style="<?= $u['is_admin'] == 0 ? 'background:rgba(255,215,0,.12);color:#c9a840;font-weight:700' : '' ?>"><?= $u['is_admin'] == 0 ? '超级管理员' : ($u['is_admin'] == 1 ? '管理员' : '用户') ?></span></div>
      <div class="user-meta">
        <span>QQ: <?= htmlspecialchars($u['qq'] ?? '-') ?></span>
        <span>注册: <?= substr($u['created_at'],0,10) ?></span>
      </div>
    </div>
    <?php if (($_SESSION['admin_is_admin'] ?? 99) <= 1 && ($_SESSION['admin_is_admin'] ?? 99) < (int)$u['is_admin']): ?>
    <div class="user-actions">
      <form method="post" action="<?= admin_url('user-admin') ?>"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="id" value="<?= $u['id'] ?>">
        <input type="hidden" name="is_admin" value="<?= $u['is_admin'] == 0 ? 2 : ($u['is_admin'] == 1 ? 2 : 1) ?>">
        <button class="btn-sm"><?= $u['is_admin'] == 0 ? '取消超级管理' : ($u['is_admin'] == 1 ? '取消管理' : '设为管理') ?></button>
      </form>
      <form method="post" action="<?= admin_url('user-delete') ?>" onsubmit="return showConfirm(event,this,'确认删除用户 <?= htmlspecialchars(addslashes($u['username']), ENT_QUOTES) ?>？')"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="id" value="<?= $u['id'] ?>">
        <button class="btn-sm danger">删除</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
  <?php endif; ?>
</div>
