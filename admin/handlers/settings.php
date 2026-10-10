<?php

// $action_key 由路由层传入，值为实际的action名

if (in_array($action_key, ['settings','settings-site','settings-mail','settings-security','settings-api','settings-storage'], true)) {
    /**
     * 诊断开关（排查"保存后回弹/保存不生效"这类说不清的问题）：
     * 只有存在标记文件 `%TEMP%/mapi-settings-debug.on` 时才记录，平时零开销、不产生任何日志。
     * 记录每次设置页 POST 的关键事实：请求到没到、CSRF 过没过、哪个分支跑了、写了什么。
     * 定位完删掉标记文件即可。
     */
    if (!function_exists('mapi_settings_diag')) {
        function mapi_settings_diag(string $stage, array $extra = []): void {
            $dir = sys_get_temp_dir();
            if (!is_file($dir . DIRECTORY_SEPARATOR . 'mapi-settings-debug.on')) return;
            $line = sprintf("[%s] %-12s method=%s uri=%s ref=%s sid=%s is_admin=%s csrf=%s sub=%s test=%s",
                date('Y-m-d H:i:s'), $stage, $_SERVER['REQUEST_METHOD'] ?? '?', $_SERVER['REQUEST_URI'] ?? '?',
                $_SERVER['HTTP_REFERER'] ?? '-', session_id() ?: '-',
                var_export($_SESSION['admin_is_admin'] ?? null, true) . '(' . gettype($_SESSION['admin_is_admin'] ?? null) . ')',
                isset($_POST['_csrf']) ? (hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$_POST['_csrf']) ? 'ok' : 'MISMATCH') : 'missing',
                isset($_POST['_redis_submit']) ? '1' : '0', isset($_POST['_redis_test']) ? '1' : '0');
            if ($extra) $line .= ' ' . json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            @file_put_contents($dir . DIRECTORY_SEPARATOR . 'mapi-settings-post.log', $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }
    // 放在 csrf_require() **之前**：这样 CSRF 失败（会直接 die）也能被记录下来
    mapi_settings_diag('recv', [
        'pw'     => (($_POST['redis_password'] ?? '') !== '' ? 'set' : 'empty'),
        'posted' => array_intersect_key($_POST, array_flip(['redis_enabled','redis_host','redis_port','redis_database',
            'redis_prefix','redis_timeout','redis_ttl_url','redis_ttl_cfg','redis_ip_grace','redis_strict_ip',
            'redis_form_stamp','_redis_submit','_redis_test','_s3_submit','_api_submit'])),
    ]);
    csrf_require();
    if (isset($_POST['_geetest_submit'])) {
        $geetestCaptchaId = trim($_POST['geetest_captcha_id'] ?? '');
        $geetestKey = trim($_POST['geetest_key'] ?? '');
        $r = $db->query("SELECT COUNT(*) as cnt FROM mapi_geetest");
        $exists = $r && (int)$r->fetch_assoc()['cnt'] > 0;
        if ($exists) {
            $st = $db->prepare("UPDATE mapi_geetest SET captcha_id=?, `key`=?");
            $st->bind_param('ss', $geetestCaptchaId, $geetestKey);
        } else {
            $st = $db->prepare("INSERT INTO mapi_geetest (captcha_id, `key`) VALUES (?, ?)");
            $st->bind_param('ss', $geetestCaptchaId, $geetestKey);
        }
        if ($st->execute()) flash_set('msg', '极验配置已保存');
        else flash_set('err', '保存失败');
    }
    if (isset($_POST['_ann_submit'])) {
        $annContent = trim($_POST['announcement'] ?? '');
        $annSave = [
            'enabled' => $annContent !== '',
            'title' => '系统公告',
            'content' => $annContent,
            'align' => in_array($_POST['ann_align'] ?? '', ['left','center']) ? $_POST['ann_align'] : 'left',
            'updated_at' => time(),
        ];
        $annJson = json_encode($annSave, JSON_UNESCAPED_UNICODE);
        $annSt = $db->prepare("REPLACE INTO mapi_config (config_key, config_value) VALUES ('announcement', ?)");
        $annSt->bind_param('s', $annJson);
        if ($annSt->execute()) flash_set('msg', '公告已' . ($annContent !== '' ? '发布' : '关闭'));
        else flash_set('err', '公告保存失败');
    }
    if (isset($_POST['_login_submit'])) {
        $loginTheme = in_array($_POST['login_theme'] ?? '', ['light','dark']) ? $_POST['login_theme'] : 'light';
        $loginBg = mb_substr(trim($_POST['login_bg'] ?? ''), 0, 500);
        $st = $db->prepare("REPLACE INTO mapi_config (config_key, config_value) VALUES ('login_theme', ?)");
        $st->bind_param('s', $loginTheme); $st->execute();
        $st = $db->prepare("REPLACE INTO mapi_config (config_key, config_value) VALUES ('login_bg', ?)");
        $st->bind_param('s', $loginBg); $st->execute();
        flash_set('msg', '登录页配置已保存');
    }
    if (isset($_POST['_key_limit_submit']) && ($_SESSION['admin_is_admin'] ?? 99) === 0) {
        $limit = max(1, min(100, (int)($_POST['key_limit'] ?? 1)));
        $json = json_encode(['limit' => $limit], JSON_UNESCAPED_UNICODE);
        $st = $db->prepare("REPLACE INTO mapi_config (config_key, config_value) VALUES ('key_limit', ?)");
        $st->bind_param('s', $json);
        if ($st->execute()) flash_set('msg', '密钥限制已更新为 ' . $limit . ' 个');
        else flash_set('err', '保存失败');
    }
    if (isset($_POST['_s3_submit']) && ($_SESSION['admin_is_admin'] ?? 99) === 0) {
        $s3Save = [
            'endpoint' => trim($_POST['s3_endpoint'] ?? ''),
            'access_key' => trim($_POST['s3_access_key'] ?? ''),
            'secret_key' => trim($_POST['s3_secret_key'] ?? ''),
            'bucket' => trim($_POST['s3_bucket'] ?? ''),
            'region' => trim($_POST['s3_region'] ?? 'auto'),
            'path_prefix' => trim($_POST['s3_path_prefix'] ?? ''),
            'custom_domain' => trim($_POST['s3_custom_domain'] ?? ''),
        ];
        $json = json_encode($s3Save, JSON_UNESCAPED_UNICODE);
        $st = $db->prepare("REPLACE INTO mapi_config (config_key, config_value) VALUES ('s3', ?)");
        $st->bind_param('s', $json);
        if ($st->execute()) flash_set('msg', 'S3 配置已保存');
        else flash_set('err', '保存失败');
    }
    // 「保存」与「保存并测试」都会落库：以前"测试连接"只探不存，用户改完点它 → 表单弹回旧值，
    // 看着就像"保存没生效"。现在两个按钮都以保存为前提。
    // 判定"这是 Redis 表单的提交"有三条依据，任一成立即可：
    //   ① 隐藏字段 `_redis_submit`（页面点击时补进表单，项目惯例）
    //   ② 提交按钮的名字（老页面/老 JS 会丢，见 base.js 里那段说明）
    //   ③ **表单字段本身**（redis_host + redis_port）—— 这条最可靠：
    //      全局 AJAX 拦截用 `new FormData(form)` 提交，字段一定在、按钮名不一定在。
    //      （实测踩过：只认按钮名时"保存"等于没点，页面重载回旧值，看着就是"配置回弹"。）
    $__isRedisForm  = isset($_POST['redis_host']) && isset($_POST['redis_port']);
    $__redisSaveReq = $__isRedisForm || isset($_POST['_redis_submit']) || isset($_POST['_redis_test']);
    if ($__redisSaveReq && ($_SESSION['admin_is_admin'] ?? 99) === 0) {
        // Redis 播放器缓存：字段名与 admin/lib/redis.php 的 redis_default_config() 一一对应。
        // 注意"密码留空 = 不改"：页面上回显的是掩码，真的空着提交不能把已存的密码抹掉。
        $old = [];
        $rr = $db->query("SELECT config_value FROM mapi_config WHERE config_key='redis'");
        if ($rr && $rx = $rr->fetch_assoc()) { $oj = json_decode((string)$rx['config_value'], true); if (is_array($oj)) $old = $oj; }
        $pwd = (string)($_POST['redis_password'] ?? '');
        if ($pwd === '' && !empty($old['password'])) $pwd = (string)$old['password'];
        // default_ttl（各类缓存 TTL 的兜底值）在设置页的 Redis 卡片里没有对应输入框，表单从不提交它。
        // 以前写 `?? 600` = 每次保存都把它重置成 600，把别处设过的值悄悄抹掉（就是"保存后配置回弹"）。
        // 与 password 同理：字段缺席就保留库里的旧值，库里也没有才用默认 600。
        $defTtl = array_key_exists('redis_default_ttl', $_POST)
            ? max(1, (int)$_POST['redis_default_ttl'])
            : (isset($old['default_ttl']) ? max(1, (int)$old['default_ttl']) : 600);

        $redisSave = [
            'enabled'     => isset($_POST['redis_enabled']) ? true : false,
            'host'        => trim((string)($_POST['redis_host'] ?? '127.0.0.1')) ?: '127.0.0.1',
            'port'        => max(1, min(65535, (int)($_POST['redis_port'] ?? 6379))),
            'password'    => $pwd,
            'database'    => max(0, min(15, (int)($_POST['redis_database'] ?? 0))),
            'prefix'      => trim((string)($_POST['redis_prefix'] ?? 'mapi:')) ?: 'mapi:',
            'default_ttl' => $defTtl,
            'timeout'     => max(1, min(10, (int)($_POST['redis_timeout'] ?? 2))),
            'ttl_url'     => max(1, (int)($_POST['redis_ttl_url'] ?? 300)),
            'ttl_lrc'     => max(1, (int)($_POST['redis_ttl_lrc'] ?? 604800)),
            'ttl_pic'     => max(1, (int)($_POST['redis_ttl_pic'] ?? 604800)),
            'ttl_cfg'     => max(0, (int)($_POST['redis_ttl_cfg'] ?? 60)),   // 0 = 不缓存配置
            'ttl_state'   => max(1, (int)($_POST['redis_ttl_state'] ?? 2592000)),
            'strict_ip'   => isset($_POST['redis_strict_ip']) ? 1 : 0,
            'ip_grace'    => max(0, (int)($_POST['redis_ip_grace'] ?? 86400)),
        ];
        // **把"被改动的输入"如实报出来**：数字项都有范围钳制（端口 1–65535、库号 0–15、超时 1–10…），
        // 以前是静默改掉 —— 用户填 30 秒被存成 10 秒，页面回显 10，看着就是"保存不生效/回弹"。
        // 表单虽然已加 type=number+min/max，但绕过前端的提交（脚本/旧页面）仍要能说清楚。
        $clampLabels = ['port'=>'端口','database'=>'库号','timeout'=>'超时(秒)','ttl_url'=>'播放地址 TTL',
                        'ttl_lrc'=>'歌词 TTL','ttl_pic'=>'封面 TTL','ttl_cfg'=>'配置 TTL','ttl_state'=>'状态 TTL','ip_grace'=>'换网宽限'];
        $clamped = [];
        foreach ($clampLabels as $k => $label) {
            if (!isset($_POST['redis_' . $k])) continue;
            $raw = trim((string)$_POST['redis_' . $k]);
            if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) {
                $clamped[] = $label . '（填的是「' . ($raw === '' ? '空' : $raw) . '」，按 ' . (int)$redisSave[$k] . ' 保存）';
            } elseif ((int)$raw !== (int)$redisSave[$k]) {
                $clamped[] = $label . '（填的是 ' . (int)$raw . '，超出范围，按 ' . (int)$redisSave[$k] . ' 保存）';
            }
        }
        $clampNote = $clamped ? '；注意有 ' . count($clamped) . ' 项超出允许范围：' . implode('，', array_slice($clamped, 0, 4)) : '';
        // 记下"最近一次保存时间"：容器里存一份、页面会显示出来 ——
        // 这样用户点完保存能**直接看到有没有写进去**，不用猜"是不是没生效"。
        $redisSave['_saved_at'] = date('Y-m-d H:i:s');
        // JSON_INVALID_UTF8_SUBSTITUTE：粘进非法字节时替换掉，而不是让 json_encode 返回 false。
        // （以前没有这个兜底：一旦编码失败，$json 就是 false，bind_param 会把**空串**写进库 →
        //   页面读出来是空 → 回落到默认值，看着就是"保存了却回到默认/原设置"且毫无报错。）
        $json = json_encode($redisSave, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        // **旧页面覆盖护栏**：表单带着"渲染那一刻的保存时间戳"。
        // 若库里已经不是那个时间戳，说明这中间有另一次保存（典型：另一个标签页 / 重复提交）——
        // 直接拒绝并说清楚，而不是让旧值悄悄盖掉新值（那看起来就是"改了又自己弹回去"）。
        $formStamp = trim((string)($_POST['redis_form_stamp'] ?? ''));
        $curStamp  = (string)($old['_saved_at'] ?? '');
        if ($json === false) {
            flash_set('err', '保存失败：配置内容无法编码（可能粘进了非法字符），原配置未改动');
        } elseif ($formStamp !== $curStamp) {
            flash_set('err', '这次保存没生效：配置在你打开页面之后被改过'
                . ($curStamp !== '' ? '（库里最近一次保存是 ' . $curStamp . '）' : '（库里还没有保存记录）')
                . '。请刷新页面确认最新值后再保存。');
            mapi_settings_diag('redis_stale', ['form' => $formStamp, 'db' => $curStamp]);
        } else {
            $st = $db->prepare("REPLACE INTO mapi_config (config_key, config_value) VALUES ('redis', ?)");
            $st->bind_param('s', $json);
            $writeOk = $st->execute();
            if (!$writeOk) flash_set('err', '保存失败（Redis 缓存配置没写进数据库）');
            elseif (!isset($_POST['_redis_test'])) flash_set('msg', 'Redis 缓存配置已保存' . ($redisSave['enabled'] ? '（已启用）' : '（未启用）') . $clampNote);
            mapi_settings_diag('redis_write', ['ok' => (bool)$writeOk, 'affected' => (int)$st->affected_rows,
                'clamped' => $clamped, 'saved' => array_diff_key($redisSave, ['password' => 1])]);
        }
    }
    if (isset($_POST['_redis_test']) && ($_SESSION['admin_is_admin'] ?? 99) === 0) {
        // 用"刚提交的表单值"探（配置已经在上面落库了，这里再按表单值探一次，等价于"保存并测试"）
        $probe = [
            'enabled' => true,
            'host' => trim((string)($_POST['redis_host'] ?? '127.0.0.1')),
            'port' => (int)($_POST['redis_port'] ?? 6379),
            'password' => ($_POST['redis_password'] ?? '') !== '' ? (string)$_POST['redis_password'] : (function () use ($db) {
                $r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='redis'");
                if ($r && $x = $r->fetch_assoc()) { $j = json_decode((string)$x['config_value'], true); if (is_array($j)) return (string)($j['password'] ?? ''); }
                return '';
            })(),
            'database' => (int)($_POST['redis_database'] ?? 0),
            'prefix' => trim((string)($_POST['redis_prefix'] ?? 'mapi:')),
            'timeout' => (int)($_POST['redis_timeout'] ?? 2),
        ];
        $GLOBALS['__redis_probe_cfg'] = $probe;      // redis_conf(true) 会优先用它（见 redis.php）
        $d = redis_diagnose();
        // 用完必须清掉：这个全局排在 redis_conf() 的进程内缓存**之前**，留着会劫持本请求后续所有 Redis 调用
        unset($GLOBALS['__redis_probe_cfg']);
        // **按实测结果同步手动开关**（用户点「保存并测试」＝明确动作）：
        //   连得上 → 开关置为开；连不上 → 开关置为关。
        // 注意：只在用户点这个按钮时同步。运行时的"自动停用"**绝不写库** ——
        // 一旦写库就会覆盖用户的手动意图，那才是真正的"配置回弹"。
        $wantEnabled = (bool)$d['ok'];
        $autoNote = '';
        if (isset($redisSave) && is_array($redisSave)) {
            if ((bool)$redisSave['enabled'] !== $wantEnabled) {
                $redisSave['enabled'] = $wantEnabled;
                $redisSave['_saved_at'] = date('Y-m-d H:i:s');
                $json2 = json_encode($redisSave, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $db->query("REPLACE INTO mapi_config (config_key, config_value) VALUES ('redis', '"
                    . $db->real_escape_string((string)$json2) . "')");
                $autoNote = $wantEnabled ? ' · 连通正常，已自动开启开关'
                                         : ' · 连不上，已自动关闭开关（地址修好后点「保存并测试」会自动再开）';
            } else {
                $autoNote = $wantEnabled ? ' · 连通正常，开关保持开启'
                                         : ' · 连不上，开关保持关闭（可修地址后再试）';
            }
        }
        flash_set($d['ok'] ? 'msg' : 'err', '已保存并测试：' . $d['msg'] . '（' . $d['target'] . '，前缀 ' . $d['prefix'] . '）' . $autoNote . $clampNote);
    }
    if (isset($_POST['_api_submit']) && ($_SESSION['admin_is_admin'] ?? 99) === 0) {
        $apiSave = [
            'meting' => trim($_POST['api_meting'] ?? ''),
            'qq_referer' => trim($_POST['api_qq_referer'] ?? ''),
            'qq_cover' => trim($_POST['api_qq_cover'] ?? ''),
            'param_id' => trim($_POST['api_param_id'] ?? 'id') ?: 'id',
            'param_auth' => trim($_POST['api_param_auth'] ?? 'auth') ?: 'auth',
            'req_params' => [
                'server' => trim($_POST['api_req_server'] ?? 'server') ?: 'server',
                'type' => trim($_POST['api_req_type'] ?? 'type') ?: 'type',
                'id' => trim($_POST['api_req_id'] ?? 'id') ?: 'id',
            ],
            'fields' => [
                'title' => trim($_POST['api_field_title'] ?? 'title') ?: 'title',
                'artist' => trim($_POST['api_field_artist'] ?? 'author') ?: 'author',
                'url' => trim($_POST['api_field_url'] ?? 'url') ?: 'url',
                'pic' => trim($_POST['api_field_pic'] ?? 'pic') ?: 'pic',
                'lrc' => trim($_POST['api_field_lrc'] ?? 'lrc') ?: 'lrc',
            ],
        ];
        $json = json_encode($apiSave, JSON_UNESCAPED_UNICODE);
        $st = $db->prepare("REPLACE INTO mapi_config (config_key, config_value) VALUES ('mapi_api', ?)");
        $st->bind_param('s', $json);
        if ($st->execute()) flash_set('msg', '音乐 API 配置已保存');
        else flash_set('err', '保存失败');

        $cfgData = require $configFile;
        if (!isset($cfgData['api'])) $cfgData['api'] = [];
        $cfgData['api']['base_url']   = $apiSave['meting'];
        $cfgData['api']['qq_referer'] = $apiSave['qq_referer'];
        $cfgData['api']['qq_cover']   = $apiSave['qq_cover'];
        $newContent = "<?php\nreturn " . var_export($cfgData, true) . ";\n";
        file_put_contents($configFile, $newContent);
    }
    if (isset($_POST['smtp_host']) && ($_SESSION['admin_is_admin'] ?? 99) === 0) {
        $smtpSave = [
            'host' => trim($_POST['smtp_host'] ?? ''),
            'port' => (int)($_POST['smtp_port'] ?? 465),
            'user' => trim($_POST['smtp_user'] ?? ''),
            'pass' => trim($_POST['smtp_pass'] ?? ''),
            'encrypt' => in_array($_POST['smtp_encrypt'] ?? '', ['ssl','tls','none']) ? $_POST['smtp_encrypt'] : 'ssl',
            'from' => trim($_POST['smtp_from'] ?? ''),
            'name' => trim($_POST['smtp_name'] ?? ''),
        ];
        $json = json_encode($smtpSave, JSON_UNESCAPED_UNICODE);
        $st = $db->prepare("REPLACE INTO mapi_config (config_key, config_value) VALUES ('smtp', ?)");
        $st->bind_param('s', $json);
        if ($st->execute()) flash_set('msg', 'SMTP 配置已保存');
        else flash_set('err', '保存失败');
    }
    // 邮件模板 — 自动建表 + 迁移旧数据
    if (($_SESSION['admin_is_admin'] ?? 99) === 0) {
        $isMysql = !defined('DB_SQLITE');
        if ($isMysql) {
            $db->query("CREATE TABLE IF NOT EXISTS `mapi_mail_templates` (`id` INT NOT NULL AUTO_INCREMENT, `name` VARCHAR(100) NOT NULL DEFAULT '', `subject` VARCHAR(200) NOT NULL DEFAULT '顺雅音乐 - 验证码邮件', `body` TEXT, `is_html` TINYINT NOT NULL DEFAULT 0, `is_default` TINYINT NOT NULL DEFAULT 0, `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $db->query("CREATE TABLE IF NOT EXISTS mapi_mail_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL DEFAULT '', subject VARCHAR(200) NOT NULL DEFAULT '顺雅音乐 - 验证码邮件', body TEXT, is_html INTEGER NOT NULL DEFAULT 0, is_default INTEGER NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        }
        // 迁移旧 mail_template 配置到新表
        $cnt = $db->query("SELECT COUNT(*) as c FROM mapi_mail_templates");
        if ($cnt && ($crow = $cnt->fetch_assoc()) && (int)$crow['c'] === 0) {
            $rold = $db->query("SELECT config_value FROM mapi_config WHERE config_key='mail_template'");
            if ($rold && $oldrow = $rold->fetch_assoc()) {
                $tpl = json_decode($oldrow['config_value'], true);
                if (is_array($tpl)) {
                    $nm = trim($tpl['name'] ?? '') ?: '默认模板';
                    $sj = trim($tpl['subject'] ?? '') ?: '顺雅音乐 - 验证码邮件';
                    $bd = $tpl['body'] ?? '';
                    $ht = !empty($tpl['html']) ? 1 : 0;
                    $ins = $db->prepare("INSERT INTO mapi_mail_templates (name, subject, body, is_html, is_default) VALUES (?, ?, ?, ?, 1)");
                    if ($ins) { $ins->bind_param('sssi', $nm, $sj, $bd, $ht); $ins->execute(); }
                }
            }
        }
        // 保存/新增模板（AJAX）
        if (isset($_POST['_mail_tpl_save'])) {
            $tplId = (int)($_POST['_mail_tpl_id'] ?? 0);
            $nm = trim($_POST['mail_tpl_name'] ?? '');
            if ($nm === '') $nm = '未命名模板';
            $sj = trim($_POST['mail_tpl_subject'] ?? '') ?: '顺雅音乐 - 验证码邮件';
            $bd = trim($_POST['mail_tpl_body'] ?? '');
            $ht = !empty($_POST['mail_tpl_html']) ? 1 : 0;
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            if ($tplId > 0) {
                $st = $db->prepare("UPDATE mapi_mail_templates SET name=?, subject=?, body=?, is_html=? WHERE id=?");
                $st->bind_param('sssii', $nm, $sj, $bd, $ht, $tplId);
            } else {
                $st = $db->prepare("INSERT INTO mapi_mail_templates (name, subject, body, is_html) VALUES (?, ?, ?, ?)");
                $st->bind_param('sssi', $nm, $sj, $bd, $ht);
            }
            if ($st->execute()) {
                $newId = $tplId > 0 ? $tplId : $db->insert_id;
                if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'id' => $newId, 'name' => $nm, 'subject' => $sj, 'body' => $bd, 'is_html' => $ht]); exit; }
                flash_set('msg', '邮件模板已保存');
            } else {
                if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'error' => '保存失败']); exit; }
                flash_set('err', '保存失败');
            }
        }
        // 删除模板（AJAX）
        if (isset($_POST['_mail_tpl_delete'])) {
            $id = (int)($_POST['_mail_tpl_id'] ?? 0);
            $db->query("DELETE FROM mapi_mail_templates WHERE id=" . $id);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true]); exit;
        }
        // 设为默认（AJAX）
        if (isset($_POST['_mail_tpl_default'])) {
            $id = (int)($_POST['_mail_tpl_id'] ?? 0);
            $db->query("UPDATE mapi_mail_templates SET is_default=0");
            $st = $db->prepare("UPDATE mapi_mail_templates SET is_default=1 WHERE id=?");
            $st->bind_param('i', $id); $st->execute();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true]); exit;
        }
        // 非 AJAX 则重定向
        if (!(isset($_POST['_mail_tpl_save']) || isset($_POST['_mail_tpl_delete']) || isset($_POST['_mail_tpl_default']))) {
            header('Location: ?action=' . $action_key); exit;
        }
        exit;
    }
    header('Location: ?action=' . $action_key); exit;
}