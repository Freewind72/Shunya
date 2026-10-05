<?php defined('MAPI_ADMIN') or die('禁止直接访问');
// settings-site.php — 站点（公告 / 登录页外观）
if ((($_SESSION['admin_is_admin'] ?? 99) > 1)) { echo '<div class="card"><div class="empty">无权限</div></div>'; return; }

$announcement = ['enabled' => false, 'title' => '', 'content' => '', 'align' => 'left'];
$ra = $db->query("SELECT config_value FROM mapi_config WHERE config_key='announcement'");
if ($ra && $rowa = $ra->fetch_assoc()) {
    $saveda = json_decode($rowa['config_value'], true);
    if (is_array($saveda)) $announcement = array_merge($announcement, $saveda);
}

$loginTheme = 'light'; $loginBg = '';
$r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='login_theme'");
if ($r && $row = $r->fetch_assoc()) $loginTheme = $row['config_value'];
$r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='login_bg'");
if ($r && $row = $r->fetch_assoc()) $loginBg = $row['config_value'];
?>
<!-- ═══ 站点内容 ═══ -->
<div class="section-header"><span class="section-header-inner"><?= svg('img') ?> 站点内容</span></div>

<div class="cards-grid">
<div class="card">
  <div class="card-header"><span class="card-title"><?= svg('srv') ?> 公告</span></div>
  <form method="post" action="?action=settings-site"><input type="hidden" name="_ann_submit" value="1"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="form-group">
      <label class="form-label">公告内容</label>
      <textarea name="announcement" class="form-input" placeholder="留空 = 关闭公告&#10;填写内容并保存 = 发布公告"><?= htmlspecialchars($announcement['content'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <label class="form-label">内容对齐</label>
      <select name="ann_align" class="form-input">
        <option value="left" <?= $announcement['align'] === 'left' ? 'selected' : '' ?>>左对齐</option>
        <option value="center" <?= $announcement['align'] === 'center' ? 'selected' : '' ?>>居中</option>
      </select>
    </div>
    <button class="btn btn-primary btn-block">发布公告</button>
  </form>
</div>

<div class="card">
  <div class="card-header"><span class="card-title"><?= svg('img') ?> 登录页主题与背景</span></div>
  <form method="post" action="?action=settings-site"><input type="hidden" name="_login_submit" value="1"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="form-row">
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">主题模式</label>
        <select name="login_theme" class="form-input">
          <option value="light" <?= $loginTheme === 'light' ? 'selected' : '' ?>>浅色</option>
          <option value="dark" <?= $loginTheme === 'dark' ? 'selected' : '' ?>>深色</option>
        </select>
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">背景图片 URL</label>
        <input class="form-input" name="login_bg" value="<?= htmlspecialchars($loginBg) ?>" placeholder="留空 = 默认浅灰背景">
      </div>
    </div>
    <button class="btn btn-primary btn-block">保存</button>
  </form>
</div>
</div>
