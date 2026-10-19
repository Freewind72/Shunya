<?php

if ($action === 'keys-create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    // 回跳目标统一用 admin_url()：?action=keys 是老式相对地址，在路径路由
    // （/admin/pages/keys/）下会拼到当前 URL 上，语义混乱。
    $keysBack = admin_url('keys');
    $isAdmin = $_SESSION['admin_is_admin'] ?? 99;
    if ($isAdmin > 1) {
        $limit = 1;
        $r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='key_limit'");
        if ($r && $row = $r->fetch_assoc()) {
            $limitCfg = json_decode($row['config_value'], true);
            if (is_array($limitCfg) && isset($limitCfg['limit'])) $limit = max(1, (int)$limitCfg['limit']);
        }
        $cntR = $db->query("SELECT COUNT(*) as cnt FROM mapi_keys WHERE user_id=" . (int)$_SESSION['admin_id']);
        $cnt = $cntR ? (int)$cntR->fetch_assoc()['cnt'] : 0;
        if ($cnt >= $limit) {
            flash_set('err', '已达到密钥创建上限（' . $limit . ' 个），请联系管理员');
            header('Location: ' . $keysBack); exit;
        }
    }
    // 名称：创建弹窗里必填，用来区分「这条密钥是给哪个站/哪个用途的」
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        flash_set('err', '请先填写密钥名称');
        header('Location: ' . admin_url('keys', ['create' => 1])); exit;
    }
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 32) : substr($name, 0, 32);

    // 授权域名：可留空（留空=不绑定域名）；填了则校验格式，并同时写进 mapi_domains 授权
    require_once __DIR__ . '/../lib/domains.php';
    $domain = '';
    $domainRaw = trim((string)($_POST['domain'] ?? ''));
    if ($domainRaw !== '') {
        $domain = domain_normalize($domainRaw);
        if ($domain === '' || strlen($domain) > 190) {
            flash_set('err', '域名格式不正确：只填主机名，例如 blog.example.com');
            header('Location: ' . admin_url('keys', ['create' => 1])); exit;
        }
    }

    $k = bin2hex(random_bytes(16));
    $stmt = $db->prepare("INSERT INTO mapi_keys (user_id, api_key, name, domain, status) VALUES (?, ?, ?, ?, 1)");
    $stmt->bind_param('isss', $_SESSION['admin_id'], $k, $name, $domain);
    if (!$stmt->execute()) {
        flash_set('err', '创建失败');
        header('Location: ' . admin_url('keys', ['create' => 1])); exit;
    }

    $domainMsg = '';
    if ($domain !== '') {
        $uid = (int)$_SESSION['admin_id'];
        if (!domains_table_exists($db)) {
            $domainMsg = '；但域名表不存在，请先执行 install/sql/mysql.sql 补表';
        } else {
            $exRow = null;
            $ex = $db->prepare("SELECT id, authorized FROM mapi_domains WHERE user_id=? AND domain=? LIMIT 1");
            if ($ex) {
                $ex->bind_param('is', $uid, $domain);
                $ex->execute();
                $exRes = $ex->get_result();
                $exRow = $exRes ? $exRes->fetch_assoc() : null;
            }
            if ($exRow) {
                // 已有这条域名：只把「已授权」打开，其余设置（偏移/备注/自动探测）不动
                if ((int)$exRow['authorized'] !== 1) {
                    $up = $db->prepare("UPDATE mapi_domains SET authorized=1 WHERE id=?");
                    if ($up) { $up->bind_param('i', $exRow['id']); $up->execute(); }
                }
                $domainMsg = '，并把域名 ' . $domain . ' 设为已授权';
            } else {
                $note = '密钥「' . $name . '」创建时授权';
                $ins = $db->prepare("INSERT INTO mapi_domains (user_id, domain, authorized, auto, lyrics_bottom, player_bottom, note, auto_added) VALUES (?, ?, 1, 1, 0, NULL, ?, 0)");
                if ($ins) {
                    $ins->bind_param('iss', $uid, $domain, $note);
                    if ($ins->execute()) $domainMsg = '，并授权域名 ' . $domain;
                    else $domainMsg = '；但域名授权失败，请到「域名」页手动添加';
                } else {
                    $domainMsg = '；但域名授权失败，请到「域名」页手动添加';
                }
            }
        }
    }
    if (function_exists('cfg_cache_invalidate')) cfg_cache_invalidate();
    flash_set('msg', '密钥「' . $name . '」已创建' . $domainMsg);
    header('Location: ' . $keysBack); exit;
}

if ($action === 'keys-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $kid = (int)($_POST['id'] ?? 0);
    $keysBack = admin_url('keys');
    if (!$kid) { flash_set('err', '参数不完整'); header('Location: ' . $keysBack); exit; }

    // 归属：超管（is_admin=0）可删任意密钥；其他人只能删自己的。
    // 先取行 + 关联统计：删除确认里要展示「会丢多少歌单/歌曲」，且级联清理要用。
    $kSt = $db->prepare("SELECT id, name, user_id FROM mapi_keys WHERE id=? LIMIT 1");
    $kSt->bind_param('i', $kid);
    $kSt->execute();
    $kRow = $kSt->get_result()->fetch_assoc();
    if (!$kRow || ((($_SESSION['admin_is_admin'] ?? 99) !== 0) && (int)$kRow['user_id'] !== (int)$_SESSION['admin_id'])) {
        flash_set('err', '无权限或密钥不存在'); header('Location: ' . $keysBack); exit;
    }

    /* ── 级联清理（2026-10-20）：删密钥必须带走它的歌单数据 ─────────────
       原来只 DELETE mapi_keys 一行 ⇒ 歌单/歌曲全部变孤儿（实测库里有 6 歌单 + 44 首歌
       挂在已删 key 上）。孤儿歌单虽然界面上看不见，但会：
         · 让「导入同名跳过」等逻辑的语义变得不可预期；
         · 永远占着存储与 sort_order 空间；
         · 如果将来 key_id 复用，会"复活"成别人的歌单。
       清理顺序（先明细后主行）：
         ① 记下歌曲引用 → 删 mapi_songs
         ② 删 mapi_playlists
         ③ 歌曲封面无其它引用则 unset（与 playlist-delete 同一套惯例）
         ④ 解绑域名（mapi_domains.key_id 指向此密钥的行清 0，域名本身保留——
            域名是用户资产，不因删密钥而丢授权）
       ⚠ 不用外键 CASCADE：表是 MyISAM 时代的无外键设计，且手工清理可以在
         flash 消息里如实汇报删了什么。 */
    $plCount = 0; $songCount = 0;
    $c1 = $db->query("SELECT COUNT(*) n FROM mapi_playlists WHERE key_id=$kid");
    if ($c1) $plCount = (int)$c1->fetch_assoc()['n'];
    $c2 = $db->query("SELECT COUNT(*) n FROM mapi_songs WHERE key_id=$kid");
    if ($c2) $songCount = (int)$c2->fetch_assoc()['n'];

    // 歌曲封面引用统计（先记，删完 songs 后判断是否还有别的歌引用）
    $coverRefs = [];
    $cr = $db->query("SELECT DISTINCT song_id, server FROM mapi_songs WHERE key_id=$kid");
    if ($cr) while ($d = $cr->fetch_assoc()) $coverRefs[] = [($d['server'] ?: 'netease'), (string)$d['song_id']];

    $db->query("DELETE FROM mapi_songs WHERE key_id=$kid");
    $db->query("DELETE FROM mapi_playlists WHERE key_id=$kid");

    // 歌曲封面：全站已无引用才清（同一首歌可能被多个密钥的歌单引用过）
    foreach ($coverRefs as $ref) {
        list($refServer, $refSongId) = $ref;
        $songEsc = $db->real_escape_string($refSongId);
        $srvEsc  = $db->real_escape_string($refServer);
        $still = $db->query("SELECT COUNT(*) n FROM mapi_songs WHERE song_id='$songEsc' AND server='$srvEsc'");
        if ($still && $n = $still->fetch_assoc()) {
            if ((int)$n['n'] === 0 && function_exists('cover_song_unset')) {
                cover_song_unset($db, $refServer, $refSongId);
            }
        }
    }

    // 域名解绑：只清 key_id 指向，不动 authorized（域名授权是用户资产）
    $db->query("UPDATE mapi_domains SET key_id=0 WHERE key_id=$kid");

    // 删除密钥行：超管无归属限制（上面已手工判过归属）；其他人强制带 user_id 兜底
    if (($_SESSION['admin_is_admin'] ?? 99) === 0) {
        $dSt = $db->prepare("DELETE FROM mapi_keys WHERE id=?");
        $dSt->bind_param('i', $kid);
    } else {
        $dSt = $db->prepare("DELETE FROM mapi_keys WHERE id=? AND user_id=?");
        $dSt->bind_param('ii', $kid, $_SESSION['admin_id']);
    }
    $dSt->execute();

    if ($dSt->affected_rows > 0) {
        if (function_exists('cfg_cache_invalidate')) cfg_cache_invalidate();
        flash_set('msg', '密钥「' . ($kRow['name'] !== '' ? $kRow['name'] : ('#' . $kid)) . '」已删除'
            . ($plCount > 0 ? "（含 {$plCount} 个歌单" : '')
            . ($songCount > 0 ? "、{$songCount} 首歌）" : ($plCount > 0 ? '）' : '')));
    } else {
        flash_set('err', '删除失败');
    }
    header('Location: ' . $keysBack); exit;
}

// ── 歌单配置导出（GET，浏览器直接下载）──────────────────────────────────
//
// 导出内容口径（产品要求）：**只有歌单列表本身** ——
//   · 不导出密钥（api_key / key_id 一律不出现在 JSON 里）
//   · 不导出密钥名（key 的 name / domain 不出现）
//   · 导出：歌单名 / 类型 / remote_id / server / 封面模式 / 排序 / 歌曲明细
//   · cover_data（封面 base64，可能几 MB）不导 —— 导入时按 cover_mode 自动重建
//
// 归属：普通用户只能导自己的；超管（is_admin=0）可导任意密钥（与 keys-delete 同口径）。
if ($action === 'keys-export') {
    $kid = (int)($_GET['key_id'] ?? 0);
    if (!$kid) { http_response_code(400); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'missing key_id']); exit; }
    if (($_SESSION['admin_is_admin'] ?? 99) === 0) {
        $kSt = $db->prepare("SELECT id, name, user_id FROM mapi_keys WHERE id=? LIMIT 1");
        $kSt->bind_param('i', $kid);
    } else {
        $kSt = $db->prepare("SELECT id, name, user_id FROM mapi_keys WHERE id=? AND user_id=? LIMIT 1");
        $kSt->bind_param('ii', $kid, $_SESSION['admin_id']);
    }
    $kSt->execute();
    $kRow = $kSt->get_result()->fetch_assoc();
    if (!$kRow) {
        http_response_code(403); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'无权限或密钥不存在']); exit;
    }

    /* ── 下载文件名：密钥名 + 用户名 + 时间戳（2026-10-20，产品要求）────────
       口径：`密钥名-用户名-YmdHis.json`；密钥名为空（老版本创建的密钥没有 name）
       就退化为 `用户名-YmdHis.json`。
       ⚠ 文件名里只有**密钥名**（如"Zeph 小屋"）和**用户名**，不含 api_key ——
         与 JSON 内容"不含密钥"的口径一致，文件名同样不能泄露凭据。
       ⚠ RFC 6266：非 ASCII 文件名必须用 filename*=UTF-8'' 编码，同时保留一个
         ASCII 兜底 filename（旧浏览器/代理只认它）。两个都给，编码版优先。 */
    $keyName  = trim((string)($kRow['name'] ?? ''));
    $uname    = '';
    $uSt = $db->prepare("SELECT username FROM mapi_users WHERE id=? LIMIT 1");
    if ($uSt) {
        $uidX = (int)$kRow['user_id'];
        $uSt->bind_param('i', $uidX);
        $uSt->execute();
        if ($uRow = $uSt->get_result()->fetch_assoc()) $uname = trim((string)$uRow['username']);
    }
    $stamp = date('Ymd-His');

    // 歌单（不选 key_id / cover_data / synced_at 这些内部字段）
    $pls = [];
    $pr2 = $db->query("SELECT id, name, type, remote_id, server, cover_mode, sort_order FROM mapi_playlists WHERE key_id=$kid ORDER BY sort_order ASC, id ASC");
    if ($pr2) while ($p = $pr2->fetch_assoc()) $pls[] = $p;

    // 每个歌单的歌曲
    $out = [
        'version'     => 1,
        'exported_at' => date('c'),
        'playlists'   => [],
    ];
    foreach ($pls as $p) {
        $pid = (int)$p['id'];
        $songs = [];
        $sr = $db->query("SELECT song_id, name, artist, server, sort_order, missing FROM mapi_songs WHERE playlist_id=$pid ORDER BY sort_order ASC, id ASC");
        if ($sr) while ($s = $sr->fetch_assoc()) $songs[] = $s;
        $out['playlists'][] = [
            'name'       => (string)$p['name'],
            'type'       => (string)$p['type'],
            'remote_id'  => (string)$p['remote_id'],
            'server'     => (string)$p['server'],
            'cover_mode' => (string)$p['cover_mode'],
            'sort_order' => (int)$p['sort_order'],
            'songs'      => $songs,
        ];
    }

    // 文件名组装放在数据查完之后、输出之前：密钥名 + 用户名 + 时间戳
    $nameBase = ($keyName !== '' ? $keyName . '-' : '') . ($uname !== '' ? $uname : 'user' . (int)$kRow['user_id']) . '-' . $stamp;
    // 文件名净化：去掉路径分隔符与 Windows 非法字符，控制空白
    $nameBase = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]/u', '', $nameBase);
    $nameBase = trim($nameBase) !== '' ? trim($nameBase) : 'export-' . $stamp;
    $asciiFallback = 'msapi-playlists-' . $kid . '-' . $stamp;   // 纯 ASCII 兜底（保留 key_id，不影响内容）
    $encoded = rawurlencode($nameBase) . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$asciiFallback}.json\"; filename*=UTF-8''{$encoded}");
    header('Cache-Control: no-store');
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── 歌单配置导入（POST，接收上传的 JSON）────────────────────────────────
//
// 入口在**配置页**每个密钥卡上 ⇒ 所有分支的回跳目标都是 config 页。
// 策略：**追加**不覆盖 —— 已有同名歌单时跳过（避免误导入把现有歌单搞重复/搞乱）；
//       歌曲逐条 INSERT，sort_order 按 JSON 里的顺序。
if ($action === 'keys-import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $kid = (int)($_POST['key_id'] ?? 0);
    if (!$kid) { flash_set('err', '参数不完整'); header('Location: ' . admin_url('config')); exit; }
    if (($_SESSION['admin_is_admin'] ?? 99) === 0) {
        $kSt = $db->prepare("SELECT id FROM mapi_keys WHERE id=? LIMIT 1");
        $kSt->bind_param('i', $kid);
    } else {
        $kSt = $db->prepare("SELECT id FROM mapi_keys WHERE id=? AND user_id=? LIMIT 1");
        $kSt->bind_param('ii', $kid, $_SESSION['admin_id']);
    }
    $kSt->execute();
    if (!($kSt->get_result()->fetch_assoc())) {
        flash_set('err', '无权限或密钥不存在'); header('Location: ' . admin_url('config')); exit;
    }

    // JSON 来源：上传文件（表单 file 字段）。限制大小，防恶意上传。
    if (empty($_FILES['json_file']) || !is_uploaded_file($_FILES['json_file']['tmp_name'] ?? '')) {
        flash_set('err', '请选择要导入的 JSON 文件'); header('Location: ' . admin_url('config')); exit;
    }
    if ($_FILES['json_file']['size'] > 4 * 1024 * 1024) {
        flash_set('err', '文件过大（上限 4MB）'); header('Location: ' . admin_url('config')); exit;
    }
    $raw = file_get_contents($_FILES['json_file']['tmp_name']);
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['playlists']) || !is_array($data['playlists'])) {
        flash_set('err', 'JSON 格式不对（缺少 playlists 数组）'); header('Location: ' . admin_url('config')); exit;
    }

    // 已有歌单名集合（用于同名跳过）
    $existing = [];
    $er = $db->query("SELECT name FROM mapi_playlists WHERE key_id=$kid");
    if ($er) while ($e = $er->fetch_assoc()) $existing[trim($e['name'])] = true;

    $maxOrder = $db->query("SELECT IFNULL(MAX(sort_order),0) FROM mapi_playlists WHERE key_id=$kid");
    $nextOrder = $maxOrder ? (int)$maxOrder->fetch_row()[0] : 0;

    $created = 0; $skipped = 0; $songCount = 0;
    $pSt = $db->prepare("INSERT INTO mapi_playlists (key_id, name, type, remote_id, server, cover_mode, sort_order) VALUES (?,?,?,?,?,?,?)");
    $sSt = $db->prepare("INSERT INTO mapi_songs (key_id, playlist_id, song_id, name, artist, server, sort_order, missing) VALUES (?,?,?,?,?,?,?,?)");

    foreach ($data['playlists'] as $pl) {
        if (!is_array($pl)) continue;
        $name = trim((string)($pl['name'] ?? ''));
        if ($name === '') continue;
        if (isset($existing[trim($name)])) { $skipped++; continue; }   // 同名跳过

        $type      = in_array($pl['type'] ?? '', ['custom','remote']) ? $pl['type'] : 'custom';
        $remoteId  = trim((string)($pl['remote_id'] ?? ''));
        $server    = in_array($pl['server'] ?? '', ['tencent','netease']) ? $pl['server'] : 'netease';
        $coverMode = in_array($pl['cover_mode'] ?? '', ['auto','url','first_song','last_song']) ? $pl['cover_mode'] : 'auto';
        $nextOrder += 100;
        $pSt->bind_param('isssssi', $kid, $name, $type, $remoteId, $server, $coverMode, $nextOrder);
        if (!$pSt->execute()) continue;
        $newPid = (int)$pSt->insert_id;
        $created++; $existing[trim($name)] = true;

        $order = 0;
        foreach ((array)($pl['songs'] ?? []) as $s) {
            if (!is_array($s)) continue;
            $sid  = trim((string)($s['song_id'] ?? ''));
            $sname= trim((string)($s['name'] ?? ''));
            if ($sid === '' || $sname === '') continue;
            $artist = trim((string)($s['artist'] ?? ''));
            $sserver= in_array($s['server'] ?? '', ['tencent','netease']) ? $s['server'] : $server;
            $order += 100;
            $missing = !empty($s['missing']) ? 1 : 0;
            $sSt->bind_param('iissssii', $kid, $newPid, $sid, $sname, $artist, $sserver, $order, $missing);
            if ($sSt->execute()) $songCount++;
        }
    }

    if ($created > 0 && function_exists('cfg_cache_invalidate')) cfg_cache_invalidate();
    flash_set('msg', "导入完成：新建 {$created} 个歌单 · {$songCount} 首歌" . ($skipped ? " · 跳过同名 {$skipped} 个" : ''));
    header('Location: ' . admin_url('config')); exit;
}