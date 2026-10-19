<?php
// 页面文件引导序列：路由基础设施（非业务板块），除下方 INDEX_ACTION 的值外，
// 16 份页面文件逐字相同。对外地址 /admin/<页面>（无后缀）由根 index.php 路由器经通用桥
// 回退引导（或经 /admin/index.php 裸入口）；直接物理访问本文件时由本引导块声明后台上下文
// 与 action，交给 includes/front.php 跑完整判定链；判定通过后 front.php 会把本文件再载入
// 一趟执行页面主体（此刻 MAPI_ADMIN 已定义，跳过本块），主体恰好执行一次——下方
// return 拦住第一趟继续落到主体。主体内容在引导块之后，判定拦截时不会执行。
if (!defined('MAPI_ADMIN')) {
    define('MAPI_ADMIN', true);
    define('INDEX_ACTION', 'settings');
    require __DIR__ . '/../../includes/front.php';
    return;
} ?>
<?php $settingsChildren = $navItems['settings']['children'] ?? []; ?>
<div class="section-header"><div class="section-header-inner"><?= svg('srv') ?>设置</div></div>
<div class="card">
  <div class="card-header"><div class="card-title"><?= svg('srv') ?>设置项</div></div>
  <div class="set-hub">
<?php foreach ($settingsChildren as $k => $item): ?>
    <a class="set-hub-item" href="<?= admin_url($k) ?>">
      <span class="set-hub-icon"><?= $item['icon'] ?></span>
      <span class="set-hub-text"><?= $item['label'] ?></span>
      <span class="set-hub-chev">&rsaquo;</span>
    </a>
<?php endforeach; ?>
  </div>
</div>
