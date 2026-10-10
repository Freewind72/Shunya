<?php defined('MAPI_ADMIN') or die('禁止直接访问');
// settings-storage.php — 储存（S3 对象存储）
if ((($_SESSION['admin_is_admin'] ?? 99) > 1)) { echo '<div class="card"><div class="empty">无权限</div></div>'; return; }

$s3 = ['endpoint'=>'','access_key'=>'','secret_key'=>'','bucket'=>'','region'=>'auto','path_prefix'=>'','custom_domain'=>''];
$r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='s3'");
if ($r && $row = $r->fetch_assoc()) {
    $saved = json_decode($row['config_value'], true);
    if (is_array($saved)) $s3 = array_merge($s3, $saved);
}
// Redis 播放器缓存配置（默认值取自 admin/lib/redis.php，字段名与它一一对应）
$redis = function_exists('redis_default_config') ? redis_default_config() : [
    'enabled'=>0,'host'=>'127.0.0.1','port'=>6379,'password'=>'','database'=>0,'prefix'=>'mapi:',
    'default_ttl'=>600,'timeout'=>2,'ttl_url'=>300,'ttl_lrc'=>604800,'ttl_pic'=>604800,'ttl_cfg'=>60,
    'ttl_state'=>2592000,'strict_ip'=>1,'ip_grace'=>86400,
];
$rr = $db->query("SELECT config_value FROM mapi_config WHERE config_key='redis'");
$redisSavedAt = ''; $redisBroken = false;
if ($rr && $rx = $rr->fetch_assoc()) {
    $raw = (string)$rx['config_value'];
    $rj = json_decode($raw, true);
    if (is_array($rj)) {
        $redisSavedAt = (string)($rj['_saved_at'] ?? '');
        unset($rj['_saved_at']);                       // 内部字段，不参与表单
        $redis = array_merge($redis, $rj);
    } elseif ($raw !== '') {
        // 行在、但内容不是合法 JSON：**明确报出来**，别悄悄显示默认值让用户以为"配置丢了"
        $redisBroken = true;
    }
}
$redisDiag = function_exists('redis_diagnose') ? redis_diagnose() : ['ok'=>false,'msg'=>'未加载 Redis 模块','target'=>'','prefix'=>'','info'=>[]];
// 自动状态（运行时判定，不写库）：手动关 → 一律不用；手动开 → 可连就用、连不上自动停用并自动恢复
$redisState = function_exists('redis_state_report') ? redis_state_report()
            : ['manual'=>false,'reachable'=>false,'effective'=>false,'note'=>'']; 
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

<?php
// ── 封面本地化（手动开启 + 手动预热/迁移）──
// 逻辑全在 admin/lib/cover_store.php，这里只渲染状态与按钮；分批由前端轮询推进。
$coverOn  = function_exists('cover_local_on') ? cover_local_on() : false;
$coverS3  = function_exists('s3_available') ? s3_available() : false;
$coverMig = function_exists('cover_migrate_progress') ? cover_migrate_progress($db) : ['total' => 0, 'done' => 0, 'finished' => true];
$coverPls = [];
// **范围必须与「歌单管理」页一致**：那边只列当前管理员的 key 下的歌单，这里以前是
// 「全表所有歌单」，于是别人的歌单（例如另一个 key 下的「QQ 音乐」）也会画成一张卡片 ——
// 用户看着就是"我根本没创建过这个歌单"。（2026-10-10 修）
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$rp = $db->query("SELECT p.id, p.name, k.api_key FROM mapi_playlists p
                  LEFT JOIN mapi_keys k ON k.id = p.key_id
                  WHERE k.user_id = $adminId
                  ORDER BY p.sort_order ASC, p.id ASC");
while ($rp && $rowp = $rp->fetch_assoc()) {
    $pp = cover_playlist_progress($db, (int)$rowp['id']);
    // **不再跳过"本地还没有歌"的歌单**（2026-10-10 修）：新加的歌单在播放器同步过歌曲之前，
    // 本地 `mapi_songs` 还是空的 —— 以前这里直接 continue，于是它在卡片列表里**根本不出现**，
    // 用户以为"得重新添加一遍歌单"。现在一律列出，空歌单显示"本地还没有歌"并说明怎么让它有歌。
    $coverPls[] = ['id' => (int)$rowp['id'], 'name' => (string)$rowp['name'], 'total' => $pp['total'],
                   'done' => $pp['done'], 'key' => substr((string)($rowp['api_key'] ?? ''), 0, 8)];
}
$coverTotal = 0; $coverDone = 0;
foreach ($coverPls as $cp) { $coverTotal += $cp['total']; $coverDone += $cp['done']; }
?>
<div class="card">
  <form method="post" action="?action=settings-storage">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <?php /* 带着"渲染这一刻的保存时间戳"提交：库里若已变（另一个标签页/重复提交），保存会被拒绝并提示，避免旧值盖新值 */ ?>
    <input type="hidden" name="redis_form_stamp" value="<?= htmlspecialchars($redisSavedAt) ?>">
    <div class="card-header">
      <span class="card-title"><?= svg('db') ?> Redis 播放器缓存</span>
      <label class="card-header-actions cov-sw" title="手动开关，优先级最高：关掉它，即使服务端能连也一律不使用缓存。开着时按连通性自动生效/自动停用（连不上会自动跳过缓存，恢复后自动继续，不需要你来管）。">
        <input type="checkbox" name="redis_enabled" value="1"<?= !empty($redis['enabled']) ? ' checked' : '' ?>>
        <span>启用（手动）</span>
      </label>
    </div>

    <div class="cov-stat">
      <span>状态：<b><?= $redisDiag['ok'] ? '已连通' : '未连通' ?></b></span>
      <span title="<?= htmlspecialchars((string)($redisDiag['info']['keyspace'] ?? '')) ?>"><?= htmlspecialchars($redisDiag['target']) ?> · <?= htmlspecialchars($redisDiag['msg']) ?></span>
      <?php if (!empty($redisDiag['info']['dbsize'])): ?><span>库内键 <b><?= (int)$redisDiag['info']['dbsize'] ?></b> 个</span><?php endif; ?>
      <?php /* 保存时间：点完保存能直接看出到底写进库没有（省得猜"是不是没生效"） */ ?>
      <span id="redisSavedAt"><?= $redisSavedAt !== '' ? '· 配置最近保存 <b>' . htmlspecialchars($redisSavedAt) . '</b>' : '· 尚未保存过（当前是默认值）' ?></span>
    </div>
    <?php /* 自动状态：运行时判定，**不写库** —— 写库就会覆盖手动意图（那才是真正的"回弹"） */ ?>
    <div class="cov-stat" style="margin-top:-4px">
      <span style="color:<?= $redisState['effective'] ? '#1a7f37' : ($redisState['manual'] ? '#c0392b' : '#8a8a8a') ?>">
        自动：<b><?= htmlspecialchars($redisState['note']) ?></b>
      </span>
    </div>
    <?php if ($redisBroken): ?>
    <div class="cov-hint" style="color:#c0392b">⚠️ 数据库里这行配置**不是合法 JSON**（可能上次保存写坏了）。下面显示的是默认值，请重新填一遍并保存。</div>
    <?php endif; ?>

    <div class="form-row">
      <div class="form-group" style="flex:2;margin-bottom:0">
        <label class="form-label">主机</label>
        <input class="form-input" name="redis_host" value="<?= htmlspecialchars((string)$redis['host']) ?>" placeholder="127.0.0.1">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">端口</label>
        <input class="form-input" type="number" min="1" max="65535" name="redis_port" value="<?= (int)$redis['port'] ?>" title="1–65535">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">库号</label>
        <input class="form-input" type="number" min="0" max="15" name="redis_database" value="<?= (int)$redis['database'] ?>" title="0–15">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group" style="flex:2;margin-bottom:0">
        <label class="form-label">密码</label>
        <input class="form-input" type="password" name="redis_password" value="" placeholder="<?= !empty($redis['password']) ? '已设置（留空不修改）' : '无密码可留空' ?>">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">键前缀</label>
        <input class="form-input" name="redis_prefix" value="<?= htmlspecialchars((string)$redis['prefix']) ?>" placeholder="mapi:">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">超时(秒)</label>
        <input class="form-input" type="number" min="1" max="10" name="redis_timeout" value="<?= (int)$redis['timeout'] ?>" title="1–10 秒；连接与读写超时，缓存只加速，超时会立刻当未命中">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">播放地址 TTL</label>
        <input class="form-input" type="number" min="1" name="redis_ttl_url" value="<?= (int)$redis['ttl_url'] ?>" title="≥1 秒；上游给的是短时效签名链接，默认 300 秒">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">歌词 TTL</label>
        <input class="form-input" type="number" min="1" name="redis_ttl_lrc" value="<?= (int)$redis['ttl_lrc'] ?>" title="≥1 秒；歌词基本不变，默认 7 天">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">封面 TTL</label>
        <input class="form-input" type="number" min="1" name="redis_ttl_pic" value="<?= (int)$redis['ttl_pic'] ?>" title="≥1 秒；封面 URL 内容寻址，稳定，默认 7 天">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">配置 TTL</label>
        <input class="form-input" type="number" min="0" name="redis_ttl_cfg" value="<?= (int)$redis['ttl_cfg'] ?>" title="≥0；歌单配置 JSON；管理端改动会主动失效，所以可以很短。**填 0 = 不缓存配置**（实测本机 MySQL 下这类缓存几乎没有收益，真正的收益在播放地址/歌词）">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">状态 TTL</label>
        <input class="form-input" type="number" min="1" name="redis_ttl_state" value="<?= (int)$redis['ttl_state'] ?>" title="≥1 秒；播放器状态（歌单/曲目/进度/音量/模式），默认 30 天">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">换网宽限(秒)</label>
        <input class="form-input" type="number" min="0" name="redis_ip_grace" value="<?= (int)$redis['ip_grace'] ?>" title="≥0；IP 网段变了但设备号没变、且最近活跃在此时间内 → 允许继续用同一份状态；默认 1 天">
      </div>
    </div>
    <label class="form-check" style="margin:10px 0 0">
      <input type="checkbox" name="redis_strict_ip" value="1"<?= !empty($redis['strict_ip']) ? ' checked' : '' ?>>
      严格校验 IP 网段（IPv4 按 /24、IPv6 按 /64）：换网络且超过宽限期后，旧状态不再下发
    </label>

    <div class="cov-actions">
      <button class="btn btn-sm" type="submit" name="_redis_submit" value="1" title="只保存，不测连通性">保存</button>
      <button class="btn btn-sm" type="submit" name="_redis_test" value="1" title="先把当前表单保存下来，再立刻测一次连通性（改完想直接生效就点这个）">保存并测试</button>
    </div>
  </form>
</div>

<div class="card" id="coverLocalBox" data-csrf="<?= htmlspecialchars(csrf_token()) ?>" data-s3="<?= $coverS3 ? '1' : '0' ?>">
  <div class="card-header">
    <span class="card-title"><?= svg('img') ?> 封面本地化</span>
    <label class="card-header-actions cov-sw" title="按图片内容的 SHA256 内容寻址存放：同一张图不论出现在哪个歌单都只上传一份，播放器拿的是对象存储直链">
      <input type="checkbox" id="coverLocalSwitch"<?= $coverOn ? ' checked' : '' ?><?= $coverS3 ? '' : ' disabled' ?>>
      <span>开启</span>
    </label>
  </div>

  <?php if (!$coverS3): ?>
  <div class="cov-hint">先在上面填好 S3 存储并保存，才能开启。</div>
  <?php endif; ?>

  <div class="cov-stat">
    <span id="coverLocalStatus">已本地化 <b><?= (int)$coverDone ?></b>/<?= (int)$coverTotal ?> · 待迁移 <b><?= (int)$coverMig['total'] ?></b></span>
    <span id="coverGcStatus" title="删除歌单/歌曲时索引立刻释放；无人使用的图片先进回收池，过了保留期（默认 7 天）才真正从存储删掉 —— 删了又加回来能按 sha256 直接复用">· 回收池读取中…</span>
  </div>
  <div id="coverLocalBar" style="display:none;height:5px;border-radius:4px;background:rgba(128,128,128,.2);overflow:hidden;margin:8px 0 2px">
    <div id="coverLocalBarIn" style="height:100%;width:0;background:#6c5ce7;transition:width .2s"></div>
  </div>

  <div class="cov-actions">
    <button type="button" class="btn btn-sm" id="coverMigrateBtn"<?= $coverS3 && $coverMig['total'] > 0 ? '' : ' disabled' ?>>迁移旧封面</button>
    <button type="button" class="btn btn-sm" id="coverWarmAllBtn"<?= ($coverS3 && $coverOn) ? '' : ' disabled' ?>>预热全部</button>
    <button type="button" class="btn btn-sm" id="coverGcBtn" title="先干跑列出将回收的对象，确认后才真删；保留期内的孤儿不动">清理无用</button>
    <button type="button" class="btn btn-sm" id="coverRefreshBtn">刷新</button>
  </div>

  <?php /* 每个歌单一张小卡片：名称 + 已本地化/总数（跑完变绿）；服务端先渲染一份，JS 再按状态刷新 */ ?>
  <div class="cover-pl-grid" id="coverLocalCards">
    <?php foreach ($coverPls as $cp):
      $cpPct   = $cp['total'] > 0 ? (int)round($cp['done'] / $cp['total'] * 100) : 0;
      $cpDone  = ($cp['total'] > 0 && $cp['done'] >= $cp['total']);
      $cpEmpty = ($cp['total'] <= 0); ?>
    <div class="cover-pl-card<?= $cpDone ? ' done' : '' ?><?= $cpEmpty ? ' empty' : '' ?>" data-pid="<?= (int)$cp['id'] ?>"
         title="<?= $cpEmpty ? '本地还没有这个歌单的歌曲：在播放器里打开一次歌单（或在歌单管理里进它的详情页）就会同步进来，之后这里就能预热封面' : '已本地化 ' . (int)$cp['done'] . '/' . (int)$cp['total'] ?>">
      <div class="cover-pl-head">
        <span class="cover-pl-name"><?= htmlspecialchars($cp['name']) ?></span>
        <span class="cover-pl-num"><?= $cpEmpty ? '本地还没有歌' : ((int)$cp['done'] . '/' . (int)$cp['total'] . ($cpDone ? ' ✓' : '')) ?></span>
      </div>
      <div class="cover-pl-bar"><i style="width:<?= $cpPct ?>%"></i></div>
    </div>
    <?php endforeach; ?>
  </div>
  <script type="application/json" id="coverLocalPlaylists"><?= json_encode($coverPls, JSON_UNESCAPED_UNICODE) ?></script>
</div>
</div>
<?php endif; ?>
