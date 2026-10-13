<?php
set_time_limit(20);
$configFile = __DIR__ . '/config/config.php';
if (!file_exists($configFile)) {
    header('Location: install/');
    exit;
}
$CFG = require $configFile;
date_default_timezone_set('Asia/Shanghai');
if (empty($CFG['db']['type'])) {
    header('Location: install/');
    exit;
}
require __DIR__ . '/assets/lib/db.php';
require __DIR__ . '/assets/lib/jwt.php';
require __DIR__ . '/assets/lib/helpers.php';   // asset_ver()：静态资源版本号
$RELAY = require __DIR__ . '/admin/api/relay.php';
$totalCalls = 0; $todayCalls = 0;
$superKey = ''; $superToken = '';
$jwtSecret = jwt_secret($CFG);   // 缺失/过短会抛异常，不再回退到弱密钥
$statDb = db_connect();
if ($statDb) {
    try {
        $r = $statDb->query("SELECT COUNT(*) as c FROM mapi_logs");
        if ($r) $totalCalls = (int)$r->fetch_assoc()['c'];
        $today = date('Y-m-d');
        $r = $statDb->query("SELECT COUNT(*) as c FROM mapi_logs WHERE DATE(created_at)='$today'");
        if ($r) $todayCalls = (int)$r->fetch_assoc()['c'];
        // 演示播放器专用密钥：**只认 config 的 api.demo_key**，不再取「库里第一条启用密钥」。
        // 这条密钥会被拼进页面 HTML 下发给所有访客，所以绝不能是站长正式密钥；
        // 留空 = 不显示演示播放器（安全默认值）。仍要求它在 mapi_keys 里真实启用，否则不发令牌。
        $demoKey = trim((string)($CFG['api']['demo_key'] ?? ''));
        if ($demoKey !== '') {
            $dkStmt = $statDb->prepare("SELECT api_key FROM mapi_keys WHERE api_key=? AND status=1 LIMIT 1");
            if ($dkStmt) {
                $dkStmt->bind_param('s', $demoKey);
                $dkStmt->execute();
                $dkRes = $dkStmt->get_result();
                $dkRow = $dkRes ? $dkRes->fetch_assoc() : null;
                if ($dkRow) {
                    $superKey = $dkRow['api_key'];
                    $superToken = jwt_encode(['key' => $superKey, 'exp' => time() + 900, 'iat' => time()], $jwtSecret);
                }
            }
        }
    } catch(Exception $e) { $totalCalls = 0; $todayCalls = 0; }
    @$statDb->close();
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$st = number_format($totalCalls);
$sd = number_format($todayCalls);

// mapi_token cookie 必须在 HTML 输出前设置
if ($superToken) {
    setcookie('mapi_token', $superToken, [
        'expires'  => time() + 900,
        'path'     => '/',
        'httponly' => false,
        'samesite' => 'Lax',
        'secure'   => ($_SERVER['HTTPS'] ?? '') === 'on',
    ]);
}
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>顺雅 · 声波宇宙</title>
<meta name="description" content="你的网站，自带旋律。">
<meta name="theme-color" content="#030712">
<link rel="stylesheet" href="<?= asset_ver($RELAY['page']['pc_css']) ?>">
<link rel="stylesheet" href="<?= asset_ver($RELAY['page']['mobile_css']) ?>">
<?php require __DIR__ . $RELAY['asset']['widget_php']; ?>
</head>
<body>

<div class="noise-overlay"></div>
<div class="starfield" id="starfield" aria-hidden="true"></div>

<!-- ═══════════ CONTEXT MENU ═══════════ -->
<div class="context-menu" id="contextMenu">
  <a href="#features">核心技术</a>
  <a href="#terminal">一行嵌入</a>
  <a href="/admin/">管理后台</a>
</div>

<!-- ═══════════ HERO ═══════════ -->
<section class="hero" id="hero">
  <div class="spectrum-stage" id="spectrumStage" aria-hidden="true"></div>

  <div class="hero-content">
    <p class="hero-kicker anim-fade-up">✦ 全息音频播放器</p>
    <h1 class="hero-title anim-fade-up">
      <span class="line1">你的网站，</span>
      <span class="line2">自带旋律</span>
    </h1>
    <p class="hero-sub anim-fade-up">Shadow DOM 隔离 · 一行代码嵌入 · 深空般的沉浸体验</p>

    <div class="hero-cta-wrap anim-fade-up">
      <div class="hero-cta-ghost"></div>
      <?php if ($superToken): ?>
      <a href="javascript:void(0)" class="hero-cta" onclick="mapiLoadPlayer(this)">启动播放器 →</a>
      <?php else: ?>
      <span class="hero-cta" style="background:rgba(255,255,255,0.04);color:var(--text-muted);cursor:not-allowed;border:1px solid var(--glass-border)">暂无可用密钥</span>
      <?php endif; ?>
    </div>

    <!-- Stats Bar -->
    <div class="hero-stats anim-fade-up">
      <div class="hero-stat-item">
        <span class="hero-stat-value" id="statTotal" data-target="<?=$st?>">0</span>
        <span class="hero-stat-label">累计调用</span>
      </div>
      <div class="hero-stat-divider"></div>
      <div class="hero-stat-item">
        <span class="hero-stat-value" id="statToday" data-target="<?=$sd?>">0</span>
        <span class="hero-stat-label">今日调用</span>
      </div>
      <div class="hero-stat-divider"></div>
      <div class="hero-stat-item">
        <span class="hero-stat-value">∞</span>
        <span class="hero-stat-label">无限站点</span>
      </div>
    </div>
  </div>

  <div class="scroll-hint">
    <span>探索深空</span>
    <div class="scroll-line"></div>
  </div>
</section>

<!-- ═══════════ FEATURES — IRREGULAR GRID ═══════════ -->
<section class="features-section" id="features">
  <div class="features-header anim-fade-up">
    <span class="tag">核心技术</span>
    <h2>能力 — <em>声波引擎</em></h2>
  </div>
  <div class="features-grid">
    <div class="feat-card col-2 anim-fade-up">
      <div class="feat-glow"></div>
      <span class="feat-num">01</span>
      <h3>Shadow DOM 隔离</h3>
      <p>attachShadow 封装，宿主 CSS 无法穿透。零冲突嵌入任意网站，不污染、不被打扰。</p>
    </div>
    <div class="feat-card col-1 anim-fade-up">
      <div class="feat-glow"></div>
      <span class="feat-num">02</span>
      <h3>歌词同步</h3>
      <p>自动解析 LRC 时间轴，实时高亮，逐字精准。</p>
    </div>
    <div class="feat-card col-1 anim-fade-up">
      <div class="feat-glow"></div>
      <span class="feat-num">03</span>
      <h3>沉浸全屏</h3>
      <p>大封面、滚动歌词、完整控制栏，宛如原生播放器。</p>
    </div>
    <div class="feat-card col-2 anim-fade-up">
      <div class="feat-glow"></div>
      <span class="feat-num">04</span>
      <h3>多种播放模式</h3>
      <p>自动深色 · 自由拖拽 · 顺序 / 循环 / 随机。每个细节都为沉浸体验考量。</p>
    </div>
  </div>
</section>

<!-- ═══════════ TERMINAL ═══════════ -->
<section class="terminal-section" id="terminal">
  <span class="tag anim-fade-up">嵌入指引</span>
  <h2 class="anim-fade-up">一行 Script — <em>即刻拥有</em></h2>
  <div class="terminal-window anim-fade-up" id="terminalWin">
    <div class="terminal-titlebar">
      <span class="terminal-dot red"></span>
      <span class="terminal-dot yellow"></span>
      <span class="terminal-dot green"></span>
      <span class="terminal-title"></span>
    </div>
    <div class="terminal-body" id="terminalBody">
      <div class="terminal-line"><span class="ln">1</span><span class="comment">在 HTML 末尾添加这一行：</span></div>
      <div class="terminal-line"><span class="ln">2</span><code id="embedCodeLine"></code></div>
      <div class="terminal-line"><span class="ln">3</span><span class="comment">播放器自动悬浮右下角，所有访客即刻收听</span></div>
    </div>
    <script>(function(){var el=document.getElementById('embedCodeLine'),src=location.origin+'<?= $RELAY["asset"]["embed_js"] ?>'+'?key=your_key';el.innerHTML='<span class="hl-tag">&lt;script</span> <span class="hl-attr">src</span>=<span class="hl-str">\"'+src+'\"</span> <span class="hl-attr">defer</span><span class="hl-tag">&gt;&lt;/script&gt;</span>';})();</script>
  </div>
</section>

<!-- ═══════════ CODA — FADE OUT ═══════════ -->
<section class="coda-section">
  <div class="coda-fade-bg"></div>
  <div class="coda-inner anim-fade-up">
    <h2>让每个页面都 <em>有声有色</em></h2>
    <p>一分钟注册，获取你的专属 API 密钥</p>
    <div class="coda-cta-wrap">
      <div class="coda-cta-breath"></div>
      <a href="/admin/" class="coda-cta">进入管理后台 →</a>
    </div>
  </div>
</section>

<script src="<?= asset_ver($RELAY['page']['pc_js']) ?>"></script>
<script src="<?= asset_ver($RELAY['page']['mobile_js']) ?>"></script>
</body>
</html>