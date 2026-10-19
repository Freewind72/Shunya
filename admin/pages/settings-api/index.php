<?php
// 页面文件引导序列：路由基础设施（非业务板块），除下方 INDEX_ACTION 的值外，
// 16 份页面文件逐字相同。对外地址 /admin/<页面>（无后缀）由根 index.php 路由器经通用桥
// 回退引导（或经 /admin/index.php 裸入口）；直接物理访问本文件时由本引导块声明后台上下文
// 与 action，交给 includes/front.php 跑完整判定链；判定通过后 front.php 会把本文件再载入
// 一趟执行页面主体（此刻 MAPI_ADMIN 已定义，跳过本块），主体恰好执行一次——下方
// return 拦住第一趟继续落到主体。主体内容在引导块之后，判定拦截时不会执行。
if (!defined('MAPI_ADMIN')) {
    define('MAPI_ADMIN', true);
    define('INDEX_ACTION', 'settings-api');
    require __DIR__ . '/../../includes/front.php';
    return;
}

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
<?php
/* ★ 版本指纹（用户实测反馈驱动）：一眼分辨「看到的是新代码还是旧缓存」
   ⚠ 必须落在 PHP 标签内：上方是 HTML 段，别把 define 裸露在标签外
     （否则整条 define 会被当页面文字原样打印 —— 真发生过，靠浏览器验证抓到）。
   ⚠ 2026-10-16 用户指令：**不再显示在页面上**（原来渲染了两遍）。它现在只作**本页版本标识**：
     改本页内联 JS 时同步改这里，排查"是不是旧缓存"时直接看本文件这一行即可。
   ⚠ 取值格式必须是 `月-日 时:分`（检查表按这个形状认）——不是给人看的装饰，是排查锚点。 */
define('SETTINGS_API_JS_VER', '10-16 08:52');
?>
<?php if (($_SESSION['admin_is_admin'] ?? 99) === 0): ?>
<!-- ★ 本页内联样式：只针对**本页要用的** toast 形态，不动共享的 base.css / base.js。
     为什么需要：顶部浮出提示要能显示**多行失败原因**（来源逐字段 + 长度 + 诊断），
     而共享的 `#toast` 是单行、固定 2500ms ⇒ 长诊断读不完、还会被压成一行。
     ⚠ 选择器用 `#toast[class~="err"]`：`~=` 按空格分词匹配，配合 JS 里写出的
       `"show err "`（**尾随空格**）⇒ 共享的 `#toast.err{…}` 不命中（分词里没有精确的 `err`），
       本页这条命中 ⇒ 红样式照样生效，且不会跟 base.js 的 2.5s 清类抢。 -->
<style>
  #toast[class~="err"] { white-space: pre-line; max-width: min(680px, 92vw); text-align: left; line-height: 1.65; }
  #toast.ok             { white-space: pre-line; max-width: min(680px, 92vw); text-align: left; line-height: 1.65; }
</style>
<div class="section-header"><span class="section-header-inner"><?= svg('api') ?> 接口</span></div>
<div class="cards-grid">
<div class="card">
  <div class="card-header"><span class="card-title"><?= svg('key') ?> 密钥数量限制</span></div>
  <form method="post" action="<?= admin_url('settings-api') ?>"><input type="hidden" name="_key_limit_submit" value="1"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="form-group">
      <label class="form-label">每个用户最多创建密钥数量</label>
      <input class="form-input" type="number" name="key_limit" value="<?= (int)$keyLimit['limit'] ?>" min="1" max="100" required>
      <small style="display:block;margin-top:6px;color:rgba(0,0,0,.38);font-size:12px">管理员不受此限制 · 默认 1 个</small>
    </div>
    <button class="btn btn-primary btn-block">保存</button>
  </form>
</div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title"><?= svg('play') ?> 音乐 API</span></div>
  <form id="api-form" method="post" action="<?= admin_url('settings-api') ?>"><input type="hidden" name="_api_submit" value="1"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="form-group">
    <label class="form-label">Meting API 地址</label>
    <input class="form-input" name="api_meting" value="<?= htmlspecialchars($mapiApi['meting']) ?>" placeholder="https://mapi.bmwy72.top/api">
    </div>
    <div class="form-group">
    <label class="form-label">QQ 音乐 Referer</label>
    <input class="form-input" name="api_qq_referer" value="<?= htmlspecialchars($mapiApi['qq_referer']) ?>" placeholder="https://y.qq.com/">
    </div>
    <div class="form-group">
    <label class="form-label">QQ 封面 CDN</label>
    <input class="form-input" name="api_qq_cover" value="<?= htmlspecialchars($mapiApi['qq_cover']) ?>" placeholder="https://y.gtimg.cn/music/photo_new/T002R300x300M000">
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
</div>
<?php endif; ?>
