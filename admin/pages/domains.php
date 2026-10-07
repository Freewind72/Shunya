<?php defined('MAPI_ADMIN') or die('禁止直接访问');
require __DIR__ . '/../api/relay.php';
require_once __DIR__ . '/../lib/domains.php';
// domains.php — 宿主域名授权与底部偏移

$isAdmin = $_SESSION['admin_is_admin'] ?? 99;
$isSuper = ($isAdmin <= 1);   // 只有超级管理员能看全部域名 / 改全局开关

// 加载全部用户（仅超级管理员需要，用来显示域名归属）
$__domUsers = [];
if ($isSuper) {
    $ur = $db->query("SELECT id, username FROM mapi_users");
    if ($ur) while ($urow = $ur->fetch_assoc()) $__domUsers[(int)$urow['id']] = $urow['username'];
}

// 数据范围：超级管理员看全部，其它人只看自己的
$domScopeUid = $isSuper ? 0 : (int)$_SESSION['admin_id'];
$domRes = domains_list($db, $domScopeUid);
$domains = $domRes['rows'];
$tableMissing = !$domRes['ok'];
$authorizeOn = domain_authorize_on($db);
$autoAddOn = domain_auto_add_on($db);
$csrf = csrf_token();
$sqlFile = domains_db_type($db) === 'sqlite' ? 'install/sql/sqlite.sql' : 'install/sql/mysql.sql';

// 密钥列表（「指定密钥」下拉用）：超管看全部，其它人只看自己名下的
$domKeys = domains_key_list($db, $domScopeUid);
$__domKeyById = [];
foreach ($domKeys as $__k) $__domKeyById[(int)$__k['id']] = $__k;
// 密钥显示名：超管带归属用户名，方便区分不同人的同名密钥
$keyLabel = function ($k) use ($isSuper, $__domUsers) {
    $name = trim((string)($k['name'] ?? ''));
    if ($name === '') $name = '密钥 #' . (int)$k['id'];
    if ($isSuper) $name = ($__domUsers[(int)$k['user_id']] ?? ('#' . (int)$k['user_id'])) . ' / ' . $name;
    if ((int)($k['status'] ?? 1) !== 1) $name .= '（已停用）';
    return $name;
};
// 一行的「密钥」显示：0 = 不限
$rowKeyLabel = function ($d) use ($__domKeyById, $isSuper, $__domUsers) {
    $kid = (int)($d['key_id'] ?? 0);
    if ($kid > 0 && isset($__domKeyById[$kid])) {
        $name = trim((string)($__domKeyById[$kid]['name'] ?? ''));
        if ($name === '') $name = '密钥 #' . $kid;
        // 非超管看到的密钥都是自己的，不必再标归属
        return $isSuper ? ($__domUsers[(int)$__domKeyById[$kid]['user_id']] ?? ('#' . (int)$__domKeyById[$kid]['user_id'])) . ' / ' . $name : $name;
    }
    if ($kid > 0) return '密钥 #' . $kid . '（已删除）';
    return '';
};
?>

<?php if ($tableMissing): ?>
<div class="dom-alert">
  <strong>数据表 mapi_domains 不存在</strong>
  <div>域名配置暂时无法读写。请先访问 <a href="/install/upgrade.php" target="_blank">/install/upgrade.php</a> 完成升级，或手动执行 <code><?= htmlspecialchars($sqlFile) ?></code> 里的 <code>mapi_domains</code> 建表语句。</div>
  <?php if (!empty($domRes['error'])): ?><div class="dom-alert-detail">细节：<?= htmlspecialchars($domRes['error']) ?></div><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isSuper): ?>
<div class="dom-switch-grid">
<div class="card">
  <div class="card-header">
    <span class="card-title"><?= svg('shield') ?> 启用域名授权</span>
    <form method="post" action="?action=domains-authorize" class="dom-switch-form">
      <input type="hidden" name="_csrf" value="<?= $csrf ?>">
      <input type="hidden" name="domain_authorize" value="0">
      <label class="dom-switch">
        <input type="checkbox" name="domain_authorize" value="1"<?= $authorizeOn ? ' checked' : '' ?><?= $tableMissing ? ' disabled' : '' ?>>
        <span class="dom-switch-track"><span class="dom-switch-dot"></span></span>
        <span class="dom-switch-text"><?= $authorizeOn ? '已启用' : '已关闭' ?></span>
      </label>
    </form>
  </div>
  <div class="dom-hint">
    关掉时任何站都能用，只是拿不到专属偏移：播放器会退回纯自动探测。
    打开后，只有本表里「已授权」的域名能启动播放器，其余一律拒绝。判定按<b>主机名精确匹配</b>：填主域名不会顺带放行它的子域名。
    <?php if ($tableMissing): ?><strong>（表不存在，开关暂不可用）</strong><?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <span class="card-title"><?= svg('plus') ?> 自动添加检测到的域名</span>
    <form method="post" action="?action=domains-auto-add" class="dom-switch-form">
      <input type="hidden" name="_csrf" value="<?= $csrf ?>">
      <input type="hidden" name="domain_auto_add" value="0">
      <label class="dom-switch">
        <input type="checkbox" name="domain_auto_add" value="1"<?= $autoAddOn ? ' checked' : '' ?><?= $tableMissing ? ' disabled' : '' ?>>
        <span class="dom-switch-track"><span class="dom-switch-dot"></span></span>
        <span class="dom-switch-text"><?= $autoAddOn ? '已启用' : '已关闭' ?></span>
      </label>
    </form>
  </div>
  <div class="dom-hint">
    开着时，<b>任何主机名第一次加载播放器都会被自动写进本表并授权</b>（列表里标「自动登记」）：刚换域名、上 CDN、多了个 www 前缀，都不会再被自己的开关挡住。
    关掉后只认本表里已授权的域名，新主机名一律拒绝。本开关只在上面的「启用域名授权」打开时起作用。
    自动登记只补新行，<b>不会复活</b>你手动停用（未授权）的行；每个密钥最多自动登记 <?= DOMAINS_AUTO_MAX ?> 个。
    自动登记的行<b>优先置顶显示</b>，也可以直接删除。
  </div>
</div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><span class="card-title"><?= svg('plus') ?> 添加域名</span></div>
  <form method="post" action="?action=domains-save" class="dom-form" id="domForm" data-ajax="1">
    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
    <input type="hidden" name="id" id="domId" value="0">
    <div class="dom-form-grid">
      <div class="dom-field dom-field-wide">
        <label for="domDomain">域名</label>
        <input type="text" id="domDomain" name="domain" class="form-input" placeholder="bmwy72.top" autocomplete="off" required>
        <div class="dom-field-hint">只填主机名，不要带 http:// 或路径；会自动小写、去端口。开着「自动添加」时，新主机名第一次加载播放器会自己登记，不用手填。</div>
      </div>
      <div class="dom-field">
        <label for="domKeyId">指定密钥</label>
        <select id="domKeyId" name="key_id" class="form-input"<?= $tableMissing ? ' disabled' : '' ?>>
          <option value="0">不限（该账号所有密钥）</option>
          <?php foreach ($domKeys as $k): ?>
          <option value="<?= (int)$k['id'] ?>"<?= ((int)($k['status'] ?? 1) !== 1) ? ' disabled' : '' ?>><?= htmlspecialchars($keyLabel($k), ENT_QUOTES) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="dom-field-hint">选了某条密钥，这行授权只对它生效；同一条密钥的专属行优先于「不限」的行。</div>
      </div>
      <div class="dom-field">
        <label for="domNote">备注</label>
        <input type="text" id="domNote" name="note" class="form-input" maxlength="255" placeholder="例如：老主题，底部导航 56px">
      </div>
    </div>

    <!-- 底部让出量：PC 与移动端分开设置。同一个站在两端往往完全不同 —— PC 通常根本没有贴底 tab 栏，
         移动端却有；自动探测按各自那一端分别判定。 -->
    <div class="dom-devices">
      <?php
      // 两端的表单块只有字段前缀不同，用同一个模板函数生成，避免两份 HTML 走偏
      $devBlocks = [
          ['pc',     'PC',     'pc', '桌面端：一般没有贴底导航栏，探测不到就自动是 0'],
          ['mobile', '移动端', 'mo', '手机端：宿主主题的底部 tab 栏 / 悬浮胶囊都算在内'],
      ];
      foreach ($devBlocks as $b):
          list($devKey, $devLabel, $pre, $devHint) = $b;
      ?>
      <div class="dom-device" data-device="<?= $devKey ?>">
        <div class="dom-device-head">
          <span class="dom-device-name"><?= $devLabel ?></span>
          <label class="dom-switch dom-switch-sm" title="自动 = 探测宿主底栏高度并让位；关掉则只按下面的修正量让位">
            <input type="hidden" name="<?= $pre ?>_auto" value="0">
            <input type="checkbox" name="<?= $pre ?>_auto" value="1" id="dom<?= ucfirst($pre) ?>Auto" checked>
            <span class="dom-switch-track"><span class="dom-switch-dot"></span></span>
            <span class="dom-switch-text">自动</span>
          </label>
        </div>
        <div class="dom-device-fields">
          <div class="dom-field">
            <label for="dom<?= ucfirst($pre) ?>Lyrics">歌词修正（px）</label>
            <input type="number" id="dom<?= ucfirst($pre) ?>Lyrics" name="<?= $pre ?>_lyrics" class="form-input" value="0" step="1">
          </div>
          <div class="dom-field">
            <label for="dom<?= ucfirst($pre) ?>Player">播放器修正（px）</label>
            <input type="number" id="dom<?= ucfirst($pre) ?>Player" name="<?= $pre ?>_player" class="form-input" value="" step="1" placeholder="留空 = 同歌词">
          </div>
        </div>
        <div class="dom-device-foot">
          <span class="dom-device-hint"><?= $devHint ?></span>
          <span class="dom-device-detected" id="dom<?= ucfirst($pre) ?>Detected" hidden>
            最近探测 <b class="dom-detected-val">0</b>px
            <button type="button" class="btn-xs dom-fill-detected" data-target="dom<?= ucfirst($pre) ?>Lyrics">填入歌词修正</button>
          </span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="dom-form-foot">
      <label class="form-check dom-check"><input type="checkbox" name="authorized" value="1" checked> 已授权</label>
      <div class="dom-form-actions">
        <button type="button" class="btn-sm" id="domReset" hidden>取消编辑</button>
        <button type="submit" class="btn-sm" id="domSubmit"<?= $tableMissing ? ' disabled' : '' ?>><?= svg('plus') ?> 添加</button>
      </div>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-header">
    <span class="card-title"><?= svg('shield') ?> 域名授权<?= $isSuper ? ' <small class="dom-count">' . count($domains) . ' 个</small>' : '' ?></span>
  </div>
  <?php if (empty($domains)): ?>
  <div class="empty"><?= $tableMissing ? '表不存在，先按上面的提示升级' : '暂无域名：开着「自动添加」时，播放器第一次被加载就会自动登记；也可以在上面手动添加' ?></div>
  <?php else: ?>

  <div class="dom-table-wrap">
    <table class="dom-table">
      <thead><tr>
        <th>域名</th>
        <?php if ($isSuper): ?><th>归属</th><?php endif; ?>
        <th>密钥</th>
        <th>已授权</th>
        <th>PC<small class="dom-th-sub">自动 / 歌词 / 播放器</small></th>
        <th>移动端<small class="dom-th-sub">自动 / 歌词 / 播放器</small></th>
        <th>最近探测<small class="dom-th-sub">PC / 移动</small></th>
        <th>备注</th><th class="dom-col-act">操作</th>
      </tr></thead>
      <tbody>
      <?php foreach ($domains as $d):
        $dc = domains_device_cfg($d);           // 两端最终生效值（老数据自动回落到旧字段）
        $pc = $dc['pc'];
        $mo = $dc['mobile'];
        $pcDet = (int)($d['pc_detected'] ?? 0);
        $moDet = (int)($d['mo_detected'] ?? 0);
      ?>
      <tr>
        <td><code class="dom-host" data-host="<?= htmlspecialchars($d['domain'], ENT_QUOTES) ?>" title="点击复制"><?= htmlspecialchars($d['domain']) ?></code><?php if (!empty($d['auto_added'])): ?> <span class="tag tag-auto" title="主机名第一次加载播放器时自动登记">自动登记</span><?php endif; ?></td>
        <?php if ($isSuper): ?><td class="dom-cell-dim"><?= htmlspecialchars($__domUsers[(int)$d['user_id']] ?? ('#' . (int)$d['user_id'])) ?></td><?php endif; ?>
        <?php $__kl = $rowKeyLabel($d); ?>
        <td class="dom-cell-key"><?= $__kl !== '' ? htmlspecialchars($__kl) : '<span class="dom-none" title="该账号下所有密钥共用这行授权">不限</span>' ?></td>
        <td><span class="tag <?= ((int)$d['authorized'] === 1) ? 'tag-ok' : 'tag-off' ?>"><?= ((int)$d['authorized'] === 1) ? '已授权' : '未授权' ?></span></td>
        <td class="dom-dev-cell">
          <span class="tag <?= $pc['auto'] ? 'tag-ok' : 'tag-off' ?>"><?= $pc['auto'] ? '自动' : '手动' ?></span>
          <span class="dom-num"><?= $pc['lyrics'] ?>px</span>
          <span class="dom-num<?= ($pc['player'] === $pc['lyrics']) ? ' dom-same' : '' ?>" title="播放器让出量"><?= $pc['player'] ?>px</span>
        </td>
        <td class="dom-dev-cell">
          <span class="tag <?= $mo['auto'] ? 'tag-ok' : 'tag-off' ?>"><?= $mo['auto'] ? '自动' : '手动' ?></span>
          <span class="dom-num"><?= $mo['lyrics'] ?>px</span>
          <span class="dom-num<?= ($mo['player'] === $mo['lyrics']) ? ' dom-same' : '' ?>" title="播放器让出量"><?= $mo['player'] ?>px</span>
        </td>
        <td class="dom-num dom-detected-cell" title="<?= htmlspecialchars((string)($d['detected_at'] ?? '')) ?>"><?= $pcDet ?> / <?= $moDet ?>px</td>
        <td class="dom-cell-note"><?= $d['note'] !== '' ? htmlspecialchars($d['note']) : '<span class="dom-none">—</span>' ?></td>
        <td class="dom-col-act">
          <div class="dom-row-actions">
            <button type="button" class="btn-sm dom-edit" data-id="<?= (int)$d['id'] ?>" data-key-id="<?= (int)($d['key_id'] ?? 0) ?>" data-domain="<?= htmlspecialchars($d['domain'], ENT_QUOTES) ?>" data-authorized="<?= (int)$d['authorized'] ?>" data-note="<?= htmlspecialchars($d['note'], ENT_QUOTES) ?>" data-pc-auto="<?= $pc['auto'] ? 1 : 0 ?>" data-pc-lyrics="<?= $pc['lyrics'] ?>" data-pc-player="<?= ($pc['player'] === $pc['lyrics']) ? '' : $pc['player'] ?>" data-mo-auto="<?= $mo['auto'] ? 1 : 0 ?>" data-mo-lyrics="<?= $mo['lyrics'] ?>" data-mo-player="<?= ($mo['player'] === $mo['lyrics']) ? '' : $mo['player'] ?>" data-pc-detected="<?= $pcDet ?>" data-mo-detected="<?= $moDet ?>">编辑</button>
            <form method="post" action="?action=domains-delete" onsubmit="return confirm('确认删除域名 <?= htmlspecialchars(addslashes($d['domain']), ENT_QUOTES) ?> ？')">
              <input type="hidden" name="_csrf" value="<?= $csrf ?>">
              <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button class="btn-sm danger">删除</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="dom-cards">
    <?php foreach ($domains as $d):
      $dc = domains_device_cfg($d);
      $pc = $dc['pc'];
      $mo = $dc['mobile'];
      $pcDet = (int)($d['pc_detected'] ?? 0);
      $moDet = (int)($d['mo_detected'] ?? 0);
    ?>
    <div class="dom-card">
      <div class="dom-card-top">
        <code class="dom-host" data-host="<?= htmlspecialchars($d['domain'], ENT_QUOTES) ?>"><?= htmlspecialchars($d['domain']) ?></code>
        <span class="tag <?= ((int)$d['authorized'] === 1) ? 'tag-ok' : 'tag-off' ?>"><?= ((int)$d['authorized'] === 1) ? '已授权' : '未授权' ?></span>
      </div>
      <div class="dom-card-meta">
        <?php if (!empty($d['auto_added'])): ?><span class="tag tag-auto">自动登记</span><?php endif; ?>
        <?php if ($isSuper): ?><span>归属：<?= htmlspecialchars($__domUsers[(int)$d['user_id']] ?? ('#' . (int)$d['user_id'])) ?></span><?php endif; ?>
        <?php $__kl = $rowKeyLabel($d); ?>
        <span>密钥：<?= $__kl !== '' ? htmlspecialchars($__kl) : '不限' ?></span>
      </div>
      <div class="dom-card-devices">
        <div class="dom-card-device">
          <span class="dom-card-dev-name">PC</span>
          <span class="tag <?= $pc['auto'] ? 'tag-ok' : 'tag-off' ?>"><?= $pc['auto'] ? '自动' : '手动' ?></span>
          <span>歌词 <?= $pc['lyrics'] ?>px · 播放器 <?= $pc['player'] ?>px</span>
          <span class="dom-card-det">探测 <?= $pcDet ?>px</span>
        </div>
        <div class="dom-card-device">
          <span class="dom-card-dev-name">移动端</span>
          <span class="tag <?= $mo['auto'] ? 'tag-ok' : 'tag-off' ?>"><?= $mo['auto'] ? '自动' : '手动' ?></span>
          <span>歌词 <?= $mo['lyrics'] ?>px · 播放器 <?= $mo['player'] ?>px</span>
          <span class="dom-card-det">探测 <?= $moDet ?>px</span>
        </div>
      </div>
      <?php if ($d['note'] !== ''): ?><div class="dom-card-note"><?= htmlspecialchars($d['note']) ?></div><?php endif; ?>
      <div class="dom-card-actions">
        <button type="button" class="btn-sm dom-edit" data-id="<?= (int)$d['id'] ?>" data-key-id="<?= (int)($d['key_id'] ?? 0) ?>" data-domain="<?= htmlspecialchars($d['domain'], ENT_QUOTES) ?>" data-authorized="<?= (int)$d['authorized'] ?>" data-note="<?= htmlspecialchars($d['note'], ENT_QUOTES) ?>" data-pc-auto="<?= $pc['auto'] ? 1 : 0 ?>" data-pc-lyrics="<?= $pc['lyrics'] ?>" data-pc-player="<?= ($pc['player'] === $pc['lyrics']) ? '' : $pc['player'] ?>" data-mo-auto="<?= $mo['auto'] ? 1 : 0 ?>" data-mo-lyrics="<?= $mo['lyrics'] ?>" data-mo-player="<?= ($mo['player'] === $mo['lyrics']) ? '' : $mo['player'] ?>" data-pc-detected="<?= $pcDet ?>" data-mo-detected="<?= $moDet ?>">编辑</button>
        <form method="post" action="?action=domains-delete" onsubmit="return confirm('确认删除域名 <?= htmlspecialchars(addslashes($d['domain']), ENT_QUOTES) ?> ？')">
          <input type="hidden" name="_csrf" value="<?= $csrf ?>">
          <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
          <button class="btn-sm danger">删除</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php endif; ?>
</div>

<?php if (!$tableMissing && !empty($domains)): ?>
<script>
// 两套 DOM 同时渲染：窄屏（手机 / 小窗）用卡片，宽屏用表格，只切显示，避免重复维护
(function () {
  var table = document.querySelector('.dom-table-wrap');
  var cards = document.querySelector('.dom-cards');
  if (!table || !cards) return;
  function sync() {
    var narrow = document.body.dataset.device === 'mobile' || window.innerWidth < 820;
    table.style.display = narrow ? 'none' : '';
    cards.style.display = narrow ? 'block' : 'none';
  }
  sync();
  window.addEventListener('resize', sync);
})();
</script>
<?php endif; ?>