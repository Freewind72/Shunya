<?php defined('MAPI_ADMIN') or die('禁止直接访问'); ?>
</div>

<div id="toast"></div>
<?php if ($isMobile): ?>
<div class="bottom-nav" id="bottomNav">
  <div class="nav-pill" id="navPill"></div>
<?php foreach ($navItems as $k => $item): ?>
  <a href="?action=<?= $k ?>" class="nav-item<?= $k === $navActiveKey ? ' active' : '' ?>"><?= $item['icon'] ?><span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span></a>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php $device = $isMobile ? 'mobile' : 'pc'; ?>
<script src="<?= asset_ver(str_replace('{device}', $device, $RELAY['page']['base_js'])) ?>"></script>
<?php $pageJsKey = $action . '_js'; if (isset($RELAY['page'][$pageJsKey])): ?>
<script id="page-js" src="<?= asset_ver(str_replace('{device}', $device, $RELAY['page'][$pageJsKey])) ?>"></script>
<?php endif; ?>
</body>
</html>