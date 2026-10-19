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
        $geetestKeyRaw    = trim($_POST['geetest_key'] ?? '');
        $gRow = null;
        $r = $db->query("SELECT id, captcha_id, `key` FROM mapi_geetest ORDER BY id ASC LIMIT 1");
        if ($r && $x = $r->fetch_assoc()) $gRow = $x;
        if ($gRow) {
            // ★ 两个真 bug 一起修：
            //   ① 旧 SQL 是 `UPDATE mapi_geetest SET captcha_id=?, key=?` —— **没有 WHERE**！
            //      现在只有 1 行所以"看着没事"，一旦多行就会**全表覆盖**（把 A 行的值写到 B 行）。
            //      改为按主键定位那一行。
            //   ② 旧代码把 `$_POST` 的值**无条件**写进去 ⇒ 只提交 captcha_id 的表单会把 `key` 清空。
            //      改为"键不在请求里 ⇒ 不动"。这不是理论问题：设置页有多个卡片共用一个 POST 出口。
            //   ③ 掩码回显（页面显示 `••••1234`）⇒ **掩码形态视为未修改**，否则掩码会被当密钥存进去。
            $gCaptcha = array_key_exists('geetest_captcha_id', $_POST) ? $geetestCaptchaId : (string)$gRow['captcha_id'];
            if (array_key_exists('geetest_key', $_POST)) {
                if ($geetestKeyRaw === '') {
                    // 页面承诺"留空则关闭极验" ⇒ **显式清空**（不是"未修改"）。
                    // ⚠ 但极验的开关判据是 `captcha_id !== '' && key !== ''`（见 admin/login/index.php:326 的 $geetestOn）
                    //   ⇒ 只清 key 会让页面显示"已关闭"而实际**仍然开着照样校验**（静默不一致）。
                    //   所以这里把 captcha_id 一起清掉，让"关闭"真的成立。
                    $gKey = '';
                    $gCaptcha = '';
                } elseif (mb_substr($geetestKeyRaw, 0, 1) === '•') {
                    $gKey = (string)$gRow['key'];        // 掩码 = 未修改（页面回显的是 `••••1234`）
                } else {
                    $gKey = $geetestKeyRaw;              // 真填了新密钥 ⇒ 覆盖
                }
            } else {
                $gKey = (string)$gRow['key'];            // 键没出现 ⇒ 不动
            }
            $gid = (int)$gRow['id'];
            $st = $db->prepare("UPDATE mapi_geetest SET captcha_id=?, `key`=? WHERE id=?");
            $st->bind_param('ssi', $gCaptcha, $gKey, $gid);
        } else {
            $st = $db->prepare("INSERT INTO mapi_geetest (captcha_id, `key`) VALUES (?, ?)");
            $st->bind_param('ss', $geetestCaptchaId, $geetestKeyRaw);
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
    // ── 演示播放器：首页「启动播放器」用哪条密钥 ────────────────────────────
    //
    // ★ 必须做**服务端归属校验**，不能信前端：
    //   `demo_key` 会被拼进首页 HTML（`<script src="api.php?key=…">`）下发给所有访客，
    //   若允许提交任意密钥，就等于让任何后台用户把**别人的**密钥公开到站点首页。
    //   所以这里按「属于当前登录者 且 已启用」双重条件落库，与页面下拉框的筛选口径一致。
    //   超管（is_admin=0）同样只限自己的密钥 —— 这是产品要求，也是最小权限。
    if (isset($_POST['_demo_submit'])) {
        $demoKeyNew = trim((string)($_POST['demo_key'] ?? ''));
        $demoOwnerId = (int)($_SESSION['admin_id'] ?? 0);
        $demoCheckOk = true;
        if ($demoKeyNew !== '') {
            // 归属 + 启用 双重校验（与页面下拉框筛选口径一致）
            $demoCheckOk = false;
            if ($demoOwnerId > 0) {
                $dSt = $db->prepare(
                    "SELECT id FROM mapi_keys WHERE api_key=? AND user_id=? AND status=1 LIMIT 1"
                );
                if ($dSt) {
                    $dSt->bind_param('si', $demoKeyNew, $demoOwnerId);
                    $dSt->execute();
                    $dRes = $dSt->get_result();
                    $demoCheckOk = (bool)($dRes && $dRes->fetch_assoc());
                }
            }
        }
        if (!$demoCheckOk) {
            // 只提示、不写库：避免非法值把原有正确配置覆盖掉。
            // ⚠ 这里不能 `return;` —— 本文件末尾还有统一的回跳（L447），
            //   提前 return 会让页面停在一个空响应上。
            flash_set('err', '只能选择你自己的、且已启用的密钥');
        } else {
            // 读-改-写：只动 demo_key，`jwt_secret` 原样保留（这正是不能整行 REPLACE 的原因）
            $demoRes = save_mapi_config_section($db, 'api_secrets', ['demo_key' => $demoKeyNew]);
            if (!empty($demoRes['ok'])) {
                flash_set('msg', $demoKeyNew === '' ? '演示播放器已关闭' : '演示播放器已更新');
            } else {
                flash_set('err', '保存失败：' . (string)($demoRes['error'] ?? '未知错误'));
            }
        }
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
        // ★ 同 SMTP：以前整行 REPLACE，少带的字段会被清空（尤其 `secret_key`）。
        //   注意 `secret_key` 也是掩码字段：空着提交 = 不改（否则一次保存就把密钥抹掉）。
        $s3Res = merge_mapi_config_from_post($db, 's3', [
            'endpoint'      => 's3_endpoint',
            'access_key'    => 's3_access_key',
            'secret_key'    => 's3_secret_key',
            'bucket'        => 's3_bucket',
            'region'        => 's3_region',
            'path_prefix'   => 's3_path_prefix',
            'custom_domain' => 's3_custom_domain',
        ], [
            'transform' => [
                // 掩码或空串 = 未修改 ⇒ 不动（与 `smtp.pass` 同理，见上面的说明）
                'secret_key' => static function ($v) use ($db) {
                    if ($v !== '' && mb_substr($v, 0, 1) !== '•') return $v;
                    $cur = read_mapi_config_section($db, 's3');
                    return array_key_exists('secret_key', $cur) && (string)$cur['secret_key'] !== '' ? null : $v;
                },
            ],
        ]);
        if ($s3Res['ok']) flash_set('msg', 'S3 配置已保存');
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
        // ★ 统一走共享收口点 `merge_mapi_config_from_post()`（定义在 assets/lib/api_config.php）。
        //   为什么要收成一个函数：这种"拿请求里的部分字段拼一整行再 REPLACE"的形状在本会话
        //   已出现**三次**（`websocket.api_url` / `mapi_api` / 更早的"上游音频 API 地址被清空"）——
        //   说明它不是某一处写错，而是**写法成片存在**。每个 handler 各修一份等于等着第四次，
        //   所以"怎么从表单生成 patch"只允许有一个实现，各处只声明字段映射。
        //   规则（与 `save_mapi_config_section()` 一致）：
        //     · POST 键不在请求里 ⇒ 不进 patch ⇒ 库里保持原值（不误伤别的卡片/脚本/旧表单）
        //     · POST 键在请求里 ⇒ 写入该值；空串就是**显式清空**（读取器会回落 config.php 的 api 段）
        //     · `req_params` / `fields` 是嵌套对象 ⇒ **逐子键合并**，不整块替换
        //   ⚠ 字段映射里的**存储键**必须与历史一致（`meting`/`req_params`/`fields`），
        //     不能写视图形键（`base_url`/`field_*`）—— 混两种形状会把这一行写坏（踩过）。
        $apiRes = merge_mapi_config_from_post($db, 'mapi_api', [
            'meting'     => 'api_meting',
            'qq_referer' => 'api_qq_referer',
            'qq_cover'   => 'api_qq_cover',
            'param_id'   => 'api_param_id',
            'param_auth' => 'api_param_auth',
            'req_params' => [['api_req_server', 'server'], ['api_req_type', 'type'], ['api_req_id', 'id']],
            'fields'     => [['api_field_title', 'title'], ['api_field_artist', 'artist'],
                             ['api_field_url', 'url'], ['api_field_pic', 'pic'], ['api_field_lrc', 'lrc']],
        ], [
            // 页面文案是"留空则用默认" ⇒ 这两个键空串时写默认值，而不是清空
            'defaults' => [
                'param_id' => 'id', 'param_auth' => 'auth',
                'req_params.server' => 'server', 'req_params.type' => 'type', 'req_params.id' => 'id',
                'fields.title' => 'title', 'fields.artist' => 'author', 'fields.url' => 'url',
                'fields.pic' => 'pic', 'fields.lrc' => 'lrc',
            ],
        ]);
        $apiOk = (bool)$apiRes['ok'];

        // WebSocket/实时通道（歌厅）已于 2026-10-20 随歌厅功能一并下线，
        // 本分支不再读写 websocket 配置行。保存结果只看音乐 API。
        if ($apiOk) {
            flash_set('msg', '音乐 API 配置已保存');
        } else {
            flash_set('err', '保存失败');
        }
        // ⚠ 这里**故意不再写回 `config/config.php`**（2026-10-16 用户批准删除）。
        //   旧行为：保存「音乐 API」时，除写库外还 `file_put_contents($configFile, ...)`，
        //   把 `api.base_url/qq_referer/qq_cover` 重新塞回文件。两个后果都很坏：
        //     ① 用户点一次保存，已经从文件删掉的 `api` 段就**复活一段** ⇒
        //        "配置从文件搬进库、按功能暴露到后台"会被逐步推翻（实测 05:26:22 文件从 256 B 变 395 B）；
        //     ② 它是 `var_export` 整文件重写 ⇒ 把 config.php 里的注释**全部剥掉**，且不可逆。
        //   ⇒ **库现在是唯一来源**；文件只由 **install 初始化**写（首次安装要落一个能启动的 db 段）。
        //   ⇒ 设置页保存只走 `save_mapi_config_section()`（读-改-写），不碰文件。
    }
    if (isset($_POST['smtp_host']) && ($_SESSION['admin_is_admin'] ?? 99) === 0) {
        // ★ 收口到 `merge_mapi_config_from_post()`：以前不论请求里有没有这些字段，都拼一个
        //   **完整** $smtpSave 再 REPLACE 整行 ⇒ 任何少带字段的提交都会把没带的键清空
        //   （尤其 `pass`：空着提交会把已存密码抹掉）。现在键不在请求里就不动。
        $smtpMap = [
            'host'    => 'smtp_host',
            'port'    => 'smtp_port',
            'user'    => 'smtp_user',
            'pass'    => 'smtp_pass',
            'encrypt' => 'smtp_encrypt',
            'from'    => 'smtp_from',
            'name'    => 'smtp_name',
        ];
        $smtpRes = merge_mapi_config_from_post($db, 'smtp', $smtpMap, [
            'transform' => [
                // 端口钳到合理范围（原来直接 (int)，0/负数/超大值都会写进去）
                'port' => static fn($v) => (string)max(1, min(65535, (int)$v)),
                // 加密方式只认三个取值，非法值按 ssl（与旧行为一致）
                'encrypt' => static fn($v) => in_array($v, ['ssl', 'tls', 'none'], true) ? $v : 'ssl',
                // 密码：页面回显的是掩码 `••••1234`；**掩码形态 或 空串 都视为"未修改"** ⇒ 不动该键。
                //   为什么必须认掩码：掩码不是真密码，若当新值存进去，用户下次就登不上，
                //   而界面看着"保存成功"（静默损坏）。规则与 `websocket` 段一致。
                'pass' => static function ($v) use ($db) {
                    if ($v !== '' && mb_substr($v, 0, 1) !== '•') return $v;   // 真填了新密码 ⇒ 覆盖
                    $cur = read_mapi_config_section($db, 'smtp');
                    return array_key_exists('pass', $cur) && (string)$cur['pass'] !== '' ? null : $v;
                },
            ],
        ]);
        if ($smtpRes['ok']) flash_set('msg', 'SMTP 配置已保存');
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
            header('Location: ' . admin_url((string)$action_key)); exit;
        }
        exit;
    }
    header('Location: ' . admin_url((string)$action_key)); exit;
}