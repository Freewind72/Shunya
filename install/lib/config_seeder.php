<?php

function seed_config(object $db, string $type): void {
    $tplBody = <<<'EOT'
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
                <div class="brand-icon">🎼</div>
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

    $defaults = [
        'mail_template' => json_encode([
            'subject' => '顺雅音乐 - 验证码邮件',
            'body' => $tplBody,
            'html' => true,
        ], JSON_UNESCAPED_UNICODE),
        // Redis 播放器缓存：默认关闭。字段名与 admin/lib/redis.php 的 redis_default_config() 一一对应，
        // 管理员在「设置 → 储存 → Redis 缓存」里改；老库缺这行时也会在这里补上（增量系统会跑播种）。
        'redis' => json_encode([
            'enabled'     => false,
            'host'        => '127.0.0.1',
            'port'        => 6379,
            'password'    => '',
            'database'    => 0,
            'prefix'      => 'mapi:',
            'default_ttl' => 600,
            'timeout'     => 2,
            'ttl_url'     => 300,
            'ttl_lrc'     => 604800,
            'ttl_pic'     => 604800,
            'ttl_cfg'     => 60,
            'ttl_state'   => 2592000,
            'strict_ip'   => 1,
            'ip_grace'    => 86400,
        ], JSON_UNESCAPED_UNICODE),
    ];

    try {
        if ($type === 'mysql') {
            $st = $db->prepare("SELECT config_key FROM mapi_config WHERE config_key = ?");
            foreach ($defaults as $key => $value) {
                $st->execute([$key]);
                if (!$st->fetch()) {
                    $ins = $db->prepare("INSERT INTO mapi_config (config_key, config_value) VALUES (?, ?)");
                    $ins->execute([$key, $value]);
                }
            }
        } elseif ($type === 'sqlite') {
            $st = $db->prepare("SELECT config_key FROM mapi_config WHERE config_key = :key");
            foreach ($defaults as $key => $value) {
                $st->bindValue(':key', $key, SQLITE3_TEXT);
                $result = $st->execute();
                $exists = $result && $result->fetchArray();
                if (!$exists) {
                    $ins = $db->prepare("INSERT INTO mapi_config (config_key, config_value) VALUES (:key, :val)");
                    $ins->bindValue(':key', $key, SQLITE3_TEXT);
                    $ins->bindValue(':val', $value, SQLITE3_TEXT);
                    $ins->execute();
                }
            }
        }
    } catch (Throwable $e) {
        error_log("MAPI seed_config: " . $e->getMessage());
    }
}