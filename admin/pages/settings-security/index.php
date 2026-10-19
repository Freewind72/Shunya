<?php
// 页面文件引导序列：路由基础设施（非业务板块），除下方 INDEX_ACTION 的值外，
// 16 份页面文件逐字相同。对外地址 /admin/<页面>（无后缀）由根 index.php 路由器经通用桥
// 回退引导（或经 /admin/index.php 裸入口）；直接物理访问本文件时由本引导块声明后台上下文
// 与 action，交给 includes/front.php 跑完整判定链；判定通过后 front.php 会把本文件再载入
// 一趟执行页面主体（此刻 MAPI_ADMIN 已定义，跳过本块），主体恰好执行一次——下方
// return 拦住第一趟继续落到主体。主体内容在引导块之后，判定拦截时不会执行。
if (!defined('MAPI_ADMIN')) {
    define('MAPI_ADMIN', true);
    define('INDEX_ACTION', 'settings-security');
    require __DIR__ . '/../../includes/front.php';
    return;
}

// settings-security.php — 安全（极验验证码）
if ((($_SESSION['admin_is_admin'] ?? 99) > 1)) { echo '<div class="card"><div class="empty">无权限</div></div>'; return; }

$geetest = ['captcha_id' => '', 'key' => ''];
$r = $db->query("SELECT captcha_id, `key` FROM mapi_geetest LIMIT 1");
if ($r && $row = $r->fetch_assoc()) {
    $geetest['captcha_id'] = $row['captcha_id'] ?? '';
    $geetest['key'] = $row['key'] ?? '';
}
// 密钥**掩码回显**：只露末 4 位。真实 key 不进 HTML。
// 保存时"以 • 开头"的值视为**未修改**；「留空则关闭」语义保留（空串 = 显式清空）。
$geetestMask = function (string $v): string {
    if ($v === '') return '';
    return '••••' . mb_substr($v, -4, null, 'UTF-8');
};
?>
<!-- ═══ 安全 ═══ -->
<div class="section-header"><span class="section-header-inner"><?= svg('shield') ?> 安全</span></div>

<div class="card">
  <div class="card-header"><span class="card-title"><?= svg('srv') ?> 极验验证码</span></div>
  <form method="post" action="<?= admin_url('settings-security') ?>"><input type="hidden" name="_geetest_submit" value="1"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="form-row">
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">captcha_id（应用 ID）</label>
        <input class="form-input" name="geetest_captcha_id" value="<?= htmlspecialchars($geetest['captcha_id']) ?>" placeholder="从极验后台获取的 captcha_id">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">key（密钥）</label>
        <input class="form-input" type="password" name="geetest_key" value="<?= htmlspecialchars($geetestMask((string)$geetest['key'])) ?>" placeholder="极验后台的验证密钥" autocomplete="new-password">
      </div>
    </div>
    <small style="display:block;margin-top:6px;margin-bottom:12px;color:rgba(0,0,0,.38);font-size:11px">留空则关闭极验验证码（会同时清空 captcha_id —— 两个都为空才算真的关闭）。修改后需刷新登录页生效。</small>
    <button class="btn btn-primary btn-block">保存</button>
  </form>
</div>
