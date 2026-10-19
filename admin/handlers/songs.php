<?php

// $action_key 由路由层传入，值为实际的action名

if ($action_key === 'song-add') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_require();
    $kid = (int)($_POST['key_id'] ?? 0);
    $plId = (int)($_POST['playlist_id'] ?? 0);
    $songId = trim($_POST['song_id'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $artist = trim($_POST['artist'] ?? '');
    $server = in_array($_POST['server'] ?? '', ['tencent', 'netease']) ? $_POST['server'] : 'netease';
    if (!$plId || !$songId || !$name) { echo json_encode(['ok' => false, 'msg' => '参数不完整']); exit; }
    $ownerCheck = ($_SESSION['admin_is_admin'] ?? 99) <= 1
        ? $db->query("SELECT id, key_id FROM mapi_playlists WHERE id=$plId")
        : $db->query("SELECT id, key_id FROM mapi_playlists WHERE id=$plId AND key_id IN (SELECT id FROM mapi_keys WHERE user_id=" . (int)$_SESSION['admin_id'] . ")");
    if (!$ownerCheck || !($plRow = $ownerCheck->fetch_assoc())) { echo json_encode(['ok' => false, 'msg' => '无权限']); exit; }
    $plKeyId = (int)$plRow['key_id'];
    // 释放会话锁：下面要下这首歌的封面（网络请求，最长 8 秒），不能让它把同一会话的
    // 其它请求堵在 session_start() 上（切页/刷新会一直转圈）。见 admin/lib/helpers.php。
    // 放这里安全：csrf_require() 与上面的权限判断已读完 $_SESSION，此后本请求不再写它。
    admin_release_session_lock();
    $dup = $db->query("SELECT id FROM mapi_songs WHERE playlist_id=$plId AND song_id='" . $db->real_escape_string($songId) . "' AND server='" . $db->real_escape_string($server) . "'");
    if ($dup && $dup->fetch_assoc()) { echo json_encode(['ok' => false, 'msg' => '歌曲已存在']); exit; }
    $maxOrder = $db->query("SELECT IFNULL(MAX(sort_order),0) FROM mapi_songs WHERE playlist_id=$plId");
    $nextOrder = $maxOrder ? (int)$maxOrder->fetch_row()[0] + 1 : 1;
    $stmt = $db->prepare("INSERT INTO mapi_songs (key_id, playlist_id, song_id, name, artist, server, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('iissssi', $plKeyId, $plId, $songId, $name, $artist, $server, $nextOrder);
    $ok = $stmt->execute();
    if ($ok) {
        // 新增歌曲属于“重大改动”：这首歌的封面写库；如果它是歌单第一首/最后一首，歌单封面也自动更新
        cover_song_refresh($db, $cfg, $server, $songId, $plKeyId);
        cover_pl_refresh($db, $cfg, $plId, false);
    }
    echo json_encode(['ok' => $ok, 'msg' => $ok ? '已添加' : '添加失败']);
    exit;
}

// 歌曲排序：单首移动或整份顺序
if ($action_key === 'song-reorder') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    $_POST['_csrf'] = $input['_csrf'] ?? '';
    csrf_require();
    $plId  = (int)($input['playlist_id'] ?? 0);
    $rowId = (int)($input['row_id'] ?? 0);
    $dir   = (string)($input['dir'] ?? '');
    $order = $input['order'] ?? null;
    if (!$plId) { echo json_encode(['ok' => false, 'msg' => '参数不完整']); exit; }

    $ownerCheck = ($_SESSION['admin_is_admin'] ?? 99) <= 1
        ? $db->query("SELECT id FROM mapi_playlists WHERE id=$plId")
        : $db->query("SELECT id FROM mapi_playlists WHERE id=$plId AND key_id IN (SELECT id FROM mapi_keys WHERE user_id=" . (int)$_SESSION['admin_id'] . ")");
    if (!$ownerCheck || !$ownerCheck->fetch_assoc()) { echo json_encode(['ok' => false, 'msg' => '无权限']); exit; }

    $rows = [];
    $r = $db->query("SELECT id FROM mapi_songs WHERE playlist_id=$plId ORDER BY sort_order ASC, id ASC");
    if ($r) while ($x = $r->fetch_assoc()) $rows[] = (int)$x['id'];
    if (!$rows) { echo json_encode(['ok' => false, 'msg' => '歌单为空']); exit; }

    if (is_array($order) && $order) {
        $want = [];
        foreach ($order as $id) {
            $id = (int)$id;
            if (in_array($id, $rows, true) && !in_array($id, $want, true)) $want[] = $id;
        }
        // 以服务端清单为准补全
        foreach ($rows as $id) { if (!in_array($id, $want, true)) $want[] = $id; }
    } else {
        $pos = array_search($rowId, $rows, true);
        if ($pos === false) { echo json_encode(['ok' => false, 'msg' => '歌曲不在该歌单']); exit; }
        $want = $rows;
        if ($dir === 'top') {
            if ($pos === 0) { echo json_encode(['ok' => true, 'msg' => '已在最前', 'order' => $rows, 'moved' => 0]); exit; }
            array_splice($want, $pos, 1);
            array_unshift($want, $rowId);
        } elseif ($dir === 'up') {
            if ($pos === 0) { echo json_encode(['ok' => true, 'msg' => '已在最前', 'order' => $rows, 'moved' => 0]); exit; }
            $want[$pos] = $want[$pos - 1]; $want[$pos - 1] = $rowId;
        } elseif ($dir === 'down') {
            if ($pos >= count($want) - 1) { echo json_encode(['ok' => true, 'msg' => '已在最后', 'order' => $rows, 'moved' => 0]); exit; }
            $want[$pos] = $want[$pos + 1]; $want[$pos + 1] = $rowId;
        } else {
            echo json_encode(['ok' => false, 'msg' => '参数不完整']); exit;
        }
    }

    // 开启事务
    $db->query('START TRANSACTION');
    try {
        $upd = $db->prepare("UPDATE mapi_songs SET sort_order=? WHERE id=? AND playlist_id=?");
        $moved = 0;
        foreach ($want as $i => $id) {
            $newOrder = ($i + 1) * 100;
            $upd->bind_param('iii', $newOrder, $id, $plId);
            $upd->execute();
            if ($upd->affected_rows > 0) $moved++;
        }
        $db->query('COMMIT');
    } catch (Throwable $e) {
        $db->query('ROLLBACK');
        error_log('MAPI: 歌曲排序失败: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => '保存失败']); exit;
    }
    echo json_encode(['ok' => true, 'msg' => '顺序已保存', 'order' => $want, 'moved' => $moved]);
    exit;
}

if ($action_key === 'song-remove') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_require();
    $sid = (int)($_POST['song_row_id'] ?? 0);
    if (!$sid) { echo json_encode(['ok' => false, 'msg' => '参数不完整']); exit; }
    if (($_SESSION['admin_is_admin'] ?? 99) <= 1) {
        $songInfo = $db->query("SELECT song_id, server, playlist_id FROM mapi_songs WHERE id=$sid");
        $stmt = $db->prepare("DELETE FROM mapi_songs WHERE id=?");
        $stmt->bind_param('i', $sid);
    } else {
        $songInfo = $db->query("SELECT song_id, server, playlist_id FROM mapi_songs WHERE id=$sid AND playlist_id IN (SELECT id FROM mapi_playlists WHERE key_id IN (SELECT id FROM mapi_keys WHERE user_id=" . (int)$_SESSION['admin_id'] . "))");
        $stmt = $db->prepare("DELETE FROM mapi_songs WHERE id=? AND playlist_id IN (SELECT id FROM mapi_playlists WHERE key_id IN (SELECT id FROM mapi_keys WHERE user_id=?))");
        $stmt->bind_param('ii', $sid, $_SESSION['admin_id']);
    }
    // 释放会话锁：下面要清封面 / 重算歌单封面（可能走网络），别堵住同一会话的其它请求。
    // $_SESSION 在上面的权限判断里已经读完，此后本请求不再写它。
    admin_release_session_lock();
    // 删除前先记下这首歌的信息（删完就查不到了）
    $si = ($songInfo && ($row = $songInfo->fetch_assoc())) ? $row : null;
    $ok = $stmt->execute();
    if ($ok && $si) {
        $refServer = $si['server'] ?: 'netease';
        $refSongId = (string)($si['song_id'] ?? '');
        // 删歌属于“重大改动”：该歌若已无其它歌单引用就清掉封面；歌单封面按规则自动更新
        if ($refSongId !== '') {
            $songEsc = $db->real_escape_string($refSongId);
            $srvEsc = $db->real_escape_string($refServer);
            $still = $db->query("SELECT COUNT(*) n FROM mapi_songs WHERE song_id='$songEsc' AND server='$srvEsc'");
            $left = ($still && $n = $still->fetch_assoc()) ? (int)$n['n'] : 0;
            if ($left === 0) cover_song_unset($db, $refServer, $refSongId);
        }
        if (!empty($si['playlist_id'])) cover_pl_refresh($db, $cfg, (int)$si['playlist_id'], false);
    }
    echo json_encode(['ok' => $ok, 'msg' => $ok ? '已删除' : '删除失败']);
    exit;
}

