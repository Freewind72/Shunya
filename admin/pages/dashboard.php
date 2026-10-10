<?php defined('MAPI_ADMIN') or die('禁止直接访问');
// dashboard.php — 仪表盘

// 调用统计数据
$totalCalls = 0; $todayCalls = 0; $totalTraffic = 0;
$r = $db->query("SELECT COUNT(*) as c, COALESCE(SUM(traffic_bytes), 0) as tb FROM mapi_logs");
if ($r) { $row = $r->fetch_assoc(); $totalCalls = (int)$row['c']; $totalTraffic = (int)$row['tb']; }
$r = $db->query("SELECT COUNT(*) as c FROM mapi_logs WHERE DATE(created_at)='" . date('Y-m-d') . "'");
if ($r) $todayCalls = (int)$r->fetch_assoc()['c'];

// 密钥列表
$keys = [];
if (($_SESSION['admin_is_admin'] ?? 99) === 0) {
    $r = $db->query("SELECT k.*, u.username FROM mapi_keys k LEFT JOIN mapi_users u ON k.user_id=u.id ORDER BY k.id DESC");
} else {
    $r = $db->query("SELECT k.*, u.username FROM mapi_keys k LEFT JOIN mapi_users u ON k.user_id=u.id WHERE k.user_id=" . (int)$_SESSION['admin_id'] . " ORDER BY k.id DESC");
}
if ($r) while ($row = $r->fetch_assoc()) $keys[] = $row;

// 数据库版本
$dataVersion = '1.0.0';
$r = $db->query("SELECT VERSION() AS version");
if ($r && ($row = $r->fetch_assoc())) {
    $v = $row['version'];
    // "11.8.8-MariaDB-ubu2404" → "MariaDB-11.8.8"
    if (preg_match('/^([\d.]+)-(\w+)/', $v, $m)) {
        $dataVersion = $m[2] . '-' . $m[1];
    } else {
        $dataVersion = $v;
    }
} else {
    $dataVersion = '?';
}
?>
<div class="cards-grid">
<div class="stats-panel">
<?php if (($_SESSION['admin_is_admin'] ?? 99) <= 1): ?>
  <div class="stats-section-title">系统配置</div>
  <div class="stats-system">
    <div class="stat-item"><div class="stat-num"><?= PHP_VERSION ?></div><div class="stat-label">PHP 版本</div></div>
    <div class="stat-item"><div class="stat-num"><?= htmlspecialchars($dataVersion) ?></div><div class="stat-label">数据库</div></div>
  </div>
  <div class="stats-divider"></div>
<?php endif; ?>
  <div class="stats-section-title">播放器状态</div>
  <div class="stats-usage">
    <div class="stat-item"><div class="stat-num"><?= number_format($totalCalls) ?></div><div class="stat-label">总调用</div></div>
    <div class="stat-item"><div class="stat-num"><?= number_format($todayCalls) ?></div><div class="stat-label">今日</div></div>
    <div class="stat-item"><div class="stat-num"><?= formatBytes($totalTraffic) ?></div><div class="stat-label">总流量</div></div>
    <div class="stat-item"><div class="stat-num"><?= count($keys) ?></div><div class="stat-label">密钥</div></div>
  </div>
</div>

<?php

// 最近调用记录
$debugMode = false;
$r = $db->query("SELECT setting_value FROM mapi_super_settings WHERE setting_key='debug_mode'");
if ($r && $row = $r->fetch_assoc()) {
    $debugMode = ($row['setting_value'] === '1');
}
$filterEndpoints = ['verify-key', 'get-config', 'get-announcement', 'pic:'];
$endpointFilter = '';
foreach ($filterEndpoints as $ep) {
    $endpointFilter .= " AND endpoint NOT LIKE '%" . $db->real_escape_string($ep) . "%'";
}
$endpointFilter .= " AND endpoint NOT LIKE '%OPTIONS%'";
$filterIP = '';
if (!$debugMode) {
    $filterIP = " AND ip NOT IN ('127.0.0.1','::1') AND (referer='' OR (referer NOT LIKE '%localhost%' AND referer NOT LIKE '%127.0.0.1%'))";
}
$logs = [];
$r = $db->query("SELECT ip,referer,endpoint,api_key,created_at FROM mapi_logs WHERE 1=1" . $filterIP . $endpointFilter . " ORDER BY id DESC LIMIT 20");
if ($r) while ($row = $r->fetch_assoc()) $logs[] = $row;
?>

<div class="card">
  <div class="card-header">
    <span class="card-title">最近调用</span>
    <?php if (($_SESSION['admin_is_admin'] ?? 99) === 0): ?>
    <div class="card-header-actions">
    <label class="debug-switch" title="开启后「最近调用」不再过滤本机/本地调试产生的记录">
      <input type="checkbox" id="debugToggle" data-csrf="<?= csrf_token() ?>"<?= $debugMode ? ' checked' : '' ?>>
      <span class="debug-switch-slider"></span>
      <span class="debug-switch-label">调试</span>
    </label>
    <form method="post" action="?action=clear-logs" onsubmit="return confirm('确认清空所有调用记录？')" style="margin:0"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <button class="btn-sm danger">清空记录</button>
    </form>
    </div>
    <?php endif; ?>
  </div>
  <div class="log-list" id="logList">
  <?php if (empty($logs)): ?><div class="empty">暂无调用记录</div>
  <?php else: ?>
  <?php foreach ($logs as $log): ?>
  <div class="log-item">
    <div class="log-row">
      <span class="log-time"><?= substr($log['created_at'],5,11) ?></span>
      <span class="log-ip"><?= htmlspecialchars($log['ip']) ?></span>
      <span class="log-ref"><?= htmlspecialchars(preg_replace('#^https?://([^/]+).*#', '$1', $log['referer'] ?: '-')) ?></span>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
  </div>
</div>
</div>