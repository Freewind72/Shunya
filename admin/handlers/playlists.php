<?php

header('Content-Type: application/json; charset=utf-8');

if ($action_key === 'playlist-create') {
    $input = json_decode(file_get_contents('php://input'), true);
    $_POST['_csrf'] = $input['_csrf'] ?? '';
    csrf_require();
    $kid = (int)($input['key_id'] ?? 0);
    $plName = trim($input['name'] ?? '');
    $plType = in_array($input['type'] ?? '', ['custom', 'remote']) ? $input['type'] : 'custom';
    $remoteId = trim($input['remote_id'] ?? '');
    $server = in_array($input['server'] ?? '', ['tencent', 'netease']) ? $input['server'] : 'netease';
    $coverUrl = trim($input['cover_url'] ?? '');
    $coverMode = in_array($input['cover_mode'] ?? '', ['auto', 'url', 'first_song', 'last_song']) ? $input['cover_mode'] : 'auto';
    if (!$kid || !$plName) { echo json_encode(['ok' => false, 'msg' => '参数不完整']); exit; }
    $ownerCheck = ($_SESSION['admin_is_admin'] ?? 99) <= 1
        ? $db->query("SELECT id FROM mapi_keys WHERE id=$kid")
        : $db->query("SELECT id FROM mapi_keys WHERE id=$kid AND user_id=" . (int)$_SESSION['admin_id']);
    if (!$ownerCheck || !$ownerCheck->fetch_assoc()) { echo json_encode(['ok' => false, 'msg' => '无权限']); exit; }
    $maxOrder = $db->query("SELECT IFNULL(MAX(sort_order),0) FROM mapi_playlists WHERE key_id=$kid");
    $nextOrder = $maxOrder ? (int)$maxOrder->fetch_row()[0] + 1 : 1;
    $stmt = $db->prepare("INSERT INTO mapi_playlists (key_id, name, type, remote_id, server, cover_url, cover_mode, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('issssssi', $kid, $plName, $plType, $remoteId, $server, $coverUrl, $coverMode, $nextOrder);
    $ok = $stmt->execute();
    $insertId = $ok ? $stmt->insert_id : 0;
    if ($ok && $plType === 'remote' && $coverMode === 'auto' && !$coverUrl && $remoteId) {
        $apiBase = $cfg['api']['base_url'] ?? '';
        $rServer = $cfg['api']['param_server'] ?? 'server';
        $rType = $cfg['api']['param_type'] ?? 'type';
        $rId = $cfg['api']['param_id'] ?? 'id';
        $pId = $cfg['api']['param_id'] ?? 'id';
        if ($apiBase) {
            // 新建歌单：按封面规则自动取一次封面并写库（auto/first_song 取第一首，last_song 取最后一首）
            cover_pl_refresh($db, $cfg, $insertId, true);
            // 顺带把这批歌曲的封面也存进库（最多 30 首，失败不影响主流程）
            $fetchUrl = $apiBase . '?' . http_build_query([$rServer => $server, $rType => 'playlist', $rId => $remoteId]);
            $raw = @file_get_contents($fetchUrl, false, stream_context_create(['http' => ['timeout' => 10], 'ssl' => ['verify_peer' => false]]));
            $plData = $raw ? json_decode($raw, true) : null;
            foreach (is_array($plData) ? $plData : [] as $idx => $s) {
                if ($idx >= 30) break;
                $sId = '';
                if (!empty($s[$cfg['api']['field_url'] ?? 'url']) && preg_match('/[?&]' . preg_quote($pId, '/') . '=([^&]+)/', $s[$cfg['api']['field_url'] ?? 'url'], $m)) $sId = $m[1];
                if (!$sId && !empty($s[$cfg['api']['field_lrc'] ?? 'lrc']) && preg_match('/[?&]' . preg_quote($pId, '/') . '=([^&]+)/', $s[$cfg['api']['field_lrc'] ?? 'lrc'], $m)) $sId = $m[1];
                if ($sId) cover_song_refresh($db, $cfg, $server, $sId, $kid);
            }
        }
    }
    if ($ok && $plType === 'remote' && $remoteId) {
        // 远程歌单落库快照
        try {
            $snapRow = ['id' => $insertId, 'key_id' => $kid, 'server' => $server, 'remote_id' => $remoteId];
            $snap = playlist_snapshot_songs($db, $cfg, $snapRow);
            if (($snap['total'] ?? 0) > 0 && function_exists('cover_pl_refresh')) {
                cover_pl_refresh($db, $cfg, $insertId, true);       // 用刚落库的快照重算封面
            }
        } catch (Throwable $e) {
            error_log('MAPI: 远程歌单快照失败: ' . $e->getMessage());
        }
    }

    echo json_encode(['ok' => $ok, 'msg' => $ok ? '已创建' : '创建失败', 'id' => $insertId]);
    exit;
}

if ($action_key === 'playlist-delete') {
    $input = json_decode(file_get_contents('php://input'), true);
    $_POST['_csrf'] = $input['_csrf'] ?? '';
    csrf_require();
    $pid = (int)($input['id'] ?? 0);
    if (!$pid) { echo json_encode(['ok' => false, 'msg' => '参数不完整']); exit; }
    // 先记下这个歌单的歌曲（删完之后再判断是否还有别的歌单引用它们的封面）
    $delRefs = [];
    $dr = $db->query("SELECT DISTINCT song_id, server FROM mapi_songs WHERE playlist_id=$pid");
    if ($dr) while ($d = $dr->fetch_assoc()) $delRefs[] = [($d['server'] ?: 'netease'), (string)$d['song_id']];
    if (($_SESSION['admin_is_admin'] ?? 99) <= 1) {
        $db->query("DELETE FROM mapi_songs WHERE playlist_id=$pid");
        $stmt = $db->prepare("DELETE FROM mapi_playlists WHERE id=?");
        $stmt->bind_param('i', $pid);
    } else {
        $db->query("DELETE FROM mapi_songs WHERE playlist_id=$pid AND playlist_id IN (SELECT id FROM mapi_playlists WHERE key_id IN (SELECT id FROM mapi_keys WHERE user_id=" . (int)$_SESSION['admin_id'] . "))");
        $stmt = $db->prepare("DELETE FROM mapi_playlists WHERE id=? AND key_id IN (SELECT id FROM mapi_keys WHERE user_id=?)");
        $stmt->bind_param('ii', $pid, $_SESSION['admin_id']);
    }
    $ok = $stmt->execute();
    if ($ok && $delRefs) {
        // 歌曲封面若无其它歌单引用则一并清理（歌单封面随 mapi_playlists 行一起消失）
        foreach ($delRefs as $ref) {
            list($refServer, $refSongId) = $ref;
            $songEsc = $db->real_escape_string($refSongId);
            $srvEsc = $db->real_escape_string($refServer);
            $still = $db->query("SELECT COUNT(*) n FROM mapi_songs WHERE song_id='$songEsc' AND server='$srvEsc'");
            if ($still && $n = $still->fetch_assoc()) {
                if ((int)$n['n'] === 0) cover_song_unset($db, $refServer, $refSongId);
            }
        }
    }
    echo json_encode(['ok' => $ok, 'msg' => $ok ? '已删除' : '删除失败']);
    exit;
}

if ($action_key === 'playlist-update') {
    $input = json_decode(file_get_contents('php://input'), true);
    $_POST['_csrf'] = $input['_csrf'] ?? '';
    csrf_require();
    $pid = (int)($input['id'] ?? 0);
    $plName = trim($input['name'] ?? '');
    $remoteId = trim($input['remote_id'] ?? '');
    $server = in_array($input['server'] ?? '', ['tencent', 'netease']) ? $input['server'] : 'netease';
    $coverUrl = trim($input['cover_url'] ?? '');
    $coverMode = in_array($input['cover_mode'] ?? '', ['auto', 'url', 'first_song', 'last_song']) ? $input['cover_mode'] : 'auto';
    if (!$pid || !$plName) { echo json_encode(['ok' => false, 'msg' => '参数不完整']); exit; }
    if (($_SESSION['admin_is_admin'] ?? 99) <= 1) {
        $stmt = $db->prepare("UPDATE mapi_playlists SET name=?, remote_id=?, server=?, cover_url=?, cover_mode=? WHERE id=?");
        $stmt->bind_param('sssssi', $plName, $remoteId, $server, $coverUrl, $coverMode, $pid);
    } else {
        $stmt = $db->prepare("UPDATE mapi_playlists SET name=?, remote_id=?, server=?, cover_url=?, cover_mode=? WHERE id=? AND key_id IN (SELECT id FROM mapi_keys WHERE user_id=?)");
        $uid = (int)$_SESSION['admin_id'];
        $stmt->bind_param('ssssiii', $plName, $remoteId, $server, $coverUrl, $coverMode, $pid, $uid);
    }
    $ok = $stmt->execute();
    if ($ok) {
        // 保存歌单属于"重大改动": 按封面规则重新取一次封面并写库
        cover_pl_refresh($db, $cfg, $pid, true);
    }
    echo json_encode(['ok' => $ok, 'msg' => $ok ? '已保存' : '保存失败']);
    exit;
}

if ($action_key === 'playlist-update-cover') {
    $input = json_decode(file_get_contents('php://input'), true);
    $_POST['_csrf'] = $input['_csrf'] ?? '';
    csrf_require();
    $pid = (int)($input['id'] ?? 0);
    $coverUrl = trim($input['cover_url'] ?? '');
    $coverMode = in_array($input['cover_mode'] ?? '', ['auto', 'url', 'first_song', 'last_song']) ? $input['cover_mode'] : 'auto';
    if (!$pid) { echo json_encode(['ok' => false, 'msg' => '参数不完整']); exit; }
    if (($_SESSION['admin_is_admin'] ?? 99) <= 1) {
        $stmt = $db->prepare("UPDATE mapi_playlists SET cover_url=?, cover_mode=? WHERE id=?");
        $stmt->bind_param('ssi', $coverUrl, $coverMode, $pid);
    } else {
        $stmt = $db->prepare("UPDATE mapi_playlists SET cover_url=?, cover_mode=? WHERE id=? AND key_id IN (SELECT id FROM mapi_keys WHERE user_id=?)");
        $stmt->bind_param('ssii', $coverUrl, $coverMode, $pid, $_SESSION['admin_id']);
    }
    $ok = $stmt->execute();
    if ($ok) {
        // 手动改封面 / 改封面规则：立即按新规则刷新并写库
        cover_pl_refresh($db, $cfg, $pid, true);
    }
    echo json_encode(['ok' => $ok, 'msg' => $ok ? '已更新' : '更新失败']);
    exit;
}

if ($action_key === 'playlist-fetch-cover') {
    $input = json_decode(file_get_contents('php://input'), true);
    $_POST['_csrf'] = $input['_csrf'] ?? '';
    csrf_require();
    $pid = (int)($input['id'] ?? 0);
    if (!$pid) { echo json_encode(['ok' => false, 'msg' => '参数不完整']); exit; }
    $ownerCheck = ($_SESSION['admin_is_admin'] ?? 99) <= 1
        ? $db->query("SELECT id FROM mapi_playlists WHERE id=$pid")
        : $db->query("SELECT id FROM mapi_playlists WHERE id=$pid AND key_id IN (SELECT id FROM mapi_keys WHERE user_id=" . (int)$_SESSION['admin_id'] . ")");
    if (!$ownerCheck || !$ownerCheck->fetch_assoc()) { echo json_encode(['ok' => false, 'msg' => '无权限']); exit; }
    // 强制刷新（重新按规则抓取并写库）
    $b64 = cover_pl_refresh($db, $cfg, $pid, true);
    $pl = cover_pl_row($db, $pid);
    if ($b64 !== '' || !empty($pl['cover_url'])) {
        echo json_encode(['ok' => true, 'cover_url' => $pl['cover_url'] ?? '', 'cover_b64' => $b64, 'msg' => '已获取封面']);
    } else {
        echo json_encode(['ok' => false, 'msg' => '未获取到封面']);
    }
    exit;
}

if ($action_key === 'playlist-reorder') {
    $input = json_decode(file_get_contents('php://input'), true);
    $_POST['_csrf'] = $input['_csrf'] ?? '';
    csrf_require();
    $kid = (int)($input['key_id'] ?? 0);
    $order = $input['order'] ?? [];
    if (!$kid || !is_array($order) || !$order) { echo json_encode(['ok' => false, 'msg' => '参数不完整']); exit; }
    // 权限：该密钥必须属于当前用户（超管不限）
    $ownerCheck = ($_SESSION['admin_is_admin'] ?? 99) <= 1
        ? $db->query("SELECT id FROM mapi_keys WHERE id=$kid")
        : $db->query("SELECT id FROM mapi_keys WHERE id=$kid AND user_id=" . (int)$_SESSION['admin_id']);
    if (!$ownerCheck || !$ownerCheck->fetch_assoc()) { echo json_encode(['ok' => false, 'msg' => '无权限']); exit; }
    // 合并前端顺序与服务端清单
    $rows = [];
    $r = $db->query("SELECT id FROM mapi_playlists WHERE key_id=$kid ORDER BY sort_order ASC, id ASC");
    if ($r) while ($x = $r->fetch_assoc()) $rows[] = (int)$x['id'];
    $want = [];
    foreach ($order as $pid) {
        $pid = (int)$pid;
        if ($pid && in_array($pid, $rows, true) && !in_array($pid, $want, true)) $want[] = $pid;
    }
    foreach ($rows as $pid) { if (!in_array($pid, $want, true)) $want[] = $pid; }

    // 开启事务
    $db->query('START TRANSACTION');
    try {
        $upd = $db->prepare("UPDATE mapi_playlists SET sort_order=? WHERE id=? AND key_id=?");
        $moved = 0;
        foreach ($want as $i => $pid) {
            $newOrder = ($i + 1) * 100;
            $upd->bind_param('iii', $newOrder, $pid, $kid);
            $upd->execute();
            if ($upd->affected_rows > 0) $moved++;
        }
        $db->query('COMMIT');
    } catch (Throwable $e) {
        $db->query('ROLLBACK');
        error_log('MAPI: 歌单排序失败: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => '保存失败']); exit;
    }
    echo json_encode(['ok' => true, 'msg' => '顺序已保存', 'order' => $want, 'moved' => $moved]);
    exit;
}

// 同步远程歌单快照
if ($action_key === 'playlist-sync') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    $_POST['_csrf'] = $input['_csrf'] ?? '';
    csrf_require();
    $pid = (int)($input['id'] ?? 0);
    if (!$pid) { echo json_encode(['ok' => false, 'msg' => '参数不完整']); exit; }
    $ownerCheck = ($_SESSION['admin_is_admin'] ?? 99) <= 1
        ? $db->query("SELECT * FROM mapi_playlists WHERE id=$pid")
        : $db->query("SELECT * FROM mapi_playlists WHERE id=$pid AND key_id IN (SELECT id FROM mapi_keys WHERE user_id=" . (int)$_SESSION['admin_id'] . ")");
    $pl = $ownerCheck ? $ownerCheck->fetch_assoc() : null;
    if (!$pl) { echo json_encode(['ok' => false, 'msg' => '无权限']); exit; }
    if (($pl['type'] ?? '') !== 'remote' || empty($pl['remote_id'])) { echo json_encode(['ok' => false, 'msg' => '不是远程歌单']); exit; }
    $snap = playlist_snapshot_songs($db, $cfg, $pl);
    if (($snap['total'] ?? 0) === 0) { echo json_encode(['ok' => false, 'msg' => '上游没取到歌曲，稍后再试']); exit; }
    if (function_exists('cover_pl_refresh')) cover_pl_refresh($db, $cfg, $pid, true);
    echo json_encode([
        'ok'  => true,
        'msg' => "同步完成：新增 {$snap['added']} · 更新 {$snap['updated']} · 标记下架 {$snap['missing']}",
        'snap'=> $snap,
    ]);
    exit;
}