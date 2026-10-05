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
?>
<div class="notice-line"><?= svg('alert') ?> 自动探测值以客户端的实时上报为准，这里只填修正量。</div>

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
        <label for="domLyrics">歌词修正（px）</label>
        <input type="number" id="domLyrics" name="lyrics_bottom" class="form-input" value="0" step="1">
      </div>
      <div class="dom-field">
        <label for="domPlayer">播放器修正（px）</label>
        <input type="number" id="domPlayer" name="player_bottom" class="form-input" value="" step="1" placeholder="留空 = 同歌词">
        <div class="dom-field-hint">留空表示跟随歌词修正量</div>
      </div>
      <div class="dom-field dom-field-wide">
        <label for="domNote">备注</label>
        <input type="text" id="domNote" name="note" class="form-input" maxlength="255" placeholder="例如：老主题，底部导航 56px">
      </div>
    </div>
    <div class="dom-form-foot">
      <label class="form-check dom-check"><input type="checkbox" name="authorized" value="1" checked> 已授权</label>
      <label class="form-check dom-check"><input type="checkbox" name="auto" value="1" checked> 自动探测</label>
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
        <th>已授权</th><th>自动探测</th><th>歌词修正</th><th>播放器修正</th><th>备注</th><th class="dom-col-act">操作</th>
      </tr></thead>
      <tbody>
      <?php foreach ($domains as $d):
        $pbRaw = $d['player_bottom'];
        $follow = ($pbRaw === null || $pbRaw === '');
        $pb = $follow ? (int)$d['lyrics_bottom'] : (int)$pbRaw;
      ?>
      <tr>
        <td><code class="dom-host" data-host="<?= htmlspecialchars($d['domain'], ENT_QUOTES) ?>" title="点击复制"><?= htmlspecialchars($d['domain']) ?></code><?php if (!empty($d['auto_added'])): ?> <span class="tag tag-auto" title="主机名第一次加载播放器时自动登记">自动登记</span><?php endif; ?></td>
        <?php if ($isSuper): ?><td class="dom-cell-dim"><?= htmlspecialchars($__domUsers[(int)$d['user_id']] ?? ('#' . (int)$d['user_id'])) ?></td><?php endif; ?>
        <td><span class="tag <?= ((int)$d['authorized'] === 1) ? 'tag-ok' : 'tag-off' ?>"><?= ((int)$d['authorized'] === 1) ? '已授权' : '未授权' ?></span></td>
        <td><?= ((int)$d['auto'] === 1) ? '开' : '关' ?></td>
        <td class="dom-num"><?= (int)$d['lyrics_bottom'] ?>px</td>
        <td class="dom-num"><?= $pb ?>px<?= $follow ? ' <span class="dom-follow">跟随</span>' : '' ?></td>
        <td class="dom-cell-note"><?= $d['note'] !== '' ? htmlspecialchars($d['note']) : '<span class="dom-none">—</span>' ?></td>
        <td class="dom-col-act">
          <div class="dom-row-actions">
            <button type="button" class="btn-sm dom-edit" data-id="<?= (int)$d['id'] ?>" data-domain="<?= htmlspecialchars($d['domain'], ENT_QUOTES) ?>" data-authorized="<?= (int)$d['authorized'] ?>" data-auto="<?= (int)$d['auto'] ?>" data-lyrics="<?= (int)$d['lyrics_bottom'] ?>" data-player="<?= $follow ? '' : (int)$pbRaw ?>" data-note="<?= htmlspecialchars($d['note'], ENT_QUOTES) ?>">编辑</button>
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
      $pbRaw = $d['player_bottom'];
      $follow = ($pbRaw === null || $pbRaw === '');
      $pb = $follow ? (int)$d['lyrics_bottom'] : (int)$pbRaw;
    ?>
    <div class="dom-card">
      <div class="dom-card-top">
        <code class="dom-host" data-host="<?= htmlspecialchars($d['domain'], ENT_QUOTES) ?>"><?= htmlspecialchars($d['domain']) ?></code>
        <span class="tag <?= ((int)$d['authorized'] === 1) ? 'tag-ok' : 'tag-off' ?>"><?= ((int)$d['authorized'] === 1) ? '已授权' : '未授权' ?></span>
      </div>
      <div class="dom-card-meta">
        <?php if (!empty($d['auto_added'])): ?><span class="tag tag-auto">自动登记</span><?php endif; ?>
        <span>自动探测：<?= ((int)$d['auto'] === 1) ? '开' : '关' ?></span>
        <span>歌词修正：<?= (int)$d['lyrics_bottom'] ?>px</span>
        <span>播放器修正：<?= $pb ?>px<?= $follow ? '（跟随）' : '' ?></span>
        <?php if ($isSuper): ?><span>归属：<?= htmlspecialchars($__domUsers[(int)$d['user_id']] ?? ('#' . (int)$d['user_id'])) ?></span><?php endif; ?>
      </div>
      <?php if ($d['note'] !== ''): ?><div class="dom-card-note"><?= htmlspecialchars($d['note']) ?></div><?php endif; ?>
      <div class="dom-card-actions">
        <button type="button" class="btn-sm dom-edit" data-id="<?= (int)$d['id'] ?>" data-domain="<?= htmlspecialchars($d['domain'], ENT_QUOTES) ?>" data-authorized="<?= (int)$d['authorized'] ?>" data-auto="<?= (int)$d['auto'] ?>" data-lyrics="<?= (int)$d['lyrics_bottom'] ?>" data-player="<?= $follow ? '' : (int)$pbRaw ?>" data-note="<?= htmlspecialchars($d['note'], ENT_QUOTES) ?>">编辑</button>
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