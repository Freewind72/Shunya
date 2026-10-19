<?php
// 页面文件引导序列：路由基础设施（非业务板块），除下方 INDEX_ACTION 的值外，
// 16 份页面文件逐字相同。对外地址 /admin/<页面>（无后缀）由根 index.php 路由器经通用桥
// 回退引导（或经 /admin/index.php 裸入口）；直接物理访问本文件时由本引导块声明后台上下文
// 与 action，交给 includes/front.php 跑完整判定链；判定通过后 front.php 会把本文件再载入
// 一趟执行页面主体（此刻 MAPI_ADMIN 已定义，跳过本块），主体恰好执行一次——下方
// return 拦住第一趟继续落到主体。主体内容在引导块之后，判定拦截时不会执行。
if (!defined('MAPI_ADMIN')) {
    define('MAPI_ADMIN', true);
    define('INDEX_ACTION', 'profile');
    require __DIR__ . '/../../includes/front.php';
    return;
}

// profile.php — 个人资料

// 通行密钥列表
$passkeys = [];
$r = $db->query("SELECT id, credential_id, created_at FROM mapi_passkeys WHERE user_id=" . (int)$_SESSION['admin_id'] . " ORDER BY id DESC");
if ($r) while ($row = $r->fetch_assoc()) $passkeys[] = $row;

$bgKey = $_SESSION['admin_background'] ?? '';
$bgUrl = '';
if ($bgKey && function_exists('s3_get_url')) {
    $bgUrl = s3_get_url($bgKey);
}
$uid = (int)$_SESSION['admin_id'];
?>

<div class="cards-grid">
<div class="card">
  <div class="card-header"><span class="card-title">个人资料</span></div>
  <form method="post" action="<?= admin_url('profile') ?>"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="form-row">
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">账号</label>
        <input class="form-input" name="username" value="<?= htmlspecialchars($_SESSION['admin_user']) ?>" required>
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">QQ号</label>
        <input class="form-input" name="qq" value="<?= htmlspecialchars($_SESSION['admin_qq'] ?? '') ?>" placeholder="选填，用于获取头像">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">原密码</label>
        <input class="form-input" type="password" name="old_password" placeholder="修改密码时才需要填写">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">新密码</label>
        <input class="form-input" type="password" name="password" placeholder="不填则不修改密码">
      </div>
    </div>
    <button class="btn btn-primary btn-block">保存</button>
  </form>
</div>

<div class="card pk-card">
  <div class="card-header"><span class="card-title">通行密钥</span></div>
  <?php if (!empty($passkeys)): ?><button class="btn btn-primary btn-block" disabled style="opacity:0.45;pointer-events:none" id="pkRegBtn">绑定通行密钥</button><?php else: ?><button class="btn btn-primary btn-block" onclick="registerPasskey()" id="pkRegBtn">绑定通行密钥</button><?php endif; ?>
  <?php if (empty($passkeys)): ?>
  <div class="empty">暂无绑定设备</div>
  <?php else: foreach ($passkeys as $pk): ?>
  <div class="pk-item">
    <span>密钥 · <?= substr($pk['created_at'],0,10) ?></span>
    <form method="post" action="<?= admin_url('pk-delete') ?>" onsubmit="return showConfirm(event,this,'确认删除此通行密钥？')"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= $pk['id'] ?>">
      <button class="btn-sm danger">删除</button>
    </form>
  </div>
  <?php endforeach; endif; ?>
</div>
</div>

<div class="cards-grid">
<div class="card">
  <div class="card-header"><span class="card-title"><?= svg('img') ?> 后台背景图</span></div>
<?php $adminTheme = $_SESSION['admin_ui_theme'] ?? 'light'; ?>
    <div class="theme-toggle-row" style="display:flex;align-items:center;justify-content:space-between;padding:0 0 16px 0">
      <span style="font-size:13px;font-weight:600;color:rgba(0,0,0,.55)">后台主题</span>
      <button class="theme-switch<?= $adminTheme === 'dark' ? ' active' : '' ?>" id="themeSwitch" onclick="toggleTheme()" title="切换浅色/深色主题">
        <span class="theme-switch-track">
          <span class="theme-switch-thumb"></span>
        </span>
      </button>
    </div>
<div style="margin-bottom:12px;border-radius:8px;overflow:hidden;max-height:160px;display:<?= $bgUrl ? 'block' : 'none' ?>" id="bgPreview">
    <?php if ($bgUrl): ?><img src="<?= htmlspecialchars($bgUrl) ?>" alt="当前背景" style="width:100%;height:auto;display:block;object-fit:cover;max-height:160px"><?php endif; ?>
  </div>
  <div class="form-group">
    <label class="form-label">背景来源</label>
    <div class="bg-source-row">
      <input class="form-input bg-url-input" type="url" id="bgUrlInput" placeholder="输入壁纸链接（图片/视频 URL）" value="<?= htmlspecialchars($_SESSION['admin_background_url'] ?? '') ?>">
      <div class="bg-divider"></div>
      <label class="bg-upload-btn glass" title="上传背景图">
        <?= svg('upload') ?>
        <input type="file" id="bgFileInput" accept="image/*">
      </label>
    </div>
    <small style="display:block;margin-top:4px;color:rgba(0,0,0,.38);font-size:11px">左侧输入动态壁纸 URL（支持图片/视频），右侧上传静态背景图 · 上传自动替换旧图</small>
  </div>
  <div style="display:flex;gap:8px">
    <button class="btn btn-primary" style="flex:1" id="bgUrlSaveBtn" onclick="saveBgUrl()">保存链接</button>
    <button class="btn btn-primary" style="flex:1" id="bgUploadBtn" onclick="uploadBg()">上传背景</button>
  </div>
  <span id="bgStatus" style="display:none;font-size:12px;margin-top:6px;text-align:center"></span>
  <?php if ($bgUrl || ($_SESSION['admin_background_url'] ?? '')): ?>
  <form method="post" action="<?= admin_url('profile') ?>" onsubmit="return showConfirm(event,this,'确认删除背景图？')" style="margin-top:8px"><input type="hidden" name="_bg_delete" value="1"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <button class="btn btn-danger btn-block" style="background:rgba(214,48,49,.08);color:#d63031;border:1px solid rgba(214,48,49,.2)">删除背景</button>
  </form>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-header"><span class="card-title"><?= svg('exit') ?> 退出登录</span></div>
  <a href="<?= admin_url('logout') ?>" class="btn btn-danger btn-block">退出登录</a>
</div>
</div>
