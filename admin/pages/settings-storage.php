<?php defined('MAPI_ADMIN') or die('禁止直接访问');
// settings-storage.php — 储存（S3 对象存储）
if ((($_SESSION['admin_is_admin'] ?? 99) > 1)) { echo '<div class="card"><div class="empty">无权限</div></div>'; return; }

$s3 = ['endpoint'=>'','access_key'=>'','secret_key'=>'','bucket'=>'','region'=>'auto','path_prefix'=>'','custom_domain'=>''];
$r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='s3'");
if ($r && $row = $r->fetch_assoc()) {
    $saved = json_decode($row['config_value'], true);
    if (is_array($saved)) $s3 = array_merge($s3, $saved);
}
?>
<?php if (($_SESSION['admin_is_admin'] ?? 99) === 0): ?>
<div class="section-header"><span class="section-header-inner"><?= svg('db') ?> 储存</span></div>
<div class="cards-grid">
<div class="card">
  <div class="card-header"><span class="card-title"><?= svg('upload') ?> S3 存储</span></div>
  <form method="post" action="?action=settings-storage"><input type="hidden" name="_s3_submit" value="1"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="form-group">
      <label class="form-label">Endpoint</label>
      <input class="form-input" name="s3_endpoint" value="<?= htmlspecialchars($s3['endpoint']) ?>" placeholder="https://s3.amazonaws.com 或 https://xxx.r2.cloudflarestorage.com">
      <small style="display:block;margin-top:4px;color:rgba(0,0,0,.38);font-size:11px">S3 兼容服务的 Endpoint 地址</small>
    </div>
    <div class="form-row">
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">Access Key</label>
        <input class="form-input" name="s3_access_key" value="<?= htmlspecialchars($s3['access_key']) ?>" placeholder="AKIA...">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">Secret Key</label>
        <input class="form-input" type="password" name="s3_secret_key" value="<?= htmlspecialchars($s3['secret_key']) ?>" placeholder="留空不修改">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">Bucket</label>
        <input class="form-input" name="s3_bucket" value="<?= htmlspecialchars($s3['bucket']) ?>" placeholder="my-bucket">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">Region</label>
        <input class="form-input" name="s3_region" value="<?= htmlspecialchars($s3['region']) ?>" placeholder="auto（R2 填 auto）">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">路径前缀（可选）</label>
        <input class="form-input" name="s3_path_prefix" value="<?= htmlspecialchars($s3['path_prefix']) ?>" placeholder="mapi/music 或留空">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">自定义域名（可选）</label>
        <input class="form-input" name="s3_custom_domain" value="<?= htmlspecialchars($s3['custom_domain']) ?>" placeholder="https://cdn.example.com">
      </div>
    </div>
    <small style="display:block;margin-top:4px;margin-bottom:0;color:rgba(0,0,0,.38);font-size:11px">留空则使用 Endpoint 拼接，填写后公开 URL 使用此域名</small>
    <button class="btn btn-primary btn-block">保存</button>
  </form>
</div>
</div>
<?php endif; ?>
