<?php
// 页面文件引导序列：路由基础设施（非业务板块），除下方 INDEX_ACTION 的值外，
// 16 份页面文件逐字相同。对外地址 /admin/<页面>（无后缀）由根 index.php 路由器经通用桥
// 回退引导（或经 /admin/index.php 裸入口）；直接物理访问本文件时由本引导块声明后台上下文
// 与 action，交给 includes/front.php 跑完整判定链；判定通过后 front.php 会把本文件再载入
// 一趟执行页面主体（此刻 MAPI_ADMIN 已定义，跳过本块），主体恰好执行一次——下方
// return 拦住第一趟继续落到主体。主体内容在引导块之后，判定拦截时不会执行。
if (!defined('MAPI_ADMIN')) {
    define('MAPI_ADMIN', true);
    define('INDEX_ACTION', 'login');
    require __DIR__ . '/../../includes/front.php';
    return;
}

require __DIR__ . '/../../api/relay.php';
if ($action === 'get-avatar') {
    header('Content-Type: application/json');
    $u = trim($_GET['username'] ?? '');
    if (!$u || mb_strlen($u) > 30) { echo json_encode(['avatar'=>'']); exit; }
    $rlFile = sys_get_temp_dir() . '/avatar_rl_' . md5($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    $rlData = @file_get_contents($rlFile);
    $rlCount = 0; $rlTime = time();
    if ($rlData) {
        [$rlCount, $rlTime] = explode('|', $rlData);
        if (time() - (int)$rlTime > 60) $rlCount = 0;
    }
    $rlCount++;
    if ($rlCount > 20) { echo json_encode(['avatar'=>'']); exit; }
    @file_put_contents($rlFile, $rlCount . '|' . time(), LOCK_EX);
    $st = $db->prepare("SELECT qq FROM mapi_users WHERE username=?");
    $st->bind_param('s', $u); $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $qq = $row['qq'] ?? '';
    echo json_encode(['avatar' => $qq ? $RELAY['avatar']['qq'].'?b=qq&nk='.$qq.'&s=40' : '']);
    exit;
}
if ($action === 'logout') { session_destroy(); header('Location: ' . admin_url('login')); exit; }

// 合并文件两趟载入（引导块第一趟 + front.php 第二趟）：顶层函数必须条件声明（守卫），
// 无条件声明在编译期提升，第二趟 include 即 fatal "Cannot redeclare"。
if (!function_exists('get_mail_template')):
function get_mail_template(string $code): array {
    global $db;
    $subject = '顺雅音乐 - 验证码邮件';
    $body = <<<'EOT'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>顺雅音乐 - 验证码邮件</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f5efe6;
            font-family: 'PingFang SC', 'Microsoft YaHei', -apple-system, sans-serif;
        }
        .container {
            max-width: 480px;
            margin: 40px auto;
            background: #fdfaf3;
            border-radius: 24px;
            padding: 40px 36px;
            box-shadow: 0 20px 40px rgba(180, 160, 130, 0.12), 0 4px 12px rgba(180, 160, 130, 0.08);
            border: 1px solid rgba(255, 252, 245, 0.6);
            position: relative;
            overflow: hidden;
        }
        .glass-orb-top {
            position: absolute;
            width: 180px;
            height: 180px;
            background: radial-gradient(circle at 30% 30%, rgba(255, 235, 200, 0.55), rgba(255, 210, 160, 0.18) 65%, transparent 70%);
            top: -60px;
            right: -50px;
            border-radius: 50%;
            backdrop-filter: blur(1.2px);
            pointer-events: none;
            z-index: 0;
        }
        .glass-orb-bottom {
            position: absolute;
            width: 220px;
            height: 220px;
            background: radial-gradient(circle at 40% 40%, rgba(240, 225, 205, 0.45), rgba(235, 210, 190, 0.15) 60%, transparent 70%);
            bottom: -70px;
            left: -60px;
            border-radius: 50%;
            backdrop-filter: blur(2px);
            pointer-events: none;
            z-index: 0;
        }
        .card {
            position: relative;
            z-index: 1;
            background: rgba(255, 250, 240, 0.65);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 32px 28px 36px;
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.7), 0 8px 24px rgba(200, 180, 150, 0.10);
        }
        .brand {
            text-align: center;
            margin-bottom: 28px;
        }
        .brand-icon {
            width: 56px;
            height: 56px;
            background: rgba(255, 255, 255, 0.55);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1.2px solid rgba(255, 255, 255, 0.85);
            box-shadow: 0 4px 12px rgba(190, 160, 130, 0.12);
            font-size: 28px;
            margin-bottom: 14px;
            letter-spacing: 2px;
        }
        .brand-name {
            font-size: 21px;
            font-weight: 600;
            color: #5b4a3f;
            letter-spacing: 4px;
            margin: 0;
            text-shadow: 0 1px 2px rgba(255, 255, 255, 0.6);
        }
        .brand-sub {
            font-size: 12px;
            color: #9b8978;
            letter-spacing: 2px;
            margin-top: 6px;
        }
        .title {
            font-size: 16px;
            color: #6b5a4e;
            text-align: center;
            margin: 0 0 6px;
            font-weight: 500;
            letter-spacing: 1px;
        }
        .welcome-text {
            font-size: 13px;
            color: #907e6d;
            text-align: center;
            margin: 0 0 30px;
            line-height: 1.6;
            letter-spacing: 0.5px;
        }
        .code-wrapper {
            background: rgba(255, 245, 233, 0.65);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-radius: 16px;
            padding: 26px 20px;
            text-align: center;
            border: 1px dashed rgba(200, 175, 145, 0.4);
            box-shadow: inset 0 2px 8px rgba(255, 255, 255, 0.5), 0 4px 16px rgba(190, 160, 130, 0.10);
            margin-bottom: 24px;
        }
        .code-label {
            display: block;
            font-size: 12px;
            color: #a08a76;
            letter-spacing: 3px;
            text-transform: uppercase;
            margin-bottom: 14px;
            font-weight: 400;
        }
        .code-number {
            font-size: 42px;
            font-weight: 700;
            color: #3f3a36;
            letter-spacing: 12px;
            font-family: 'SF Mono', 'Menlo', 'Monaco', monospace;
            text-shadow: 0 2px 4px rgba(200, 170, 140, 0.12);
            margin-left: 12px;
        }
        .validity {
            font-size: 12px;
            color: #ad9a87;
            text-align: center;
            margin: 22px 0 6px;
            background: rgba(250, 244, 235, 0.65);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            display: inline-block;
            padding: 6px 16px;
            border-radius: 30px;
            border: 1px solid rgba(255, 255, 255, 0.56);
            letter-spacing: 1px;
        }
        .validity-wrap {
            text-align: center;
        }
        .security-note {
            font-size: 12px;
            color: #b3a392;
            line-height: 1.7;
            text-align: justify;
            margin-top: 26px;
            padding-top: 20px;
            border-top: 1px solid rgba(190, 170, 145, 0.18);
            letter-spacing: 0.3px;
        }
        .footer {
            font-size: 11px;
            color: #bfae9b;
            text-align: center;
            margin-top: 28px;
            line-height: 1.5;
            letter-spacing: 0.3px;
            padding-top: 16px;
            border-top: 1px solid rgba(190, 170, 145, 0.12);
        }
        @media screen and (max-width: 520px) {
            .container {
                margin: 16px auto;
                padding: 20px 16px;
                border-radius: 18px;
            }
            .card {
                padding: 24px 16px 28px;
            }
            .code-number {
                font-size: 36px;
                letter-spacing: 8px;
                margin-left: 8px;
            }
            .brand-icon {
                width: 48px;
                height: 48px;
                font-size: 24px;
            }
        }
        .no-backdrop-blur {
            background-color: rgba(250, 245, 237, 0.94);
        }
        .no-backdrop-blur .card,
        .no-backdrop-blur .code-wrapper,
        .no-backdrop-blur .validity {
            background-color: rgba(252, 248, 242, 0.95);
        }
    </style>
</head>
<body>
    <div class="container no-backdrop-blur">
        <div class="glass-orb-top" style="background: radial-gradient(circle at 30% 30%, rgba(255,235,200,0.55), rgba(255,210,160,0.18) 65%, transparent 70%);"></div>
        <div class="glass-orb-bottom" style="background: radial-gradient(circle at 40% 40%, rgba(240,225,205,0.45), rgba(235,210,190,0.15) 60%, transparent 70%);"></div>
        <div class="card">
            <div class="brand">
                <div style="width: 58px; height: 58px; background: rgba(255,255,255,0.55); border-radius: 18px; display: inline-flex; align-items: center; justify-content: center; border: 1.2px solid rgba(255,255,255,0.85); box-shadow: 0 4px 12px rgba(190,160,130,0.12); margin-bottom: 12px;">
                    <span style="font-size: 26px; color: #5b4a3f;">🎼</span>
                </div>
                <p class="brand-name">顺雅音乐</p>
                <p class="brand-sub">Shunya Music</p>
            </div>
            <h2 class="title">欢迎注册顺雅音乐</h2>
            <p class="welcome-text">请输入以下验证码完成注册<br>随音符悦动，即刻启程</p>
            <div class="code-wrapper">
                <span class="code-label">验证码 · Verification Code</span>
                <div class="code-number">{code}</div>
            </div>
            <div class="validity-wrap">
                <span class="validity">⏳ 验证码10分钟内有效，请尽快填写</span>
            </div>
            <div class="security-note">
                ⚠️ 若您并未注册顺雅音乐，请忽略此邮件。<br>
                · 保护您的验证码与隐私，切勿转发给他人。<br>
                · 顺雅音乐工作人员不会向您索要任何验证码。
            </div>
            <div class="footer">
                顺雅音乐 · 聆享纯粹旋律<br>
                本邮件由系统自动发送，请勿直接回复
            </div>
        </div>
    </div>
</body>
</html>
EOT;
    $html = true;
    if ($db && !$db->connect_error) {
        if (!defined('DB_SQLITE')) {
            $db->query("CREATE TABLE IF NOT EXISTS `mapi_mail_templates` (`id` INT NOT NULL AUTO_INCREMENT, `name` VARCHAR(100) NOT NULL DEFAULT '', `subject` VARCHAR(200) NOT NULL DEFAULT '顺雅音乐 - 验证码邮件', `body` TEXT, `is_html` TINYINT NOT NULL DEFAULT 0, `is_default` TINYINT NOT NULL DEFAULT 0, `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $db->query("CREATE TABLE IF NOT EXISTS mapi_mail_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL DEFAULT '', subject VARCHAR(200) NOT NULL DEFAULT '顺雅音乐 - 验证码邮件', body TEXT, is_html INTEGER NOT NULL DEFAULT 0, is_default INTEGER NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        }
        $r = $db->query("SELECT * FROM mapi_mail_templates WHERE is_default=1 LIMIT 1");
        if ($r && ($row2 = $r->fetch_assoc())) {
            if (!empty($row2['subject'])) $subject = $row2['subject'];
            if (!empty($row2['body'])) $body = $row2['body'];
            $html = !empty($row2['is_html']);
        }
    }
    return [
        'subject' => str_replace('{code}', $code, $subject),
        'body' => str_replace('{code}', $code, $body),
        'html' => $html,
    ];
}
endif;

$err = ''; $ok = '';
// 读取极验配置
$geetestCaptchaId = ''; $geetestKey = '';
$r = $db->query("SELECT captcha_id, `key` FROM mapi_geetest LIMIT 1");
if ($r && $grow = $r->fetch_assoc()) {
    $geetestCaptchaId = $grow['captcha_id'] ?? '';
    $geetestKey = $grow['key'] ?? '';
}
// 极验逃生开关：配置文件 security.geetest_bypass
$geetestOn = ($geetestCaptchaId !== '' && $geetestKey !== '' && empty($cfg['security']['geetest_bypass']));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$action) $action = 'login';
// 登录表单提交到 /admin/login/（见下方两个登录表单的 action），所以 $action 会是 'login'。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    login_rl();
    // 极验验证（配置已在文件上方读取，这里才真正生效）
    if ($geetestOn) {
        $gt_lot = $_POST['geetest_lot_number'] ?? '';
        $gt_output = $_POST['geetest_captcha_output'] ?? '';
        $gt_pass = $_POST['geetest_pass_token'] ?? '';
        $gt_gen = $_POST['geetest_gen_time'] ?? '';
        if (!$gt_lot || !$gt_output || !$gt_pass || !$gt_gen) {
            $err = '请完成安全验证';
        } else {
            $gt_sign = hash_hmac('sha256', $gt_lot, $geetestKey);
            $ch = curl_init($RELAY['api']['geetest_validate'] . '?captcha_id=' . urlencode($geetestCaptchaId));
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'lot_number' => $gt_lot,
                'captcha_output' => $gt_output,
                'pass_token' => $gt_pass,
                'gen_time' => $gt_gen,
                'sign_token' => $gt_sign,
            ]));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            $gt_res = curl_exec($ch);
            $gt_http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $gt_curlErr = curl_error($ch);
            curl_close($ch);
            $gt_data = is_string($gt_res) ? json_decode($gt_res, true) : null;
            if (!is_array($gt_data)) {
// 极验服务异常时放行并记日志
                error_log('MAPI geetest: validate 响应异常 http=' . $gt_http . ' curl=' . $gt_curlErr);
            } elseif (($gt_data['result'] ?? '') !== 'success') {
                $err = '安全验证失败，请重新验证';
            }
        }
    }
    if (!$err) {
        $u = trim($_POST['username'] ?? ''); $p = $_POST['password'] ?? '';
        if (mb_strlen($u) > 30) { $u = ''; }
        if (!$u || !$p) {
            $err = '请填写完整';
        } else {
            // 老库兼容：按 mapi_users 真实存在的列拼 SELECT，缺列不再抛异常导致 500 白屏
            $row = null; $stmt = null;
            try {
                $stmt = $db->prepare('SELECT ' . mapi_users_select_cols($db) . ' FROM mapi_users WHERE username=?');
                if ($stmt) {
                    $stmt->bind_param('s', $u); $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                }
            } catch (Throwable $e) {
                error_log('MAPI login: 查询 mapi_users 失败: ' . $e->getMessage());
                $err = '登录失败：数据库结构异常，请检查 mapi_users 表';
            }
            if (!$err && !$stmt) { $err = '数据库连接失败，请检查配置'; }
            if (!$err && $row && password_verify($p, $row['password'])) {
                session_regenerate_id(true);
                $_SESSION['admin_id'] = $row['id']; $_SESSION['admin_user'] = $row['username'];
                $_SESSION['admin_qq'] = $row['qq'] ?? ''; $_SESSION['admin_is_admin'] = (int)($row['is_admin'] ?? 2);
                $_SESSION['admin_auto_theme'] = (int)($row['auto_theme'] ?? 1);
                $_SESSION['admin_theme_mode'] = $row['theme_mode'] ?? 'light';
                // 设置后台界面主题
                $_SESSION['admin_ui_theme'] = $row['admin_theme'] ?? 'light';
                $_SESSION['admin_lyrics_default'] = (int)($row['lyrics_default'] ?? 1);
                $_SESSION['admin_autoplay_default'] = (int)($row['autoplay_default'] ?? 0);
                $_SESSION['admin_player_pos'] = trim((string)($row['player_pos'] ?? ''));
                // 皮肤属于「用户设置」，登录时必须一并读回会话 —— 否则登录后配置页显示默认值，
                // 此时一按保存就会把默认值写回数据库（配置真被覆盖）。列可能不存在，用 ?? 兜底。
                $_SESSION['admin_player_skin'] = trim((string)($row['player_skin'] ?? ''));
                $_SESSION['admin_player_skin_cfg'] = trim((string)($row['player_skin_cfg'] ?? ''));
                $_SESSION['admin_background'] = $row['background'] ?? '';
                $_SESSION['admin_background_url'] = $row['background_url'] ?? '';
                header('Location: ' . admin_url('dashboard')); exit;
            }
            if (!$err) { $err = '账号或密码错误'; }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'register') {
    $u = trim($_POST['username'] ?? ''); $p = $_POST['password'] ?? ''; $e = trim($_POST['email'] ?? ''); $q = trim($_POST['qq'] ?? ''); $vc = trim($_POST['vcode'] ?? '');
    if (mb_strlen($u) > 30) { $err = '账号最多30字符'; }
    elseif (mb_strlen($p) > 60) { $err = '密码最多60字符'; }
    elseif (mb_strlen($q) > 20) { $err = 'QQ号最多20字符'; }
    if ($err && isset($_POST['send_code'])) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'err' => $err]); exit; }
    if (isset($_POST['send_code'])) {
        login_rl();
        if (!$e || !filter_var($e, FILTER_VALIDATE_EMAIL)) { $err = '请输入有效邮箱'; }
        else {
            $code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
            $tpl = get_mail_template($code);
            $mailErr = sendMail($e, $tpl['subject'], $tpl['body'], $tpl['html']);
            if ($mailErr) { $err = '验证码发送失败：' . $mailErr; }
            else { $_SESSION['reg_u'] = $u; $_SESSION['reg_e'] = $e; $_SESSION['reg_q'] = $q; $_SESSION['reg_code'] = $code; $_SESSION['reg_time'] = time(); $ok = '验证码已发送'; }
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => !$err, 'err' => $err ?: '']);
        exit;
    } elseif ($vc && !empty($_SESSION['reg_code'])) {
        if (time() - $_SESSION['reg_time'] > 300) { $err = '验证码已过期，请重新发送'; unset($_SESSION['reg_code'], $_SESSION['reg_u'], $_SESSION['reg_e'], $_SESSION['reg_q'], $_SESSION['reg_time']); }
        elseif ($vc !== $_SESSION['reg_code']) { $err = '验证码错误'; }
        else {
            if (!$u || !$p || !$q) { $err = '请先填写完整信息'; }
            elseif ($err) { /* 已在上方校验失败 */ }
            else {
                $hash = password_hash($p, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO mapi_users (username,password,qq,email,is_admin) VALUES (?,?,?,?,2)");
                $stmt->bind_param('ssss', $u, $hash, $q, $e);
                if ($stmt->execute()) {
                    session_regenerate_id(true);
                    $_SESSION['admin_id'] = $stmt->insert_id; $_SESSION['admin_user'] = $u;
                    $_SESSION['admin_qq'] = $q; $_SESSION['admin_is_admin'] = 2;
                    unset($_SESSION['reg_code'], $_SESSION['reg_u'], $_SESSION['reg_e'], $_SESSION['reg_q'], $_SESSION['reg_time']);
                    header('Location: ' . admin_url('dashboard')); exit;
                }
                $err = '注册失败';
            }
        }
    } else {
        if ($err) { /* 已在上方校验失败 */ }
        elseif (!filter_var($e, FILTER_VALIDATE_EMAIL)) { $err = '邮箱格式不正确'; }
        else {
            $stmt = $db->prepare("SELECT id FROM mapi_users WHERE username=?");
            $stmt->bind_param('s', $u); $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) { $err = '账号已存在'; }
            else {
                $code = str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
                $tpl = get_mail_template($code);
                $mailErr = sendMail($e, $tpl['subject'], $tpl['body'], $tpl['html']);
                if ($mailErr) { $err = '验证码发送失败：' . $mailErr; }
                else { $_SESSION['reg_u'] = $u; $_SESSION['reg_e'] = $e; $_SESSION['reg_q'] = $q; $_SESSION['reg_code'] = $code; $_SESSION['reg_time'] = time(); $ok = '验证码已发送'; }
            }
        }
    }
}

if ($action === 'pk-login-begin') {
    $rpId = webauthn_get_rp_id();
    if (!webauthn_is_valid_rp_id($rpId)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'WebAuthn不支持IP地址访问，请使用域名或localhost访问']);
        exit;
    }
    $challenge = random_bytes(32);
    $_SESSION['pk_challenge'] = base64_encode($challenge);
    $_SESSION['pk_rp_id'] = $rpId;
    $_SESSION['pk_origin'] = webauthn_get_origin();
    header('Content-Type: application/json');
    echo json_encode(['challenge' => base64url_encode($challenge), 'rpId' => $rpId, 'timeout' => 120000, 'userVerification' => 'preferred']);
    exit;
}
if ($action === 'pk-login-complete') {
    $ad = $_POST['authenticatorData'] ?? ''; $sig = $_POST['signature'] ?? ''; $cjd = $_POST['clientDataJSON'] ?? ''; $cid = base64_encode(base64url_decode($_POST['credentialId'] ?? ''));
    if (!$ad || !$sig || !$cjd || !$cid) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'err'=>'参数不完整']); exit; }
    $st = $db->prepare("SELECT id, user_id, public_key_pem, counter FROM mapi_passkeys WHERE credential_id=?");
    $st->bind_param('s', $cid); $st->execute();
    $pk = $st->get_result()->fetch_assoc();
    if (!$pk) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'err'=>'未找到通行密钥']); exit; }
    $nc = webauthn_verify_assertion($ad, $sig, $cjd, $pk['public_key_pem'], $pk['counter']);
    if ($nc === false) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'err'=>'验证失败']); exit; }
    $st2 = $db->prepare("UPDATE mapi_passkeys SET counter=? WHERE id=?");
    $st2->bind_param('ii', $nc, $pk['id']); $st2->execute();
    // 老库兼容：与密码登录同样按真实存在的列拼 SELECT
    $row = null;
    try {
        $st3 = $db->prepare('SELECT ' . mapi_users_select_cols($db) . ' FROM mapi_users WHERE id=?');
        if ($st3) { $st3->bind_param('i', $pk['user_id']); $st3->execute(); $row = $st3->get_result()->fetch_assoc(); }
    } catch (Throwable $e) {
        error_log('MAPI pk-login: 查询 mapi_users 失败: ' . $e->getMessage());
    }
    if (!$row) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'err'=>'用户不存在']); exit; }
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $row['id']; $_SESSION['admin_user'] = $row['username'];
    $_SESSION['admin_qq'] = $row['qq'] ?? ''; $_SESSION['admin_is_admin'] = (int)($row['is_admin'] ?? 2);
    $_SESSION['admin_auto_theme'] = (int)($row['auto_theme'] ?? 1);
    $_SESSION['admin_theme_mode'] = $row['theme_mode'] ?? 'light';
    // 后台界面主题：独立列 admin_theme（缺列时回落 light，不影响播放器设置）
    $_SESSION['admin_ui_theme'] = $row['admin_theme'] ?? 'light';
    $_SESSION['admin_lyrics_default'] = (int)($row['lyrics_default'] ?? 1);
    $_SESSION['admin_autoplay_default'] = (int)($row['autoplay_default'] ?? 0);
                $_SESSION['admin_player_pos'] = trim((string)($row['player_pos'] ?? ''));
                // 皮肤属于「用户设置」，登录时必须一并读回会话 —— 否则登录后配置页显示默认值，
                // 此时一按保存就会把默认值写回数据库（配置真被覆盖）。列可能不存在，用 ?? 兜底。
                $_SESSION['admin_player_skin'] = trim((string)($row['player_skin'] ?? ''));
                $_SESSION['admin_player_skin_cfg'] = trim((string)($row['player_skin_cfg'] ?? ''));
    $_SESSION['admin_background'] = $row['background'] ?? '';
    $_SESSION['admin_background_url'] = $row['background_url'] ?? '';
    unset($_SESSION['pk_challenge'], $_SESSION['pk_rp_id'], $_SESSION['pk_origin']);
    header('Content-Type: application/json');
    echo json_encode(['ok'=>true]);
    exit;
}

header('Cache-Control: private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
$loginTheme = 'light'; $loginBg = '';
$r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='login_theme'");
if ($r && $row = $r->fetch_assoc()) $loginTheme = $row['config_value'];
$r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='login_bg'");
if ($r && $row = $r->fetch_assoc()) $loginBg = $row['config_value'];
// 极验配置已在文件上方（登录校验之前）读取，这里不再重复查询
?><!DOCTYPE html>
<html lang="zh-CN">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,minimum-scale=1.0,user-scalable=no,viewport-fit=cover">
<title>顺雅 · 登录</title>
<script>var GEETEST_CAPTCHA_ID = '<?= addslashes($geetestCaptchaId) ?>';</script>
<script src="<?= $RELAY['sdk']['geetest_js'] ?>"></script>
<link rel="stylesheet" href="<?= str_replace('{device}', $isMobile ? 'mobile' : 'pc', $RELAY['page']['login_css']) ?>">
</head>
<body data-device="<?= $isMobile ? 'mobile' : 'pc' ?>" data-theme="<?= $loginTheme ?>" style="background:<?= $loginBg ? "url($loginBg) center/cover no-repeat fixed" : '' ?>">

<!-- ═══ PC 端：推压式面板 ═══ -->
<div class="push-container" id="pushContainer">
  <div class="push-panel push-login<?= $action === 'register' ? ' pushed' : '' ?>">
    <div class="push-tab push-tab-right" onclick="pushTo('reg')" title="注册">
      <svg viewBox="0 0 26 52"><path d="M0 8 L16 14 L16 38 L0 44 Z" class="tab-shadow"/><path d="M0 6 L18 12 L18 40 L0 46 Z" class="tab-body"/><polyline points="8,22 14,26 8,30" class="tab-arrow"/></svg>
    </div>
    <div class="push-panel-inner">
      <h1>顺雅管理</h1>
      <p class="sub">音乐播放器后台</p>
      <?php if ($err && $action !== 'register'): ?><script>setTimeout(function(){showToast('<?= addslashes(htmlspecialchars($err)) ?>','err')},100)</script><?php endif; ?>
      <?php if ($ok && $action !== 'register'): ?><script>setTimeout(function(){showToast('<?= addslashes($ok) ?>','ok')},100)</script><?php endif; ?>
      <form method="post" id="loginFormPc" action="<?= admin_url('login') ?>"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <div class="fl"><label>账号</label><input name="username" required autocomplete="username" placeholder="请输入账号"></div>
        <div class="fl"><label>密码</label><input type="password" name="password" required autocomplete="new-password" placeholder="请输入密码"></div>
        <input type="hidden" name="geetest_lot_number">
        <input type="hidden" name="geetest_captcha_output">
        <input type="hidden" name="geetest_pass_token">
        <input type="hidden" name="geetest_gen_time">
        <button type="button" class="btn" id="loginBtnPc">登录</button>
      </form>
      <div class="divider"><span>或</span></div>
      <button class="btn btn-outline" id="pkLoginBtnD" onclick="passkeyLogin()">通行密钥登录</button>
    </div>
  </div>
  <div class="push-panel push-register<?= $action === 'register' ? ' pushed' : '' ?>">
    <div class="push-tab push-tab-left" onclick="pushTo('login')" title="登录">
      <svg viewBox="0 0 26 52"><path d="M26 8 L10 14 L10 38 L26 44 Z" class="tab-shadow"/><path d="M26 6 L8 12 L8 40 L26 46 Z" class="tab-body"/><polyline points="18,22 12,26 18,30" class="tab-arrow"/></svg>
    </div>
    <div class="push-panel-inner">
      <h1>注册账号</h1>
      <p class="sub">加入顺雅音乐</p>
      <?php if ($err && $action === 'register'): ?><script>setTimeout(function(){showToast('<?= addslashes(htmlspecialchars($err)) ?>','err')},100)</script><?php endif; ?>
      <?php if ($ok && $action === 'register'): ?><script>setTimeout(function(){showToast('<?= addslashes($ok) ?>','ok')},100)</script><?php endif; ?>
      <form method="post" action="<?= admin_url('register') ?>"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <div class="fl"><label>账号</label><input name="username" value="<?= htmlspecialchars($_SESSION['reg_u'] ?? '') ?>" placeholder="设置登录账号"></div>
        <div class="fl"><label>密码</label><input type="password" name="password" placeholder="设置登录密码"></div>
        <div class="fl"><label>QQ号</label><input name="qq" value="<?= htmlspecialchars($_SESSION['reg_q'] ?? '') ?>" placeholder="用于获取头像"></div>
        <div class="fl"><label>邮箱</label><input type="email" name="email" value="<?= htmlspecialchars($_SESSION['reg_e'] ?? '') ?>" placeholder="接收验证码"></div>
        <div class="fl"><label>验证码</label>
          <div class="vcode-row">
            <input name="vcode" placeholder="5位验证码" maxlength="5" autocomplete="off" class="vcode-input">
            <button type="button" id="sendCodeBtnD" class="btn btn-sm" onclick="sendCodeD()">发送验证码</button>
          </div>
        </div>
        <button class="btn">注册</button>
      </form>
    </div>
  </div>
</div>

<!-- ═══ 移动端：翻转卡片 ═══ -->
<div class="login-mobile">
<div class="flip-wrap"><div class="flip-outer"><div class="flip-inner<?= ($action==='register' && ($err || $ok) || !empty($_SESSION['reg_code'])) ? ' reg' : '' ?>" id="flipInner">
<div class="glass">
  <h1>顺雅管理</h1><p class="sub">音乐播放器后台</p>
  <?php if ($err): ?><script>setTimeout(function(){showToast('<?= addslashes(htmlspecialchars($err)) ?>','err')},100)</script><?php endif; ?>
  <?php if ($ok): ?><script>setTimeout(function(){showToast('<?= addslashes($ok) ?>','ok')},100)</script><?php endif; ?>
  <div class="tabs"><button class="tab act" id="tabLogin" onclick="flip('login')">登录</button><button class="tab" id="tabReg" onclick="flip('reg')">注册</button></div>
  <form method="post" id="loginFormMb" action="<?= admin_url('login') ?>"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><div class="fl"><label>账号</label><input name="username" required autocomplete="username"></div><div class="fl"><label>密码</label><input type="password" name="password" required autocomplete="new-password"></div><input type="hidden" name="geetest_lot_number"><input type="hidden" name="geetest_captcha_output"><input type="hidden" name="geetest_pass_token"><input type="hidden" name="geetest_gen_time"><button type="button" class="btn" id="loginBtnMb">登录</button></form>
  <button class="btn" id="pkLoginBtn" onclick="passkeyLogin()" style="margin-top:8px;font-size:12px;padding:8px">通行密钥登录</button>
</div>
<div class="glass flip-back">
  <h1>顺雅管理</h1><p class="sub">音乐播放器后台</p>
  <?php if ($err): ?><script>setTimeout(function(){showToast('<?= addslashes(htmlspecialchars($err)) ?>','err')},100)</script><?php endif; ?>
  <?php if ($ok): ?><script>setTimeout(function(){showToast('<?= addslashes($ok) ?>','ok')},100)</script><?php endif; ?>
  <div class="tabs"><button class="tab" id="tabLogin2" onclick="flip('login')">登录</button><button class="tab act" id="tabReg2" onclick="flip('reg')">注册</button></div>
  <form method="post" action="<?= admin_url('register') ?>"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="fl"><label>账号</label><input name="username" value="<?= htmlspecialchars($_SESSION['reg_u'] ?? '') ?>"></div>
    <div class="fl"><label>密码</label><input type="password" name="password"></div>
    <div class="fl"><label>QQ号</label><input name="qq" value="<?= htmlspecialchars($_SESSION['reg_q'] ?? '') ?>"></div>
    <div class="fl"><label>邮箱</label><input type="email" name="email" value="<?= htmlspecialchars($_SESSION['reg_e'] ?? '') ?>" placeholder="接收验证码"></div>
    <div class="fl"><label>验证码</label>
      <div style="display:flex;gap:6px">
        <input name="vcode" placeholder="5位验证码" maxlength="5" autocomplete="off" style="text-align:center;font-size:18px;letter-spacing:6px;font-weight:700;flex:1;min-width:0">
        <button type="button" id="sendCodeBtn" class="btn" style="width:auto;padding:10px 16px;flex-shrink:0;white-space:nowrap;font-size:13px" onclick="sendCode()">发送验证码</button>
      </div>
    </div>
    <button class="btn">注册</button>
  </form>
</div>
</div></div></div>
</div>

<div id="toast"></div>
<script src="<?= str_replace('{device}', $isMobile ? 'mobile' : 'pc', $RELAY['page']['login_js']) ?>"></script>
</body>
</html>
