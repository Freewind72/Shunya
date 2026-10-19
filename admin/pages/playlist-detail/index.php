<?php
// 页面文件引导序列：路由基础设施（非业务板块），除下方 INDEX_ACTION 的值外，
// 16 份页面文件逐字相同。对外地址 /admin/<页面>（无后缀）由根 index.php 路由器经通用桥
// 回退引导（或经 /admin/index.php 裸入口）；直接物理访问本文件时由本引导块声明后台上下文
// 与 action，交给 includes/front.php 跑完整判定链；判定通过后 front.php 会把本文件再载入
// 一趟执行页面主体（此刻 MAPI_ADMIN 已定义，跳过本块），主体恰好执行一次——下方
// return 拦住第一趟继续落到主体。主体内容在引导块之后，判定拦截时不会执行。
if (!defined('MAPI_ADMIN')) {
    define('MAPI_ADMIN', true);
    define('INDEX_ACTION', 'playlist-detail');
    require __DIR__ . '/../../includes/front.php';
    return;
}


/**
 * 读取歌单详情页所需数据（本页私有，原来单独放在 admin/service/ 下）。
 *
 * ⚠️ 必须包 `function_exists` 守卫：本文件每个请求会被 include **两次**
 *   （引导块第一趟 + front.php 判定通过后再载入一趟）。PHP 对无条件的顶层函数声明
 *   做编译期 early binding ⇒ 第二趟编译即 fatal `Cannot redeclare`，哪怕第一趟
 *   已提前 return。条件声明不提升，包一层即安全。（本项目 login 页踩过同样的坑。）
 *
 * $cfg 口径：库里 api.base_url / param_* 由 mapi_app_config() 合并进来，
 * 与 admin/includes/bootstrap.php 的读取链同源。
 */
if (!function_exists('playlist_detail_load')) {
    function playlist_detail_load($db, array $cfg, array $session, array $request): array {
        $plId = (int)($request['id'] ?? 0);
        if ($plId <= 0) return ['ok' => false, 'error' => '参数错误'];

        // 读取歌单与归属信息
        $pl = null;
        $r = $db->query("SELECT p.*, k.api_key, k.user_id as key_user_id, u.username FROM mapi_playlists p LEFT JOIN mapi_keys k ON p.key_id=k.id LEFT JOIN mapi_users u ON k.user_id=u.id WHERE p.id=$plId");
        if ($r) $pl = $r->fetch_assoc();
        if (!$pl) return ['ok' => false, 'error' => '歌单不存在'];

        // 校验歌单归属
        $isAdmin = (int)($session['admin_is_admin'] ?? 99) <= 1;
        $isOwner = $isAdmin || (int)$pl['key_user_id'] === (int)($session['admin_id'] ?? 0);
        if (!$isOwner) return ['ok' => false, 'error' => '无权限'];

        // 读取歌曲并统计已下架数量
        $songs = [];
        $missingCount = 0;
        $r = $db->query("SELECT id, song_id, name, artist, server, sort_order, missing FROM mapi_songs WHERE playlist_id=$plId ORDER BY sort_order ASC, id ASC");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                if ((int)($row['missing'] ?? 0) === 1) $missingCount++;
                $songs[] = $row;
            }
        }

        $isRemote = ($pl['type'] ?? '') === 'remote';
        $apiBase  = $cfg['api']['base_url'] ?? '';
        $pId      = $cfg['api']['param_id'] ?? 'id';
        $rServer  = $cfg['api']['param_server'] ?? 'server';
        $rType    = $cfg['api']['param_type'] ?? 'type';

        // 读取歌曲封面缓存：优先「已本地化」的 S3 直链（内容寻址），其次库里那份历史 base64，
        // 都没有才回落到上游取图地址 —— 迁移到 S3 之后这里不能再依赖 base64。
        $covers = cover_songs_load($db, $songs);
        $s3Covers = function_exists('cover_song_urls') ? cover_song_urls($db, $songs) : [];

        // 组装每首歌的输出数据
        $rows = [];
        foreach ($songs as $song) {
            $ref = $song['server'] . '_' . $song['song_id'];
            $pic = $covers[$ref] ?? '';
            if ($pic === '') $pic = $s3Covers[$ref] ?? '';
            if ($pic === '' && $apiBase !== '' && $song['song_id'] !== '') {
                $pic = $apiBase . '?' . http_build_query([$rServer => $song['server'], $rType => 'pic', $pId => $song['song_id']]);
            }
            $rows[] = [
                'id'        => (int)$song['id'],
                'name'      => (string)$song['name'],
                'artist'    => (string)$song['artist'],
                'pic'       => (string)$pic,
                'isMissing' => (int)($song['missing'] ?? 0) === 1,
            ];
        }

        // 生成播放器搜索令牌
        $searchToken = '';
        $firstKey = (string)($pl['api_key'] ?? '');
        if ($firstKey !== '') {
            $jwtSecret = jwt_secret_or_503($cfg);   // 库不可达 ⇒ 503 + 人话，不再 500 白屏
            $searchToken = jwt_encode(['key' => $firstKey, 'exp' => time() + 300, 'iat' => time()], $jwtSecret);
        }

        // 汇总页面数据
        return [
            'ok'           => true,
            'pl'           => $pl,
            'songs'        => $rows,
            'songCount'    => count($rows),
            'missingCount' => $missingCount,
            'isRemote'     => $isRemote,
            'pageData'     => [
                'csrf'        => csrf_token(),
                'playlistId'  => $plId,
                'searchToken' => $searchToken,
                'apiBase'     => $apiBase,
                'pId'         => $pId,
                'pServer'     => $pl['server'] ?? 'netease',
                'pType'       => $pl['type'] ?? '',
                'plName'      => $pl['name'],
                'plCoverMode' => $pl['cover_mode'] ?? 'auto',
            ],
        ];
    }
}

// 取页面所需数据
$plData = playlist_detail_load($db, $cfg, $_SESSION, $_GET);
if (empty($plData['ok'])) {
    echo '<div class="empty">' . htmlspecialchars((string)$plData['error'], ENT_QUOTES, 'UTF-8') . '</div>';
    return;
}

$pl           = $plData['pl'];
$songs        = $plData['songs'];
$songCount    = $plData['songCount'];
$missingCount = $plData['missingCount'];
$isRemote     = $plData['isRemote'];
?>
<div id="playlist-detail-data" data-page='<?= htmlspecialchars(json_encode($plData['pageData']), ENT_QUOTES, 'UTF-8') ?>' style="display:none"></div>
<div class="card">
  <div class="card-header">
    <a href="<?= admin_url('config') ?>" class="btn-sm" style="margin-right:8px">&larr;</a>
    <span class="card-title"><?= htmlspecialchars($pl['name'], ENT_QUOTES, 'UTF-8') ?></span>
    <div style="margin-left:auto;display:flex;gap:6px;align-items:center">
      <span class="key-badge"><?= $isRemote ? '远程' : '自建' ?></span>
      <span class="key-badge"><?= $pl['server'] === 'netease' ? '网易' : 'QQ' ?></span>
      <span class="key-badge"><?= $songCount ?> 首</span>
    </div>
  </div>

  <div class="key-card-info" style="margin-bottom:12px">
    <span class="key-card-user"><?= htmlspecialchars($pl['username'] ?? '-', ENT_QUOTES, 'UTF-8') ?></span>
    <code class="key-card-code"><?= htmlspecialchars($pl['api_key'] ?? '', ENT_QUOTES, 'UTF-8') ?></code>
    <?php if (!$isRemote): ?>
    <button type="button" class="btn-sm" id="openEditPlBtn" style="margin-left:auto;font-size:10px;padding:3px 10px">编辑</button>
    <?php endif; ?>
  </div>

  <?php if ($isRemote): ?>
  <div style="padding:8px 0;font-size:12px;color:rgba(0,0,0,.4)">
    远程歌单 ID：<strong><?= htmlspecialchars($pl['remote_id'], ENT_QUOTES, 'UTF-8') ?></strong>
    · 本地快照 <strong><?= $songCount ?></strong> 首<?= $missingCount ? '（' . $missingCount . ' 首上游已下架，播放器自动跳过）' : '' ?>
  </div>
  <div class="key-card-actions" style="margin:8px 0 12px">
    <button type="button" class="btn-sm" id="syncPlBtn">从上游同步</button>
    <span class="key-section-hint" style="margin-left:8px">同步只追加新歌、保留你排好的顺序</span>
  </div>
  <?php if (!$songs): ?>
  <div class="custom-song-empty">尚未同步到本地快照（点上面「从上游同步」，或直接让播放器实时拉取）</div>
  <?php endif; ?>
  <?php else: ?>

  <div class="key-section-label" style="margin-bottom:8px">
    <span>歌曲列表</span>
    <span class="key-section-hint">仅网易</span>
  </div>
  <?php endif; ?>

  <?php if (!$isRemote || $songs): ?>
  <div class="song-card-grid">
    <?php foreach ($songs as $song): ?>
    <div class="song-card<?= $song['isMissing'] ? ' is-missing' : '' ?>" data-row-id="<?= $song['id'] ?>">
      <?php if (!$isRemote): ?>
      <div class="song-card-order">
        <button type="button" class="song-ord-btn" data-row-id="<?= $song['id'] ?>" data-dir="top" title="置顶">⇤</button>
        <button type="button" class="song-ord-btn" data-row-id="<?= $song['id'] ?>" data-dir="up" title="上移">↑</button>
        <button type="button" class="song-ord-btn" data-row-id="<?= $song['id'] ?>" data-dir="down" title="下移">↓</button>
      </div>
      <?php endif; ?>
      <div class="song-card-cover">
        <?php if ($song['pic']): ?>
        <img src="<?= htmlspecialchars($song['pic'], ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
        <div class="song-card-placeholder" style="display:none"><?= svg('play') ?></div>
        <?php else: ?>
        <div class="song-card-placeholder"><?= svg('play') ?></div>
        <?php endif; ?>
      </div>
      <div class="song-card-body">
        <span class="song-card-name" title="<?= htmlspecialchars($song['name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($song['name'], ENT_QUOTES, 'UTF-8') ?></span>
        <span class="song-card-artist" title="<?= htmlspecialchars($song['artist'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($song['artist'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php if ($song['isMissing']): ?><span class="song-card-tag">已下架</span><?php endif; ?>
      </div>
      <?php if (!$isRemote): ?>
      <button type="button" class="song-card-remove" data-row-id="<?= $song['id'] ?>" title="删除">✕</button>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (!$isRemote): ?>
  <div class="key-card-actions" style="margin-top:12px">
    <button type="button" class="btn-sm" id="openSearchBtn">搜索添加</button>
    <button type="button" class="btn-sm" id="openManualBtn">手动添加</button>
  </div>
  <?php endif; ?>
</div>

<div id="songModal" class="song-modal" style="display:none">
  <div class="song-modal-backdrop"></div>
  <div class="song-modal-panel">
    <div class="song-modal-header">
      <span class="song-modal-title">搜索歌曲 · 网易云</span>
      <button type="button" class="song-modal-close">✕</button>
    </div>
    <div class="song-modal-search">
      <input type="text" id="songSearchInput" class="form-input" placeholder="输入歌曲名或歌手…" style="flex:1">
      <button type="button" id="songSearchBtn" class="btn-sm" style="flex-shrink:0">搜索</button>
    </div>
    <div id="songSearchResults" class="song-modal-results">
      <div class="song-modal-hint">输入关键词搜索网易云音乐</div>
    </div>
  </div>
</div>

<div id="manualModal" class="song-modal" style="display:none">
  <div class="song-modal-backdrop"></div>
  <div class="song-modal-panel" style="max-width:380px">
    <div class="song-modal-header">
      <span class="song-modal-title">手动添加歌曲</span>
      <button type="button" class="song-modal-close">✕</button>
    </div>
    <div class="song-modal-body">
      <label class="create-field-label">歌曲 ID</label>
      <input type="text" id="manualSongId" class="form-input" placeholder="如 473403185" style="margin-bottom:12px">
      <label class="create-field-label">歌曲名称</label>
      <input type="text" id="manualSongName" class="form-input" placeholder="如 晴天" style="margin-bottom:12px">
      <label class="create-field-label">歌手</label>
      <input type="text" id="manualSongArtist" class="form-input" placeholder="如 周杰伦" style="margin-bottom:12px">
      <label class="create-field-label">平台</label>
      <select id="manualSongServer" class="form-input" style="margin-bottom:16px">
        <option value="netease">网易云音乐</option>
        <option value="tencent">QQ音乐</option>
      </select>
      <button type="button" id="manualSongSubmit" class="btn btn-primary btn-block">添加</button>
    </div>
  </div>
</div>

<?php if (!$isRemote): ?>
<div id="editPlModal" class="song-modal" style="display:none">
  <div class="song-modal-backdrop"></div>
  <div class="song-modal-panel" style="max-width:400px">
    <div class="song-modal-header">
      <span class="song-modal-title">编辑歌单</span>
      <button type="button" class="song-modal-close">✕</button>
    </div>
    <div class="song-modal-body">
      <label class="create-field-label">歌单名称</label>
      <input type="text" id="editPlName" class="form-input" style="margin-bottom:12px">
      <label class="create-field-label">封面</label>
      <div class="create-cover-row">
        <label class="create-cover-opt">
          <input type="radio" name="edit_pl_cover" value="auto" checked>
          <span>自动</span>
        </label>
        <label class="create-cover-opt">
          <input type="radio" name="edit_pl_cover" value="url">
          <span>URL</span>
        </label>
      </div>
      <input type="text" id="editPlCoverUrl" class="form-input" placeholder="图片 URL（选 URL 时填写）" style="margin-bottom:16px;display:none">
      <button type="button" id="editPlSubmit" class="btn btn-primary btn-block">保存</button>
    </div>
  </div>
</div>
<?php endif; ?>
