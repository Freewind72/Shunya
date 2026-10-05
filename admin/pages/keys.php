<?php defined('MAPI_ADMIN') or die('禁止直接访问');
// keys.php — 密钥管理

// 密钥列表（含用户信息）
$keys = [];
if (($_SESSION['admin_is_admin'] ?? 99) === 0) {
    $r = $db->query("SELECT k.*, u.username FROM mapi_keys k LEFT JOIN mapi_users u ON k.user_id=u.id ORDER BY k.id DESC");
} else {
    $r = $db->query("SELECT k.*, u.username FROM mapi_keys k LEFT JOIN mapi_users u ON k.user_id=u.id WHERE k.user_id=" . (int)$_SESSION['admin_id'] . " ORDER BY k.id DESC");
}
if ($r) while ($row = $r->fetch_assoc()) $keys[] = $row;

// 歌单分组数据
$playlistsByKey = [];
$r = $db->query("SELECT id, key_id, name, type, remote_id, server, cover_url, cover_mode, sort_order FROM mapi_playlists ORDER BY sort_order ASC, id ASC");
if ($r) while ($row = $r->fetch_assoc()) {
    $kid = (int)$row['key_id'];
    if (!isset($playlistsByKey[$kid])) $playlistsByKey[$kid] = [];
    $playlistsByKey[$kid][] = $row;
}

$isAdmin = $_SESSION['admin_is_admin'] ?? 99;
$keyCount = count($keys);

$keyLimit = 0;
if ($isAdmin > 1) {
    $keyLimit = 1;
    $r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='key_limit'");
    if ($r && $row = $r->fetch_assoc()) {
        $cfg = json_decode($row['config_value'], true);
        if (is_array($cfg) && isset($cfg['limit'])) $keyLimit = max(1, (int)$cfg['limit']);
    }
    $canCreate = $keyCount < $keyLimit;
}
?>
<div class="card">
  <div class="card-header">
    <span class="card-title"><?= svg('key') ?> API 密钥<?= $isAdmin <= 1 ? ' <small style="font-weight:400;color:rgba(0,0,0,.38)">' . $keyCount . ' 个</small>' : '' ?></span>
    <?php if ($isAdmin > 1): ?><span style="font-size:12px;color:rgba(0,0,0,.38);margin-right:auto">已创建 <?= $keyCount ?> / 上限 <?= $keyLimit ?></span><?php endif; ?>
    <?php if ($isAdmin <= 1 || $canCreate): ?>
    <button type="button" class="btn-sm" onclick="openCreateKeyModal()"><?= svg('plus') ?> 创建密钥</button>
    <?php endif; ?>
  </div>
  <?php if (empty($keys)): ?><div class="empty">暂无密钥，点击右上角创建</div>
  <?php else: ?>
  <div class="data-grid data-grid-2">
  <?php foreach ($keys as $key): ?>
  <div class="data-item" data-api-key="<?= htmlspecialchars($key['api_key']) ?>">
    <div class="data-body">
      <div class="data-main">
        <button class="btn-copy" onclick="var k=this.closest('.data-item').dataset.apiKey;navigator.clipboard.writeText(k);var org=this.innerHTML;this.innerHTML='<svg viewBox=&quot;0 0 24 24&quot; fill=&quot;none&quot; stroke=&quot;currentColor&quot; stroke-width=&quot;2&quot;><polyline points=&quot;20 6 9 17 4 12&quot;/></svg>';setTimeout(function(){this.innerHTML=org},1500)"><?= svg('copy') ?></button>
        <?php $__kname = trim((string)($key['name'] ?? '')); ?>
        <span class="data-label"><?= htmlspecialchars($__kname !== '' ? $__kname : ($key['username'] ?? '未绑定')) ?></span>
        <?php if ($__kname !== '' && !empty($key['username'])): ?><span class="key-owner"><?= htmlspecialchars($key['username']) ?></span><?php endif; ?>
        <span class="tag <?= $key['status'] ? 'tag-ok' : 'tag-off' ?>"><?= $key['status'] ? '启用' : '禁用' ?></span>
      </div>
      <div class="data-code"><?= htmlspecialchars(mask_key($key['api_key'])) ?></div>
      <div class="data-meta">
        <?php $__kdom = trim((string)($key['domain'] ?? '')); ?>
        <?php if ($__kdom !== ''): ?><span class="key-domain" title="这条密钥只授权给该主机名">域名 <?= htmlspecialchars($__kdom) ?></span><?php endif; ?>
        <span>歌单 <?php $kid = (int)$key['id']; $pls = $playlistsByKey[$kid] ?? []; echo count($pls) > 0 ? count($pls) . ' 个' : '未设置'; ?></span>
        <span><?= substr($key['created_at'],0,10) ?></span>
      </div>
    </div>
    <div class="data-actions">
      <button class="btn-sm btn-test-load" data-key="<?= htmlspecialchars($key['api_key']) ?>" onclick="toggleTestPlayer(this.dataset.key)"><?= svg('play') ?> 加载</button>
      <button class="btn-sm" data-key="<?= htmlspecialchars($key['api_key']) ?>" onclick="copyEmbedKey(this.dataset.key);showToast('嵌入代码已复制','ok')"><?= svg('copy') ?> 嵌入</button>
      <form method="post" action="?action=keys-delete" onsubmit="return confirm('确认删除此密钥？')"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= $key['id'] ?>">
        <button class="btn-sm danger"><?= svg('trash') ?> 删除</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- 创建密钥弹窗：用公共层的 .song-modal（样式在 base.css，与歌单/配置页弹窗同一套），交互由 keys.js 按 config.js 的约定驱动 -->
<div id="createKeyModal" class="song-modal" style="display:none">
  <div class="song-modal-backdrop"></div>
  <div class="song-modal-panel" style="max-width:420px">
    <div class="song-modal-header">
      <span class="song-modal-title">创建密钥</span>
      <button type="button" class="song-modal-close">✕</button>
    </div>
    <form class="song-modal-body" id="createKeyForm" action="?action=keys-create" method="post" onsubmit="return handleCreateKeySubmit(event)">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <div class="form-group">
        <label class="form-label">密钥名称</label>
        <input class="form-input" name="name" id="createKeyName" maxlength="32" autocomplete="off" placeholder="如：Zeph 小屋、客户站 A">
        <small class="key-field-hint">必填，最多 32 字。一个站/一个用途配一条密钥时，用它区分。</small>
      </div>
      <div class="form-group">
        <label class="form-label">授权域名</label>
        <input class="form-input" name="domain" id="createKeyDomain" autocomplete="off" placeholder="如：blog.example.com">
        <small class="key-field-hint">只填主机名（不带 http:// 与路径，会自动小写）。填了以后：这条密钥只能在该域名下使用，并会同时加进「域名」页设为已授权；留空表示不绑定域名。</small>
      </div>
      <div class="key-form-actions">
        <button type="button" class="btn" onclick="closeCreateKeyModal()">取消</button>
        <button type="submit" class="btn btn-primary">创建密钥</button>
      </div>
    </form>
  </div>
</div>