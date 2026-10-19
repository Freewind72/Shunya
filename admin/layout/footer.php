<?php defined('MAPI_ADMIN') or die('禁止直接访问'); ?>
</div>

<div id="toast"></div>
<?php if ($isMobile): ?>
<div class="bottom-nav" id="bottomNav">
  <div class="nav-pill" id="navPill"></div>
<?php foreach ($navItems as $k => $item): ?>
  <a href="<?= admin_url($k) ?>" class="nav-item<?= $k === $navActiveKey ? ' active' : '' ?>"><?= $item['icon'] ?><span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span></a>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php $device = $isMobile ? 'mobile' : 'pc'; ?>
<script src="<?= asset_ver(str_replace('{device}', $device, $RELAY['page']['base_js'])) ?>"></script>
<?php $pageJsKey = $action . '_js'; if (isset($RELAY['page'][$pageJsKey])): ?>
<script id="page-js" src="<?= asset_ver(str_replace('{device}', $device, $RELAY['page'][$pageJsKey])) ?>"></script>
<?php endif; ?>
<?php /* Pusher 上线通知已于 2026-10-16 整体移除（判定侧 includes/user_init.php、库 lib/pusher.php 一并删除）。
         注意：这里曾用 fastcgi_finish_request() 根治「长时间不动后开后台卡好几秒」。
         以后若有「页面发完之后再干活」的需求，照这个形状做：
         先写会话 → session_write_close() → fastcgi_finish_request() → 再干活。 */ ?>
</body>
</html>
