<?php defined('MAPI_ADMIN') or die('禁止直接访问');
require __DIR__ . '/../api/relay.php';
function svg($name) {
    $icons = [
        'dash' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>',
        'key' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="2.5"/><path d="M12 14.5V19l-2 2v-2"/><path d="M14.5 12H19l2-2h-3"/></svg>',
        'shield' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'user' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="10" cy="8" r="3.5"/><path d="M4 20c0-3.3 2.7-7 6-7s6 3.7 6 7"/><circle cx="17.5" cy="9.5" r="2.5"/><path d="M22 20c0-2.2-1.8-4.5-4-4.5"/></svg>',
        'exit' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
        'plus' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
        'copy' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>',
        'config' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        'trash' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>',
        'srv' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"/><rect x="2" y="14" width="20" height="8" rx="2" ry="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>',
        'check' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
        'img' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="2"/><circle cx="9" cy="9" r="1.5"/><polyline points="22 15 16 9 4 22"/></svg>',
        'alert' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
        'play' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>',
        'stop' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2"/></svg>',
        'upload' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
        'close' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        'music' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>',
        'mail' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2.5 6.5l9.5 6 9.5-6"/></svg>',
        'api' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 007.5.5l3-3a5 5 0 00-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 00-7.5-.5l-3 3a5 5 0 007 7l1.7-1.7"/></svg>',
        'db' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.7-4 3-9 3s-9-1.3-9-3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5"/></svg>',
        'disc' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>',
        'chev' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>',
    ];
    return $icons[$name] ?? '';
}

$navItems = [
    'dashboard' => ['label' => '仪表盘', 'icon' => svg('dash')],
    'keys' => ['label' => '密钥', 'icon' => svg('key')],
    'users' => ['label' => '人员', 'icon' => svg('user')],
    'config' => ['label' => '配置', 'icon' => svg('config')],
    'domains' => ['label' => '域名', 'icon' => svg('shield')],
    // 设置页拆分后收进「设置」的下拉子菜单：主菜单只保留一项，子项在侧边栏展开
    'settings' => [
        'label' => '设置',
        'icon' => svg('srv'),
        'children' => [
            'settings-site' => ['label' => '站点', 'icon' => svg('img')],
            'settings-mail' => ['label' => '邮件', 'icon' => svg('mail')],
            'settings-security' => ['label' => '安全', 'icon' => svg('shield')],
            'settings-api' => ['label' => '接口', 'icon' => svg('api')],
            'settings-storage' => ['label' => '储存', 'icon' => svg('db')],
        ],
    ],
];
// 人员与设置仅管理员可见（顶部、侧边栏、底部导航共用这一份过滤）。
// 名单与页面级兜底同源：includes/routes.php 的 $adminOnlyActions（admin/index.php 已先行 require）。
if ((($_SESSION['admin_is_admin'] ?? 99) > 1)) {
    foreach ($adminOnlyActions as $__aoAction) {
        if (isset($navItems[$__aoAction])) { unset($navItems[$__aoAction]); continue; }
        foreach ($navItems as $__aoKey => $__aoItem) {
            if (!isset($navItems[$__aoKey]['children'][$__aoAction])) continue;
            unset($navItems[$__aoKey]['children'][$__aoAction]);
            if (empty($navItems[$__aoKey]['children'])) unset($navItems[$__aoKey]);
        }
    }
}
// 子菜单展平：标题、各处激活态都要能按 action 反查（子项激活时高亮父菜单）
$navFlat = [];
$navParents = [];
foreach ($navItems as $k => $item) {
    $navFlat[$k] = $item;
    foreach (($item['children'] ?? []) as $ck => $ci) {
        $navFlat[$ck] = $ci;
        $navParents[$ck] = $k;
    }
}
$navActiveKey = $navParents[$action] ?? $action;

function formatBytes($b) {
    if ($b < 1024) return $b . 'B';
    if ($b < 1048576) return round($b / 1024, 1) . 'KB';
    return round($b / 1048576, 1) . 'MB';
}

$adminPlayerToken = '';
$jwtSecret = $cfg['api']['jwt_secret'] ?? hash('sha256', ($cfg['db']['password'] ?? '') . ($cfg['site']['url'] ?? ''));
if (!empty($db) && empty($db->connect_error) && empty($db->_error)) {
    $r = $db->query("SELECT api_key FROM mapi_keys WHERE status=1 ORDER BY id ASC LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) {
        $adminPlayerToken = jwt_encode(['key' => $row['api_key'], 'exp' => time() + 86400, 'iat' => time()], $jwtSecret);
    }
}
if ($adminPlayerToken) {
    setcookie('mapi_token', $adminPlayerToken, [
        'expires'  => time() + 86400,
        'path'     => '/',
        'httponly' => false,
        'samesite' => 'Lax',
        'secure'   => ($_SERVER['HTTPS'] ?? '') === 'on',
    ]);
}

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
// 后台界面主题
$adminUiTheme = $_SESSION['admin_ui_theme'] ?? 'light';
$bgStyle = '';
$bgVideoUrl = '';
$bgVideoExt = '';
if (!empty($_SESSION['admin_background_url'])) {
    $bgVideoUrl = $_SESSION['admin_background_url'];
    try {
        $uri = Uri\Rfc3986\Uri::parse($bgVideoUrl);
        if ($uri !== null) {
            $bgExt = strtolower(pathinfo($uri->getPath(), PATHINFO_EXTENSION));
            if (in_array($bgExt, ['mp4', 'webm', 'ogg', 'mov'])) {
                $bgVideoExt = $bgExt === 'mov' ? 'mp4' : ($bgExt === 'ogg' ? 'ogg' : $bgExt);
            } else {
                $bgStyle = 'background-image:url(' . htmlspecialchars($bgVideoUrl) . ');background-size:cover;background-position:center;background-repeat:no-repeat;background-attachment:fixed';
                $bgVideoUrl = '';
            }
        } else {
            $bgStyle = 'background-image:url(' . htmlspecialchars($bgVideoUrl) . ');background-size:cover;background-position:center;background-repeat:no-repeat;background-attachment:fixed';
            $bgVideoUrl = '';
        }
    } catch (\Throwable $e) {
        $bgStyle = 'background-image:url(' . htmlspecialchars($bgVideoUrl) . ');background-size:cover;background-position:center;background-repeat:no-repeat;background-attachment:fixed';
        $bgVideoUrl = '';
    }
}
if (!$bgVideoUrl && !$bgStyle && !empty($_SESSION['admin_background']) && function_exists('s3_get_url')) {
    $bgUrl = s3_get_url($_SESSION['admin_background']);
    if ($bgUrl) {
        $bgStyle = 'background-image:url(' . htmlspecialchars($bgUrl) . ');background-size:cover;background-position:center;background-repeat:no-repeat;background-attachment:fixed';
    }
}
?><!DOCTYPE html>
<html lang="zh-CN">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
<title>顺雅管理 · <?= $action === 'profile' ? '个人资料' : ($action === 'playlist-detail' ? '歌单详情' : htmlspecialchars($navFlat[$action]['label'] ?? '')) ?></title>
<?php $device = $isMobile ? 'mobile' : 'pc'; ?>
<link rel="stylesheet" href="<?= asset_ver(str_replace('{device}', $device, $RELAY['page']['base_css'])) ?>">
<?php $pageCssKey = $action . '_css'; if (isset($RELAY['page'][$pageCssKey])): ?>
<link id="page-css" rel="stylesheet" href="<?= asset_ver(str_replace('{device}', $device, $RELAY['page'][$pageCssKey])) ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?= $RELAY['cm']['core_css'] ?>">
<link rel="stylesheet" href="<?= $RELAY['cm']['theme_monokai'] ?>">
<script src="<?= $RELAY['sdk']['pusher_js'] ?>"></script>
<script src="<?= $RELAY['cm']['core_js'] ?>"></script>
<script src="<?= $RELAY['cm']['mode_xml'] ?>"></script>
<script src="<?= $RELAY['cm']['mode_css'] ?>"></script>
<script src="<?= $RELAY['cm']['mode_js'] ?>"></script>
<script src="<?= $RELAY['cm']['mode_html'] ?>"></script>
<script src="<?= $RELAY['cm']['addon_activeline'] ?>"></script>
<script>window.RELAY=<?= json_encode($RELAY['asset']) ?>;window.__mapiPrefetchConcurrency=<?= (strpos((string)($_SERVER['SERVER_SOFTWARE'] ?? ''), 'Development Server') !== false) ? 1 : 3 ?>;</script>
</head>
<body data-device="<?= $isMobile ? 'mobile' : 'pc' ?>" data-theme="<?= $adminUiTheme ?>" style="<?= $bgStyle ?>">
<?php if ($bgVideoUrl): ?>
<video class="bg-video" autoplay muted loop playsinline><source src="<?= htmlspecialchars($bgVideoUrl) ?>" type="video/<?= $bgVideoExt ?>"></video>
<?php endif; ?>
<?php if ($isMobile): ?>
<div class="topbar" id="topbar">
  <span class="topbar-title">顺雅管理</span>
  <nav class="topbar-nav" id="topbarNav">
<?php foreach ($navItems as $k => $item): ?>
    <a href="?action=<?= $k ?>" class="topbar-nav-item<?= $k === $navActiveKey ? ' active' : '' ?>">
      <span class="topbar-nav-icon"><?= $item['icon'] ?></span>
      <span class="topbar-nav-label"><?= $item['label'] ?></span>
    </a>
<?php endforeach; ?>
  </nav>
  <div class="topbar-right">
    <a href="?action=profile" class="topbar-user">
      <span class="topbar-avatar"><?php if ($qq = $_SESSION['admin_qq'] ?? ''): ?><img src="<?= $RELAY['avatar']['qq'] ?>?b=qq&nk=<?= (int)$qq ?>&s=100"><?php else: ?><span class="topbar-avatar-fallback"><?= htmlspecialchars(mb_substr($_SESSION['admin_user'],0,1,'UTF-8')) ?></span><?php endif; ?></span>
      <span class="topbar-username"><?= htmlspecialchars($_SESSION['admin_user']) ?></span>
    </a>
  </div>
</div>
<?php else: ?>
<div class="sidebar glass" id="sidebar">
  <div class="sidebar-brand" id="sbBrand" title="折叠侧边栏" role="button" tabindex="0">
    <img class="sidebar-logo" src="../favicon.ico" alt="">
    <span class="sidebar-title">顺雅管理</span>
  </div>
  <nav class="sidebar-nav" id="sidebarNav">
<?php foreach ($navItems as $k => $item): ?>
<?php if (!empty($item['children'])): $gOpen = ($navActiveKey === $k); $gChild = isset($item['children'][$action]); ?>
    <div class="sb-group<?= $gOpen ? ' open' : '' ?><?= $gChild ? ' has-active' : '' ?>" data-group="<?= $k ?>">
      <button type="button" class="sb-item sb-parent<?= $action === $k ? ' active' : '' ?>" data-toggle-group="<?= $k ?>" aria-expanded="<?= $gOpen ? 'true' : 'false' ?>">
        <span class="sb-icon"><?= $item['icon'] ?></span>
        <span class="sb-label"><?= $item['label'] ?></span>
        <span class="sb-arrow"><?= svg('chev') ?></span>
      </button>
      <div class="sb-sub">
<?php foreach ($item['children'] as $ck => $ci): ?>
        <a href="?action=<?= $ck ?>" class="sb-sub-item<?= $ck === $action ? ' active' : '' ?>">
          <span class="sb-icon"><?= $ci['icon'] ?></span>
          <span class="sb-label"><?= $ci['label'] ?></span>
        </a>
<?php endforeach; ?>
      </div>
    </div>
<?php else: ?>
    <a href="?action=<?= $k ?>" class="sb-item<?= $k === $navActiveKey ? ' active' : '' ?>">
      <span class="sb-icon"><?= $item['icon'] ?></span>
      <span class="sb-label"><?= $item['label'] ?></span>
    </a>
<?php endif; ?>
<?php endforeach; ?>
  </nav>
  <div class="sidebar-footer">
    <a href="?action=profile" class="sb-user">
      <span class="sb-avatar"><?php if ($qq = $_SESSION['admin_qq'] ?? ''): ?><img src="<?= $RELAY['avatar']['qq'] ?>?b=qq&nk=<?= (int)$qq ?>&s=100"><?php else: ?><span class="sb-avatar-fallback"><?= htmlspecialchars(mb_substr($_SESSION['admin_user'],0,1,'UTF-8')) ?></span><?php endif; ?></span>
      <span class="sb-username"><?= htmlspecialchars($_SESSION['admin_user']) ?></span>
    </a>
    <a href="?action=logout" class="sb-logout" title="退出"><?= svg('exit') ?><span>退出</span></a>
  </div>
</div>
<?php endif; ?>
<div class="wrap"><?php if ($msg): ?><script>setTimeout(function(){showToast('<?= addslashes(htmlspecialchars($msg)) ?>','ok')},100)</script><?php endif; ?>
<?php if ($err): ?><script>setTimeout(function(){showToast('<?= addslashes(htmlspecialchars($err)) ?>','err')},100)</script><?php endif; ?>