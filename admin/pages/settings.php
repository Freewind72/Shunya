<?php defined('MAPI_ADMIN') or die('禁止直接访问'); ?>
<?php $settingsChildren = $navItems['settings']['children'] ?? []; ?>
<div class="section-header"><div class="section-header-inner"><?= svg('srv') ?>设置</div></div>
<div class="card">
  <div class="card-header"><div class="card-title"><?= svg('srv') ?>设置项</div></div>
  <div class="set-hub">
<?php foreach ($settingsChildren as $k => $item): ?>
    <a class="set-hub-item" href="?action=<?= $k ?>">
      <span class="set-hub-icon"><?= $item['icon'] ?></span>
      <span class="set-hub-text"><?= $item['label'] ?></span>
      <span class="set-hub-chev">&rsaquo;</span>
    </a>
<?php endforeach; ?>
  </div>
</div>
