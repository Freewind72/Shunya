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

    // ⚠ 远程歌单**不再在本请求里**抓封面/抓歌单快照。
    //
    // 原来这里会同步做完：① 按封面规则取封面；② 逐首取最多 30 首歌曲封面
    // （每首一个 10s 超时的 file_get_contents）；③ 拉上游歌单快照落库。
    // 这三步串起来能到几十秒，而前端要等整个响应才拿到"已创建" —— 用户看到的就是"页面卡住了"。
    //
    // 现在改为**只落库就立刻返回**，歌曲内容交给「提交之后的在线同步」：
    //   · 前端拿到 id 后，新建卡片会走既有的骨架屏 + 同步链路
    //     （新行 synced_at 为 NULL ⇒ playlist_sync_allowed() 放行，不进 10 分钟冷却）
    //   · 真正抓歌与重算封面由 playlist-sync 那条路做（它成功后会自己 cover_pl_refresh）
    // 好处：响应时间从"几十秒"降到"一次 INSERT"，且失败重试就是再点一次同步。
    //
    // 代价（已知并接受）：原来顺带预热的「最多 30 首歌曲封面」不再在创建时做，
    // 改由后续同步/封面预热流程覆盖。

    echo json_encode([
        'ok'     => (bool)$ok,
        'msg'    => $ok ? '已创建，正在同步…' : '创建失败',
        'id'     => $insertId,
        'key_id' => $kid,
        'type'   => $plType,
    ], JSON_UNESCAPED_UNICODE);
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

        /* ── 编辑保存触发一次**强制同步**（2026-10-20，产品要求）──────────
           场景：用户在平台上删了歌 → 本地还是旧清单 → 用户打开编辑弹窗改个名保存。
           这时正好是一次"让本地追平上游"的机会：顺手拉一次上游清单，
           远程缺失歌按「标 missing → 下轮真删」的两轮机制走起来，
           用户不用再专门去点同步按钮。
           ⚠ 只对**远程**歌单做（有 remote_id 才有上游可拉）；自建歌单没有上游清单，
             它的"同步"是逐首回源刷详情，这里不适合拉（预算最长 24s，会拖慢保存响应）。 */
        $plRow = null;
        $pr = $db->query("SELECT id, key_id, type, remote_id, server FROM mapi_playlists WHERE id=$pid");
        if ($pr) $plRow = $pr->fetch_assoc();
        if ($plRow && ($plRow['type'] ?? '') === 'remote' && trim((string)($plRow['remote_id'] ?? '')) !== ''
            && function_exists('playlist_snapshot_songs')) {
            $snap = playlist_snapshot_songs($db, $cfg, $plRow, true);   // force=true：编辑保存必须立刻追平，不吃冷却
            // 失败静默：保存本身已成功，同步失败不该把整次编辑报成失败。
            // 前端提示里带上本次同步结果，让用户知道删除/新增已经追平。
            if ($ok && !empty($snap)) {
                $msg = '已保存';
                if (($snap['added'] ?? 0) > 0)   $msg .= ' · 新增 ' . (int)$snap['added'];
                if (($snap['removed'] ?? 0) > 0) $msg .= ' · 删除 ' . (int)$snap['removed'];
                if (($snap['missing'] ?? 0) > 0) $msg .= ' · 下架 ' . (int)$snap['missing'];
                echo json_encode(['ok' => true, 'msg' => $msg, 'snap' => $snap], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
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

// 待同步的远程歌单队列（按 sort_order 顺序）。
// 前端据此：① 只给"真的要同步"的卡片上骨架屏；② 按这个顺序逐个同步。
// 冷却已过期的（或从未同步的）才算"待同步"，所以冷却期内进页面不会闪骨架屏。
if ($action_key === 'playlist-sync-queue') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    $_POST['_csrf'] = $input['_csrf'] ?? '';
    csrf_require();
    $isSuper = (($_SESSION['admin_is_admin'] ?? 99) <= 1);
    $uid = (int)($_SESSION['admin_id'] ?? 0);
    $sql = "SELECT p.id, p.name, p.synced_at FROM mapi_playlists p
            LEFT JOIN mapi_keys k ON k.id = p.key_id
            WHERE p.type='remote' AND p.remote_id<>''";
    if (!$isSuper) $sql .= " AND k.user_id=$uid";
    $sql .= " ORDER BY p.sort_order ASC, p.id ASC";
    $queue = [];
    $r = $db->query($sql);
    while ($r && $row = $r->fetch_assoc()) {
        if (!playlist_sync_allowed($db, $row)) continue;   // 冷却中：不上屏、不同步
        $queue[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
    }
    echo json_encode(['ok' => true, 'queue' => $queue, 'cooldown' => PLAYLIST_SYNC_COOLDOWN]);
    exit;
}

// 歌单同步：**远程与自建共用这一个 action**（前端不必区分类型）。
//   远程歌单（type=remote 且有 remote_id）→ 拉上游歌单清单做三方合并
//   自建歌单（其余）                      → 逐首回源刷新歌名/歌手/下架状态并补封面
// 两者共用同一道 10 分钟冷却闸（都在各自函数内部判定），返回形状也一致。
if ($action_key === 'playlist-sync') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    $_POST['_csrf'] = $input['_csrf'] ?? '';
    csrf_require();
    $pid = (int)($input['id'] ?? 0);
    if (!$pid) { echo json_encode(['ok' => false, 'msg' => '参数不完整']); exit; }
    // auto=1：页面自动同步（走 10 分钟冷却，冷却中直接返回 skipped）。
    // 不带 auto：用户手动点「同步」＝明确意图，强制刷新，不受冷却限制。
    $auto = !empty($input['auto']);
    $ownerCheck = ($_SESSION['admin_is_admin'] ?? 99) <= 1
        ? $db->query("SELECT * FROM mapi_playlists WHERE id=$pid")
        : $db->query("SELECT * FROM mapi_playlists WHERE id=$pid AND key_id IN (SELECT id FROM mapi_keys WHERE user_id=" . (int)$_SESSION['admin_id'] . ")");
    $pl = $ownerCheck ? $ownerCheck->fetch_assoc() : null;
    if (!$pl) { echo json_encode(['ok' => false, 'msg' => '无权限']); exit; }

    /* ⚠⚠ 释放会话锁 —— 这就是"长时间不操作后切页/刷新卡很久"的**根治点**。
       同步要打好几秒网络（实测 7 个远程歌单合计 ≈2.7 秒；最坏单歌单 20 秒）。
       PHP 的文件会话是**整请求排他**的：`session_start()` 拿到的锁要到脚本结束才释放，
       于是这期间同一浏览器的**任何**其它请求（切页、刷新）都会堵在 `session_start()` 上排队。
       必须在 csrf_require() 与上面的权限判断之后调用（它们要读 $_SESSION），
       且此后本请求不再写 $_SESSION。详见 admin/handlers/covers.php 顶部对同一问题的说明。 */
    admin_release_session_lock();

    $isRemote = (($pl['type'] ?? '') === 'remote' && !empty($pl['remote_id']));
    $snap = $isRemote
        ? playlist_snapshot_songs($db, $cfg, $pl, !$auto)
        : playlist_refresh_custom_songs($db, $cfg, $pl, !$auto);

    if (!empty($snap['skipped'])) {
        // 冷却中：不是错误，如实告诉前端"跳过"，前端照常解除骨架屏
        echo json_encode(['ok' => true, 'skipped' => true, 'msg' => '冷却中（10 分钟内已同步）', 'snap' => $snap]);
        exit;
    }

    if ($isRemote) {
        if (($snap['total'] ?? 0) === 0) { echo json_encode(['ok' => false, 'msg' => '上游没取到歌曲，稍后再试']); exit; }
        $msg = "同步完成：新增 {$snap['added']} · 更新 {$snap['updated']} · 下架 {$snap['missing']}"
             . (!empty($snap['removed']) ? " · 删除 {$snap['removed']}" : '');
    } else {
        // 自建歌单不从上游"新增"歌曲（歌是人手工挑的），只刷新已有那些。
        // 空歌单不算失败，但也别谎报成功。
        if (($snap['total'] ?? 0) === 0) {
            echo json_encode(['ok' => true, 'msg' => '这个歌单还没有歌', 'snap' => $snap], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $msg = "同步完成：刷新 {$snap['done']} 首";
        if (!empty($snap['updated']))   $msg .= " · 更新 {$snap['updated']}";
        if (!empty($snap['missing']))   $msg .= " · 标记下架 {$snap['missing']}";
        if (!empty($snap['covers']))    $msg .= " · 补封面 {$snap['covers']}";
        // 单次有墙钟预算（见 CUSTOM_SYNC_BUDGET_SEC），没刷完就告诉用户再点一次会接着刷
        if (!empty($snap['remaining'])) $msg .= " · 还剩 {$snap['remaining']} 首（再点一次接着刷）";
    }

    /* 歌单封面刷新策略 —— 别再无条件 force 了。
       cover_pl_refresh() 的缓存早退条件写的是 `if (!$force && !empty($cover_data))`，
       所以传 force=true 等于**每次都把封面图重新下一遍再写库**（实测 7 个歌单白下 ≈1.4 秒）。
       而"没有增删歌曲"时歌单封面不可能变，于是：
         · 手动点同步（!$auto）：用户明确要求看到最新 → 强制重算
         · 自动同步：只有歌曲集合真的变了（有新增或有下架），或本来就没有封面，才重算 */
    $setChanged = (($snap['added'] ?? 0) > 0) || (($snap['missing'] ?? 0) > 0);
    if ((!$auto || $setChanged || empty($pl['cover_data'])) && function_exists('cover_pl_refresh')) {
        cover_pl_refresh($db, $cfg, $pid, true);
    }

    echo json_encode(['ok' => true, 'msg' => $msg, 'snap' => $snap], JSON_UNESCAPED_UNICODE);
    exit;
}