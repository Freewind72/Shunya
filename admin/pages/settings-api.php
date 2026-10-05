<?php defined('MAPI_ADMIN') or die('禁止直接访问');
// settings-api.php — 接口（密钥限制 / 音乐 API）
if ((($_SESSION['admin_is_admin'] ?? 99) > 1)) { echo '<div class="card"><div class="empty">无权限</div></div>'; return; }

$keyLimit = ['limit' => 1];
$r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='key_limit'");
if ($r && $row = $r->fetch_assoc()) {
    $saved = json_decode($row['config_value'], true);
    if (is_array($saved)) $keyLimit = array_merge($keyLimit, $saved);
}

$mapiApi = ['meting'=>'','qq_referer'=>'','qq_cover'=>'','fields'=>['title'=>'title','artist'=>'author','url'=>'url','pic'=>'pic','lrc'=>'lrc'],'param_id'=>'id','param_auth'=>'auth','req_params'=>['server'=>'server','type'=>'type','id'=>'id']];
$r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='mapi_api'");
if ($r && $row = $r->fetch_assoc()) {
    $saved = json_decode($row['config_value'], true);
    if (is_array($saved)) {
        if (isset($saved['fields']) && is_array($saved['fields'])) {
            $saved['fields'] = array_merge($mapiApi['fields'], $saved['fields']);
        }
        if (isset($saved['req_params']) && is_array($saved['req_params'])) {
            $saved['req_params'] = array_merge($mapiApi['req_params'], $saved['req_params']);
        }
        $mapiApi = array_merge($mapiApi, $saved);
    }
}
?>
<?php if (($_SESSION['admin_is_admin'] ?? 99) === 0): ?>
<div class="section-header"><span class="section-header-inner"><?= svg('api') ?> 接口</span></div>
<div class="cards-grid">
<div class="card">
  <div class="card-header"><span class="card-title"><?= svg('key') ?> 密钥数量限制</span></div>
  <form method="post" action="?action=settings-api"><input type="hidden" name="_key_limit_submit" value="1"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="form-group">
      <label class="form-label">每个用户最多创建密钥数量</label>
      <input class="form-input" type="number" name="key_limit" value="<?= (int)$keyLimit['limit'] ?>" min="1" max="100" required>
      <small style="display:block;margin-top:6px;color:rgba(0,0,0,.38);font-size:12px">管理员不受此限制 · 默认 1 个</small>
    </div>
    <button class="btn btn-primary btn-block">保存</button>
  </form>
</div>

<div class="card">
  <div class="card-header"><span class="card-title"><?= svg('play') ?> 音乐 API</span></div>
  <form method="post" action="?action=settings-api"><input type="hidden" name="_api_submit" value="1"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="form-row">
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">Meting API 地址</label>
        <input class="form-input" name="api_meting" value="<?= htmlspecialchars($mapiApi['meting']) ?>" placeholder="https://mapi.bmwy72.top/api">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">QQ 音乐 Referer</label>
        <input class="form-input" name="api_qq_referer" value="<?= htmlspecialchars($mapiApi['qq_referer']) ?>" placeholder="https://y.qq.com/">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">QQ 封面 CDN</label>
        <input class="form-input" name="api_qq_cover" value="<?= htmlspecialchars($mapiApi['qq_cover']) ?>" placeholder="https://y.gtimg.cn/music/photo_new/T002R300x300M000">
      </div>
    </div>
    <small style="display:block;margin-top:4px;color:rgba(0,0,0,.38);font-size:11px">Meting API 代理地址，用于获取歌单、封面、播放地址等</small>
    <div class="form-row">
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">回调 — ID</label>
        <input class="form-input" name="api_param_id" value="<?= htmlspecialchars($mapiApi['param_id']) ?>" placeholder="id">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">回调 — Auth</label>
        <input class="form-input" name="api_param_auth" value="<?= htmlspecialchars($mapiApi['param_auth']) ?>" placeholder="auth">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">请求 — 平台</label>
        <input class="form-input" name="api_req_server" value="<?= htmlspecialchars($mapiApi['req_params']['server']) ?>" placeholder="server">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">请求 — 类型</label>
        <input class="form-input" name="api_req_type" value="<?= htmlspecialchars($mapiApi['req_params']['type']) ?>" placeholder="type">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">请求 — 资源ID</label>
        <input class="form-input" name="api_req_id" value="<?= htmlspecialchars($mapiApi['req_params']['id']) ?>" placeholder="id">
      </div>
    </div>
    <small style="display:block;margin-top:4px;color:rgba(0,0,0,.38);font-size:11px">回调/请求的参数名，不同接口可能使用不同命名</small>
    <div class="form-row">
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">JSON — 歌名</label>
        <input class="form-input" name="api_field_title" value="<?= htmlspecialchars($mapiApi['fields']['title']) ?>" placeholder="title">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">JSON — 歌手</label>
        <input class="form-input" name="api_field_artist" value="<?= htmlspecialchars($mapiApi['fields']['artist']) ?>" placeholder="author">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">JSON — 播放地址</label>
        <input class="form-input" name="api_field_url" value="<?= htmlspecialchars($mapiApi['fields']['url']) ?>" placeholder="url">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">JSON — 封面</label>
        <input class="form-input" name="api_field_pic" value="<?= htmlspecialchars($mapiApi['fields']['pic']) ?>" placeholder="pic">
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">JSON — 歌词</label>
        <input class="form-input" name="api_field_lrc" value="<?= htmlspecialchars($mapiApi['fields']['lrc']) ?>" placeholder="lrc">
      </div>
    </div>
    <small style="display:block;margin-top:4px;color:rgba(0,0,0,.38);font-size:11px">JSON 返回字段名映射，不一致时在此修改</small>
    <button class="btn btn-primary btn-block">保存</button>
  </form>
</div>
</div>
<?php endif; ?>
