<?php defined('MAPI_ADMIN') or die('禁止直接访问');
$csrf = csrf_token();

// 密钥列表（含用户信息）
$keys = [];
if (($_SESSION['admin_is_admin'] ?? 99) === 0) {
    $r = $db->query("SELECT k.*, u.username FROM mapi_keys k LEFT JOIN mapi_users u ON k.user_id=u.id ORDER BY k.id DESC");
} else {
    $r = $db->query("SELECT k.*, u.username FROM mapi_keys k LEFT JOIN mapi_users u ON k.user_id=u.id WHERE k.user_id=" . (int)$_SESSION['admin_id'] . " ORDER BY k.id DESC");
}
if ($r) while ($row = $r->fetch_assoc()) $keys[] = $row;

// 歌单分组数据
$playlistsByKey = [];
$r = $db->query("SELECT id, key_id, name, type, remote_id, server, cover_url, cover_mode, sort_order FROM mapi_playlists ORDER BY sort_order ASC, id ASC");
if ($r) while ($row = $r->fetch_assoc()) {
    $kid = (int)$row['key_id'];
    if (!isset($playlistsByKey[$kid])) $playlistsByKey[$kid] = [];
    $playlistsByKey[$kid][] = $row;
}

// 歌曲分组数据
$songsByPlaylist = [];
$r = $db->query("SELECT id, playlist_id, song_id, name, artist, server, sort_order FROM mapi_songs ORDER BY sort_order ASC, id ASC");
if ($r) while ($row = $r->fetch_assoc()) {
    $pid = (int)$row['playlist_id'];
    if (!isset($songsByPlaylist[$pid])) $songsByPlaylist[$pid] = [];
    $songsByPlaylist[$pid][] = $row;
}

$playlistsByKid = $playlistsByKey ?? [];
$songsByPl = $songsByPlaylist ?? [];
$searchToken = '';
if (!empty($keys)) {
    $firstKey = $keys[0]['api_key'] ?? '';
    if ($firstKey) {
        $jwtSecret = $cfg['api']['jwt_secret'] ?? hash('sha256', ($cfg['db']['password'] ?? '') . ($cfg['site']['url'] ?? ''));
        $searchToken = jwt_encode(['key' => $firstKey, 'exp' => time() + 300, 'iat' => time()], $jwtSecret);
    }
}
?>
<div class="card">
  <div class="card-header"><span class="card-title"><?= svg('config') ?> 音乐配置</span></div>
  <form method="post" action="?action=config"><input type="hidden" name="_csrf" value="<?= $csrf ?>">
    <label class="form-check">
      <input type="hidden" name="auto_theme" value="0">
      <input type="checkbox" name="auto_theme" value="1"<?= $autoTheme ? ' checked' : '' ?>>
      跟随时间自动切换深色主题
    </label>
    <div style="padding:6px 0 12px;font-size:13px;color:rgba(0,0,0,.5)">
      <div style="margin-bottom:8px;font-weight:600">主题模式（自动关闭时生效）</div>
      <label class="form-radio">
        <input type="radio" name="theme_mode" value="light"<?= $themeMode === 'light' ? ' checked' : '' ?>>
        浅色
      </label>
      <label class="form-radio">
        <input type="radio" name="theme_mode" value="dark"<?= $themeMode === 'dark' ? ' checked' : '' ?>>
        深色
      </label>
    </div>
    <label class="form-check">
      <input type="hidden" name="lyrics_default" value="0">
      <input type="checkbox" name="lyrics_default" value="1"<?= $lyricsDefault ? ' checked' : '' ?>>
      默认开启歌词
    </label>
    <label class="form-check">
      <input type="hidden" name="autoplay_default" value="0">
      <input type="checkbox" name="autoplay_default" value="1"<?= $autoplayDefault ? ' checked' : '' ?>>
      自动播放
    </label>
    <?php
    // 歌词条字体：外部 URL 与上传的字体文件二选一（上传优先；填 URL 会替换掉上传的文件）
    $s3ok = s3_available();
    $lrcFont = lrc_font_get($db, (int)$_SESSION['admin_id']);
    $lrcFontIsCss = ($lrcFont['effective'] !== '' && (bool)preg_match('/\.css(\?|#|$)/i', $lrcFont['effective']));
    $lrcFontUploadedUrl = ($lrcFont['key'] !== '') ? $lrcFont['effective'] : '';
    $lrcFontFamily = lrc_font_clean_name($lrcFont['name']);
    if ($lrcFontFamily === '' && $lrcFont['effective'] !== '' && !$lrcFontIsCss) $lrcFontFamily = 'MsapiLrcFont';
    $lrcFontSizeVal = ((int)$lrcFont['size'] > 0) ? (int)$lrcFont['size'] : '';
    $lrcFontFallback = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,'PingFang SC','Microsoft YaHei',sans-serif";
    $lrcFontPreviewCss = ($lrcFontFamily !== '' ? "font-family:'" . htmlspecialchars($lrcFontFamily, ENT_QUOTES) . "'," . $lrcFontFallback . ';' : '')
        . 'font-size:' . ($lrcFontSizeVal !== '' ? (int)$lrcFontSizeVal : 15) . 'px;';
    ?>
    <?php if ($lrcFont['effective'] !== '' && !$lrcFontIsCss): ?>
    <style id="lrcFontPreviewStyle">@font-face{font-family:'<?= htmlspecialchars($lrcFontFamily !== '' ? $lrcFontFamily : 'MsapiLrcFont', ENT_QUOTES) ?>';src:url('<?= htmlspecialchars(lrc_font_clean_url($lrcFont['effective']), ENT_QUOTES) ?>');font-display:swap}</style>
    <?php elseif ($lrcFont['effective'] !== ''): ?>
    <link id="lrcFontPreviewLink" rel="stylesheet" href="<?= htmlspecialchars(lrc_font_clean_url($lrcFont['effective']), ENT_QUOTES) ?>">
    <?php endif; ?>
    <?php /* 与上面的主题 / 歌词开关用一条分割线隔开 */ ?>
    <hr style="border:none;border-top:1px solid var(--line,rgba(0,0,0,.06));margin:10px 0 16px">
    <div style="padding:2px 0 12px;font-size:13px;color:rgba(0,0,0,.5)">
      <div style="margin-bottom:8px;font-weight:600">歌词条字体</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input id="lrcFontUrl" type="url" name="lrc_font_url" maxlength="500" placeholder="字体 URL（.woff2/.woff/.ttf/.otf 或 .css）"
               value="<?= htmlspecialchars($lrcFont['url'], ENT_QUOTES) ?>"
               style="flex:1 1 300px;min-width:200px;padding:7px 10px;border-radius:8px;border:1px solid var(--input-bd,rgba(128,128,128,.4));background:var(--input-bg,transparent);color:var(--ink,inherit)">
        <button type="button" class="btn btn-sm" id="lrcFontUpload"<?= $s3ok ? '' : ' disabled title="未配置存储，只能填字体 URL"' ?>>上传字体</button>
        <button type="button" class="btn btn-sm" id="lrcFontClear">清除</button>
      </div>
      <input type="file" id="lrcFontFile" accept=".woff2,.woff,.ttf,.otf" style="position:absolute;width:0;height:0;opacity:0;overflow:hidden;pointer-events:none">
      <input type="hidden" id="lrcFontUploaded" value="<?= htmlspecialchars($lrcFontUploadedUrl, ENT_QUOTES) ?>">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:8px">
        <label style="display:flex;align-items:center;gap:6px">字体名
          <input id="lrcFontName" type="text" name="lrc_font_name" maxlength="100" placeholder="如 汉仪文黑（可留空）" value="<?= htmlspecialchars($lrcFont['name'], ENT_QUOTES) ?>"
                 style="width:170px;padding:6px 9px;border-radius:8px;border:1px solid var(--input-bd,rgba(128,128,128,.4));background:var(--input-bg,transparent);color:var(--ink,inherit)">
        </label>
        <label style="display:flex;align-items:center;gap:6px">字号
          <input id="lrcFontSize" type="number" name="lrc_font_size" min="0" max="200" step="1" placeholder="默认" value="<?= $lrcFontSizeVal ?>"
                 style="width:78px;padding:6px 9px;border-radius:8px;border:1px solid var(--input-bd,rgba(128,128,128,.4));background:var(--input-bg,transparent);color:var(--ink,inherit)"> px
        </label>
      </div>
      <div id="lrcFontStatus" style="margin-top:6px;font-size:12px;color:var(--ink-soft,rgba(128,128,128,.75))"></div>
      <div id="lrcFontPreview" style="margin-top:8px;padding:8px 10px;border-radius:8px;border:1px dashed rgba(128,128,128,.35);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:700;<?= $lrcFontPreviewCss ?>">示例：风吹过山岗，歌词条的字体会长这样</div>
      <div style="margin-top:6px;font-size:12px;color:var(--ink-soft,rgba(128,128,128,.75))">上传与 URL 二选一，填了 URL 会替换掉上传的字体文件，点「清除」恢复默认字体。</div>
    </div>
    <?php /* 初始位置已并入下面的「播放器皮肤」——每个皮肤各自记住自己的位置，不再全局共用一份 */ ?>
    <?php /* 与上面的「歌词条字体」用一条分割线隔开 */ ?>
    <hr style="border:none;border-top:1px solid var(--line,rgba(0,0,0,.06));margin:10px 0 16px">
    <div style="padding:2px 0 12px;font-size:13px;color:rgba(0,0,0,.5)">
      <div style="margin-bottom:8px;font-weight:600">播放器皮肤<span style="margin-left:6px;font-weight:400;font-size:12px;color:rgba(0,0,0,.42)">（该功能还在内测中）</span></div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <?php require_once __DIR__ . '/../../modules/registry.php';
        foreach (msapi_skins() as $sk):
          if (!empty($sk['hidden'])) continue;
          $on = ($playerSkin === $sk['name']);
          $skPos = skin_pos_of($playerSkinCfg, $sk['name'], $playerPos);
          list($skSide, $skY) = explode(':', $skPos);
          $skId = 'skin_' . preg_replace('/[^a-z0-9_]/i', '', $sk['name']); ?>
          <div data-skin-card data-skin="<?= $skId ?>"
               style="flex:1 1 250px;min-width:230px;cursor:pointer;border:1px solid <?= $on ? 'rgba(108,92,231,.85)' : 'rgba(128,128,128,.35)' ?>;background:<?= $on ? 'rgba(108,92,231,.10)' : 'transparent' ?>;border-radius:10px;padding:10px 12px">
            <input type="radio" id="<?= $skId ?>" name="player_skin" value="<?= htmlspecialchars($sk['name']) ?>"<?= $on ? ' checked' : '' ?>
                   style="position:absolute;opacity:0;width:0;height:0;pointer-events:none">
            <div style="display:flex;align-items:center;gap:6px">
              <strong style="font-size:13px"><?= htmlspecialchars($sk['displayName']) ?></strong>
              <code style="font-size:11px;color:var(--ink-soft,rgba(128,128,128,.75))"><?= htmlspecialchars($sk['name']) ?></code>
            </div>
            <div style="margin-top:4px;font-size:12px;line-height:1.6;color:var(--ink-dim,rgba(128,128,128,.9))"><?= htmlspecialchars($sk['description']) ?></div>
            <div class="rs-pos-zone" style="margin-top:8px;padding-top:8px;border-top:1px dashed rgba(128,128,128,.35);font-size:12px">
              <div style="margin-bottom:4px;color:var(--ink-dim,rgba(128,128,128,.9))">初始位置（访客首次打开）</div>
              <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <label style="display:flex;align-items:center;gap:4px;cursor:pointer"><input type="radio" name="pos_side_<?= htmlspecialchars($sk['name']) ?>" value="right"<?= $skSide === 'right' ? ' checked' : '' ?>>靠右</label>
                <label style="display:flex;align-items:center;gap:4px;cursor:pointer"><input type="radio" name="pos_side_<?= htmlspecialchars($sk['name']) ?>" value="left"<?= $skSide === 'left' ? ' checked' : '' ?>>靠左</label>
                <label style="display:flex;align-items:center;gap:5px;cursor:pointer">垂直
                  <input type="number" name="pos_y_<?= htmlspecialchars($sk['name']) ?>" min="0" max="100" step="1" value="<?= (int)$skY ?>"
                         style="width:64px;padding:3px 6px;border-radius:6px;border:1px solid var(--input-bd,rgba(128,128,128,.4));background:var(--input-bg,transparent);color:var(--ink,inherit)">
                </label>
              </div>
              <div style="margin-top:4px;color:var(--ink-soft,rgba(128,128,128,.75))">0 = 最上，100 = 最下</div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:6px;font-size:12px;color:var(--ink-soft,rgba(128,128,128,.75))">
        选中的皮肤对本账号下所有密钥生效；<strong>每个皮肤各自记住自己的初始位置</strong>。
      </div>
    </div>
    <button class="btn btn-primary btn-block" style="margin-top:8px">保存</button>
  </form>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">密钥歌单</span></div>
  <?php if (empty($keys)): ?><div class="empty">暂无密钥</div>
  <?php else: foreach ($keys as $k):
    $kid = (int)$k['id'];
    $myPlaylists = $playlistsByKid[$kid] ?? [];
  ?>
  <div class="key-card" data-api-key="<?= htmlspecialchars($k['api_key']) ?>">
    <div class="key-card-header">
      <div class="key-card-info">
        <span class="key-card-user"><?= htmlspecialchars($k['username'] ?? '-') ?></span>
        <code class="key-card-code"><?= htmlspecialchars(mask_key($k['api_key'])) ?></code>
        <button class="btn-copy" onclick="var k=this.closest('.key-card').dataset.apiKey;navigator.clipboard.writeText(k);var org=this.innerHTML;this.innerHTML='<svg viewBox=&quot;0 0 24 24&quot; fill=&quot;none&quot; stroke=&quot;currentColor&quot; stroke-width=&quot;2&quot;><polyline points=&quot;20 6 9 17 4 12&quot;/></svg>';setTimeout(function(){this.innerHTML=org},1500)"><?= svg('copy') ?></button>
      </div>
      <div class="key-card-badges">
        <span class="key-badge"><?= count($myPlaylists) ?> 歌单</span>
      </div>
    </div>

    <div class="playlist-grid" data-key-id="<?= $kid ?>">
      <?php
      // 封面统一存在数据库（mapi_playlists.cover_data），这里一次性把这批歌单的封面取出来
      $_plCoverRows = [];
      if (!empty($myPlaylists)) {
          $_ids = [];
          foreach ($myPlaylists as $_p) $_ids[] = (int)$_p['id'];
          if ($_ids) {
              $_cr = $db->query('SELECT id, cover_url, cover_data FROM mapi_playlists WHERE id IN (' . implode(',', $_ids) . ')');
              if ($_cr) while ($_crow = $_cr->fetch_assoc()) $_plCoverRows[(int)$_crow['id']] = $_crow;
          }
      }
      foreach ($myPlaylists as $pl):
        $plId = (int)$pl['id'];
        $plSongs = $songsByPl[$plId] ?? [];
        $songCount = count($plSongs);
        $coverB64 = $_plCoverRows[$plId]['cover_data'] ?? '';
        $coverSrc = $coverB64 ?: (string)($_plCoverRows[$plId]['cover_url'] ?? $pl['cover_url'] ?: '');
        if (!$coverB64 && $coverSrc && !preg_match('/^https?:\/\//', $coverSrc)) {
            $ab = $cfg['api']['base_url'] ?? '';
            $rs = $cfg['api']['param_server'] ?? 'server';
            $rt = $cfg['api']['param_type'] ?? 'type';
            $ri = $cfg['api']['param_id'] ?? 'id';
            if ($ab && preg_match('/[?&]' . preg_quote($ri, '/') . '=([^&]+)/', $coverSrc, $cm)) {
                $sv = $pl['server'] ?: 'netease';
                if (preg_match('/server=([^&]+)/', $coverSrc, $sm)) $sv = $sm[1];
                $coverSrc = $ab . '?' . http_build_query([$rs => $sv, $rt => 'pic', $ri => $cm[1]]);
            }
        }
        $isRemote = $pl['type'] === 'remote';
      ?>
      <?php if ($isRemote): ?>
      <div class="playlist-card playlist-card-remote" data-pl-id="<?= $plId ?>" data-pl-name="<?= htmlspecialchars($pl['name']) ?>" data-remote-id="<?= htmlspecialchars($pl['remote_id']) ?>" data-server="<?= htmlspecialchars($pl['server']) ?>" data-cover-mode="<?= htmlspecialchars($pl['cover_mode']) ?>">
      <?php else: ?>
      <a href="?action=playlist-detail&id=<?= $plId ?>" class="playlist-card" data-pl-id="<?= $plId ?>" draggable="false">
      <?php endif; ?>
        <div class="playlist-card-cover">
          <?php if ($coverSrc): ?>
          <img src="<?= htmlspecialchars($coverSrc) ?>" alt="" loading="lazy">
          <?php else: ?>
          <div class="playlist-card-placeholder playlist-card-initial" data-need-cover="<?= $plId ?>"><?= mb_strtoupper(mb_substr($pl['name'], 0, 1, 'UTF-8')) ?></div>
          <?php endif; ?>
        </div>
        <div class="playlist-card-body">
          <span class="playlist-card-name"><?= htmlspecialchars($pl['name']) ?></span>
          <span class="playlist-card-meta"><?= $isRemote ? ($pl['server'] === 'netease' ? '网易' : 'QQ') . ' 远程' : $songCount . ' 首' ?></span>
        </div>
        <button type="button" class="playlist-card-delete danger" data-pl-id="<?= $plId ?>" title="删除">✕</button>
      <?php if ($isRemote): ?>
      </div>
      <?php else: ?>
      </a>
      <?php endif; ?>
      <?php endforeach; ?>
      <button type="button" class="playlist-card playlist-card-add" data-key-id="<?= $kid ?>">
        <div class="playlist-card-cover">
          <div class="playlist-card-placeholder"><?= svg('plus') ?></div>
        </div>
        <div class="playlist-card-body">
          <span class="playlist-card-name">添加歌单</span>
          <span class="playlist-card-meta">自建或远程</span>
        </div>
      </button>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<div id="createModal" class="song-modal" style="display:none">
  <div class="song-modal-backdrop"></div>
  <div class="song-modal-panel" style="max-width:400px">
    <div class="song-modal-header">
      <span class="song-modal-title">添加歌单</span>
      <button type="button" class="song-modal-close">✕</button>
    </div>
    <div class="song-modal-body">
      <div class="create-type-row">
        <label class="create-type-opt">
          <input type="radio" name="create_type" value="custom" checked>
          <span class="create-type-label">自建歌单</span>
          <span class="create-type-desc">搜索添加歌曲</span>
        </label>
        <label class="create-type-opt">
          <input type="radio" name="create_type" value="remote">
          <span class="create-type-label">远程歌单</span>
          <span class="create-type-desc">输入歌单 ID</span>
        </label>
      </div>

      <div id="createCustomFields">
        <label class="create-field-label">歌单名称</label>
        <input type="text" id="createName" class="form-input" placeholder="如 我喜欢的" style="margin-bottom:12px">
      </div>

      <div id="createRemoteFields" style="display:none">
        <label class="create-field-label">歌单名称</label>
        <input type="text" id="createRemoteName" class="form-input" placeholder="如 日语流行" style="margin-bottom:12px">
        <label class="create-field-label">歌单 ID</label>
        <input type="text" id="createRemoteId" class="form-input" placeholder="如 3778678" style="margin-bottom:12px">
        <label class="create-field-label">平台</label>
        <select id="createRemoteServer" class="form-input" style="margin-bottom:12px">
          <option value="netease">网易云音乐</option>
          <option value="tencent">QQ音乐</option>
        </select>
      </div>

      <label class="create-field-label">封面</label>
      <div class="create-cover-row">
        <label class="create-cover-opt">
          <input type="radio" name="create_cover" value="auto" checked>
          <span>自动</span>
        </label>
        <label class="create-cover-opt">
          <input type="radio" name="create_cover" value="first_song">
          <span>首曲封面</span>
        </label>
        <label class="create-cover-opt">
          <input type="radio" name="create_cover" value="url">
          <span>URL</span>
        </label>
      </div>
      <input type="text" id="createCoverUrl" class="form-input" placeholder="图片 URL（选 URL 时填写）" style="margin-bottom:16px;display:none">

      <button type="button" id="createSubmit" class="btn btn-primary btn-block">创建</button>
    </div>
  </div>
</div>

<div id="editRemoteModal" class="song-modal" style="display:none">
  <div class="song-modal-backdrop"></div>
  <div class="song-modal-panel" style="max-width:400px">
    <div class="song-modal-header">
      <span class="song-modal-title">编辑远程歌单</span>
      <button type="button" class="song-modal-close">✕</button>
    </div>
    <div class="song-modal-body">
      <input type="hidden" id="editPlId">
      <label class="create-field-label">歌单名称</label>
      <input type="text" id="editName" class="form-input" style="margin-bottom:12px">
      <label class="create-field-label">歌单 ID</label>
      <input type="text" id="editRemoteId" class="form-input" style="margin-bottom:12px">
      <label class="create-field-label">平台</label>
      <select id="editServer" class="form-input" style="margin-bottom:12px">
        <option value="netease">网易云音乐</option>
        <option value="tencent">QQ音乐</option>
      </select>
      <label class="create-field-label">封面</label>
      <div class="create-cover-row">
        <label class="create-cover-opt">
          <input type="radio" name="edit_cover" value="auto" checked>
          <span>自动</span>
        </label>
        <label class="create-cover-opt">
          <input type="radio" name="edit_cover" value="url">
          <span>URL</span>
        </label>
      </div>
      <input type="text" id="editCoverUrl" class="form-input" placeholder="图片 URL" style="margin-bottom:16px;display:none">
      <button type="button" id="editSubmit" class="btn btn-primary btn-block">保存</button>
    </div>
  </div>
</div>

<div id="config-data" data-csrf="<?= htmlspecialchars($csrf) ?>" hidden></div>