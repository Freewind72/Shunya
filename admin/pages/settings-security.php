<?php defined('MAPI_ADMIN') or die('禁止直接访问');
// settings-security.php — 安全（极验验证码）
if ((($_SESSION['admin_is_admin'] ?? 99) > 1)) { echo '<div class="card"><div class="empty">无权限</div></div>'; return; }

$geetest = ['captcha_id' => '', 'key' => ''];
$r = $db->query("SELECT captcha_id, `key` FROM mapi_geetest LIMIT 1");
if ($r && $row = $r->fetch_assoc()) {
    $geetest['captcha_id'] = $row['captcha_id'] ?? '';
    $geetest['key'] = $row['key'] ?? '';
}
?>
<!-- ═══ 安全 ═══ -->
<div class="section-header"><span class="section-header-inner"><?= svg('shield') ?> 安全</span></div>

<div class="card">
  <div class="card-header"><span class="card-title"><?= svg('srv') ?> 极验验证码</span></div>
  <form method="post" action="?action=settings-security"><input type="hidden" name="_geetest_submit" value="1"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="form-row">
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">captcha_id（应用 ID）</label>
        <input class="form-input" name="geetest_captcha_id" value="<?= htmlspecialchars($geetest['captcha_id']) ?>" placeholder="从极验后台获取的 captcha_id">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">key（密钥）</label>
        <input class="form-input" type="password" name="geetest_key" value="<?= htmlspecialchars($geetest['key']) ?>" placeholder="极验后台的验证密钥">
      </div>
    </div>
    <small style="display:block;margin-top:6px;margin-bottom:12px;color:rgba(0,0,0,.38);font-size:11px">留空则关闭极验验证码。修改后需刷新登录页生效。</small>
    <button class="btn btn-primary btn-block">保存</button>
  </form>
</div>
