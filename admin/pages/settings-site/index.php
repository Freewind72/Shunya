<?php
// 页面文件引导序列：路由基础设施（非业务板块），除下方 INDEX_ACTION 的值外，
// 16 份页面文件逐字相同。对外地址 /admin/<页面>（无后缀）由根 index.php 路由器经通用桥
// 回退引导（或经 /admin/index.php 裸入口）；直接物理访问本文件时由本引导块声明后台上下文
// 与 action，交给 includes/front.php 跑完整判定链；判定通过后 front.php 会把本文件再载入
// 一趟执行页面主体（此刻 MAPI_ADMIN 已定义，跳过本块），主体恰好执行一次——下方
// return 拦住第一趟继续落到主体。主体内容在引导块之后，判定拦截时不会执行。
if (!defined('MAPI_ADMIN')) {
    define('MAPI_ADMIN', true);
    define('INDEX_ACTION', 'settings-site');
    require __DIR__ . '/../../includes/front.php';
    return;
}

// settings-site.php — 站点（公告 / 登录页外观）
if ((($_SESSION['admin_is_admin'] ?? 99) > 1)) { echo '<div class="card"><div class="empty">无权限</div></div>'; return; }

$announcement = ['enabled' => false, 'title' => '', 'content' => '', 'align' => 'left'];
$ra = $db->query("SELECT config_value FROM mapi_config WHERE config_key='announcement'");
if ($ra && $rowa = $ra->fetch_assoc()) {
    $saveda = json_decode($rowa['config_value'], true);
    if (is_array($saveda)) $announcement = array_merge($announcement, $saveda);
}

$loginTheme = 'light'; $loginBg = '';
$r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='login_theme'");
if ($r && $row = $r->fetch_assoc()) $loginTheme = $row['config_value'];
$r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='login_bg'");
if ($r && $row = $r->fetch_assoc()) $loginBg = $row['config_value'];

// ── 演示播放器（首页「启动播放器」按钮用的密钥）──────────────────────────
// 存放位置：`mapi_config.api_secrets` 行的 `demo_key` 字段，经 app_config 镜像到
// $CFG['api']['demo_key']，由根 index.php 读取并签发 15 分钟 JWT。
//
// ⚠ 安全口径（这是本卡片存在的全部意义）：
//   1) 这里选中的密钥会被**拼进首页 HTML**（`<script src="api.php?key=…">`），
//      任何访客右键看源码即可拿到 ⇒ 绝不能是站长正式密钥。
//   2) 所以下拉框**只列当前登录者自己的密钥**，超管也不例外 —— 不允许把别人的密钥
//      挂到首页上（那既是越权，也会让密钥泄露记到别人头上）。
//   3) 服务端保存时会**再校验一次归属**（见 handlers/settings.php 的 `_demo_submit`），
//      不信任前端传来的值。
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$myKeys = [];
if ($adminId > 0) {
    // 只取自己的、且已启用的密钥；停用的密钥首页不会显示播放器，选了也没用
    $kr = $db->query(
        "SELECT id, api_key, name FROM mapi_keys WHERE user_id=" . $adminId . " AND status=1 ORDER BY id ASC"
    );
    if ($kr) { while ($k = $kr->fetch_assoc()) $myKeys[] = $k; }
}
// 当前已设置的演示密钥（可能为空 = 不启用演示）
$secSaved = function_exists('read_mapi_config_section') ? read_mapi_config_section($db, 'api_secrets') : [];
$demoKeySaved = trim((string)($secSaved['demo_key'] ?? ''));
// 掩码显示：只露首 6 位与末 4 位，避免整串密钥出现在页面/截图里
$maskKey = static function (string $v): string {
    if ($v === '') return '';
    if (strlen($v) <= 12) return str_repeat('•', strlen($v));
    return substr($v, 0, 6) . str_repeat('•', 6) . substr($v, -4);
};
?>
<!-- ═══ 站点内容 ═══ -->
<div class="section-header"><span class="section-header-inner"><?= svg('img') ?> 站点内容</span></div>

<div class="cards-grid">
<div class="card">
  <div class="card-header"><span class="card-title"><?= svg('srv') ?> 公告</span></div>
  <form method="post" action="<?= admin_url('settings-site') ?>"><input type="hidden" name="_ann_submit" value="1"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="form-group">
      <label class="form-label">公告内容</label>
      <textarea name="announcement" class="form-input" placeholder="留空 = 关闭公告&#10;填写内容并保存 = 发布公告"><?= htmlspecialchars($announcement['content'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <label class="form-label">内容对齐</label>
      <select name="ann_align" class="form-input">
        <option value="left" <?= $announcement['align'] === 'left' ? 'selected' : '' ?>>左对齐</option>
        <option value="center" <?= $announcement['align'] === 'center' ? 'selected' : '' ?>>居中</option>
      </select>
    </div>
    <button class="btn btn-primary btn-block">发布公告</button>
  </form>
</div>

<div class="card">
  <div class="card-header"><span class="card-title"><?= svg('img') ?> 登录页主题与背景</span></div>
  <form method="post" action="<?= admin_url('settings-site') ?>"><input type="hidden" name="_login_submit" value="1"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="form-row">
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">主题模式</label>
        <select name="login_theme" class="form-input">
          <option value="light" <?= $loginTheme === 'light' ? 'selected' : '' ?>>浅色</option>
          <option value="dark" <?= $loginTheme === 'dark' ? 'selected' : '' ?>>深色</option>
        </select>
      </div>
      <div class="form-group" style="flex:1;margin-bottom:0">
        <label class="form-label">背景图片 URL</label>
        <input class="form-input" name="login_bg" value="<?= htmlspecialchars($loginBg) ?>" placeholder="留空 = 默认浅灰背景">
      </div>
    </div>
    <button class="btn btn-primary btn-block">保存</button>
  </form>
</div>

<div class="card">
  <div class="card-header"><span class="card-title"><?= svg('play') ?> 演示播放器</span></div>
  <form method="post" action="<?= admin_url('settings-site') ?>"><input type="hidden" name="_demo_submit" value="1"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="form-group">
      <label class="form-label">首页「启动播放器」使用的密钥</label>
      <select name="demo_key" class="form-input">
        <option value="" <?= $demoKeySaved === '' ? 'selected' : '' ?>>不使用（首页显示「暂无可用密钥」）</option>
        <?php foreach ($myKeys as $myK): ?>
          <option value="<?= htmlspecialchars($myK['api_key'], ENT_QUOTES, 'UTF-8') ?>"
            <?= $demoKeySaved === $myK['api_key'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($myK['name'] !== '' ? $myK['name'] : '未命名', ENT_QUOTES, 'UTF-8') ?>
            · <?= htmlspecialchars($maskKey($myK['api_key']), ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
      <small style="display:block;margin-top:6px;color:rgba(0,0,0,.38);font-size:12px;line-height:1.7">
        <?php if (!$myKeys): ?>
          你还没有启用的密钥 —— 先到「密钥」页创建一个，下拉框里才会出现选项。
        <?php else: ?>
          只列出<b>你自己的</b>启用密钥（超管也只能选自己的）。保存后首页的「启动播放器」立即生效。
        <?php endif; ?>
        <?php if ($demoKeySaved !== ''): ?>
          <br>当前已启用：<code style="font-size:11px"><?= htmlspecialchars($maskKey($demoKeySaved), ENT_QUOTES, 'UTF-8') ?></code>
        <?php endif; ?>
        <br><b style="color:#c0392b">注意：</b>选中的密钥会写进首页 HTML，任何访客都能看到。
        请<b>专门建一个演示密钥</b>（只配 1 个精选歌单），不要用你的正式密钥。
      </small>
    </div>
    <button class="btn btn-primary btn-block">保存</button>
  </form>
</div>
</div>
