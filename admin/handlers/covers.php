<?php

// $action_key 由路由层传入，值为实际的 action 名。
//
// 封面本地化（内容寻址 → S3）的四个入口，全部**仅管理员**可用：
//   cover-local-toggle → 开关（mapi_config.cover_local），手动开启/关闭
//   cover-cache-status → 读进度：每个歌单「已本地化/总数」+ 全局旧数据迁移进度
//   cover-cache-run    → 把一个歌单的一批封面存进 S3（默认 10 首，前端轮询续跑）
//   cover-migrate-run  → 把数据库里历史遗留的 base64 封面搬进 S3 并清空该列
//
// 为什么必须分批：一首歌一张图 = 一次上游请求，100 首一次跑完必然顶到超时
// （网关是 set_time_limit(30)）。每次调用都是「查还缺哪些 → 处理一批 → 回报进度」，
// 所以中途关页面/刷新，再点一次就能接着跑（幂等、可续）。

header('Content-Type: application/json; charset=utf-8');

// 只有管理员能动封面缓存：它既写存储、又改全站可见的图片
if (($_SESSION['admin_is_admin'] ?? 99) > 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => '只有管理员可以操作封面缓存']);
    exit;
}

// 前端一律发 JSON；没有 JSON body 时回落到 $_POST（表单提交 / 脚本调用也能用）
$__coverInput = function (): array {
    $i = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($i)) return $i;
    return is_array($_POST) ? $_POST : [];
};

/**
 * 长任务开始前释放会话锁。
 *
 * 为什么必须做：PHP 的文件会话是**整请求排他**的 —— `session_start()` 拿到锁，直到脚本结束才释放。
 * 后台是同一个浏览器、同一个会话，于是「清理无用 / 预热 / 迁移」这类长请求会把同一会话的**所有**
 * 其它请求挡在 `session_start()` 上，其中就包括 `cover-cache-status`。
 * 用户看到的就是「回收池读取中…」永远转下去：状态请求根本没开始执行，自然到不了 renderGc()。
 *
 * 为什么卡住不会自己结束：`max_execution_time` 只计 CPU 时间，**不计**阻塞在文件锁上的等待；
 * 而 cover_gc() 里还有 `@set_time_limit(0)`。⇒ 一旦长任务不返回，状态请求就无限期等下去。
 *
 * 调用时机：必须在 `csrf_require()` **之后**（它要读 $_SESSION['csrf_token']），
 * 且本文件在调用点之后**不再写 $_SESSION**（否则会丢写）。释放后 $_SESSION 数组在本请求内仍可读。
 */
$__releaseSessionLock = function (): void {
    if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
};

if ($action_key === 'cover-local-toggle') {
    $input = $__coverInput();
    $_POST['_csrf'] = $input['_csrf'] ?? '';
    csrf_require();
    $on = !empty($input['on']);
    if ($on && !s3_available()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => '还没配置存储（设置 → 储存），无法开启封面本地化']);
        exit;
    }
    cover_local_set($on);
    echo json_encode(['ok' => true, 'enabled' => cover_local_on()]);
    exit;
}

if ($action_key === 'cover-cache-status') {
    $input = $__coverInput();
    $_POST['_csrf'] = $input['_csrf'] ?? '';
    csrf_require();

    // 单个歌单的进度（配置页每张歌单卡片用）
    $pid = (int)($input['pid'] ?? 0);
    if ($pid > 0) {
        // 单歌单进度同样按归属收口（与批量入口同口径）：自己的可以看，别人的 403
        $ownStmt = $db->prepare("SELECT k.user_id FROM mapi_playlists p
                                 LEFT JOIN mapi_keys k ON k.id = p.key_id WHERE p.id=? LIMIT 1");
        $ownUid = null;
        if ($ownStmt) {
            $ownStmt->bind_param('i', $pid);
            $ownStmt->execute();
            $ownRes = $ownStmt->get_result();
            $ownUid = $ownRes ? (int)($ownRes->fetch_assoc()['user_id'] ?? 0) : null;
        }
        $meQ = (int)($_SESSION['admin_id'] ?? 0);
        $isSuperQ = (($_SESSION['admin_is_admin'] ?? 99) === 0);
        if ($ownUid === null || (!$isSuperQ && $ownUid !== $meQ)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => '无权限查看这个歌单的封面进度']);
            exit;
        }
        $prog = cover_playlist_progress($db, $pid);
        echo json_encode(['ok' => true, 'enabled' => cover_local_on(), 's3' => s3_available(),
                          'pid' => $pid, 'total' => $prog['total'], 'done' => $prog['done'], 'left' => $prog['left']]);
        exit;
    }

    // 全局：各歌单进度 + 旧数据迁移进度
    // 范围（2026-10-20 修）：普通管理员/用户只看自己名下密钥的歌单；
    // **超管看全部**（带归属前缀，否则看不到别人歌单的本地化进度，也没法帮着预热——
    // 封面表按 song_ref 全局共享，别人的歌没本地化，全站访客都拿不到 S3 直链）。
    $adminId  = (int)($_SESSION['admin_id'] ?? 0);
    $isSuperC = (($_SESSION['admin_is_admin'] ?? 99) === 0);
    $__users = [];
    if ($isSuperC) {
        $ur = $db->query("SELECT id, username FROM mapi_users");
        if ($ur) while ($u = $ur->fetch_assoc()) $__users[(int)$u['id']] = (string)$u['username'];
    }
    $pls = [];
    $r = $db->query("SELECT p.id, p.name, k.user_id AS owner_id FROM mapi_playlists p
                     LEFT JOIN mapi_keys k ON k.id = p.key_id"
                     . ($isSuperC ? "" : " WHERE k.user_id = $adminId") .
                     " ORDER BY p.sort_order ASC, p.id ASC");
    while ($r && $row = $r->fetch_assoc()) {
        $p = cover_playlist_progress($db, (int)$row['id']);
        // 空歌单也要报（与页面一致）：跳过的话新加的歌单在卡片里根本不出现，
        // 用户会以为"得重新添加一遍歌单"。前端对 total=0 显示"本地还没有歌"。
        $name = (string)$row['name'];
        if ($isSuperC && $name !== '') {
            $ownerId = (int)($row['owner_id'] ?? 0);
            if ($ownerId !== $adminId && $ownerId > 0) {
                $name = ($__users[$ownerId] ?? ('#' . $ownerId)) . ' / ' . $name;
            }
        }
        $pls[] = ['id' => (int)$row['id'], 'name' => $name,
                  'total' => $p['total'], 'done' => $p['done']];
    }
    echo json_encode(['ok' => true, 'enabled' => cover_local_on(), 's3' => s3_available(),
                      'playlists' => $pls, 'migrate' => cover_migrate_progress($db),
                      'gc' => cover_gc_stats($db, 7)]);
    exit;
}

if ($action_key === 'cover-cache-run') {
    $input = $__coverInput();
    $_POST['_csrf'] = $input['_csrf'] ?? '';
    csrf_require();
    $__releaseSessionLock();   // 长任务：先把会话锁放开，别挡住同一会话的状态轮询
    $pid = (int)($input['pid'] ?? 0);
    $limit = (int)($input['limit'] ?? 10);
    if ($pid <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => '缺少歌单 id']); exit; }
    if (!s3_available()) { http_response_code(400); echo json_encode(['ok' => false, 'error' => '未配置存储']); exit; }
    if (!cover_local_on()) { http_response_code(400); echo json_encode(['ok' => false, 'error' => '封面本地化未开启']); exit; }

    /* 归属校验（2026-10-20 补）：此前只收 pid 不验归属，任何管理员都能传别人的
       歌单 id 触发批量本地化（消耗对方的上游请求额度）。口径与 status 一致：
       自己名下（key.user_id = 我）；超管任意。 */
    $ownerStmt = $db->prepare("SELECT k.user_id FROM mapi_playlists p
                               LEFT JOIN mapi_keys k ON k.id = p.key_id WHERE p.id=? LIMIT 1");
    $pidOwner = null;
    if ($ownerStmt) {
        $ownerStmt->bind_param('i', $pid);
        $ownerStmt->execute();
        $ownerRes = $ownerStmt->get_result();
        $pidOwner = $ownerRes ? (int)($ownerRes->fetch_assoc()['user_id'] ?? 0) : null;
    }
    $me = (int)($_SESSION['admin_id'] ?? 0);
    $isSuperR = (($_SESSION['admin_is_admin'] ?? 99) === 0);
    if ($pidOwner === null || (!$isSuperR && $pidOwner !== $me)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => '无权限操作这个歌单的封面']);
        exit;
    }

    $res = cover_cache_playlist_batch($db, $cfg, $pid, $limit);
    // 注意别用 array_merge：$res 里也有个 'ok'（本批成功**条数**），会把协议里的 ok=true 覆盖成数字，
    // 前端按 d.ok 判成败时就会把「成功但 0 条」当失败。所以逐字段显式映射，条数叫 succeeded。
    echo json_encode([
        'ok' => true, 'pid' => $pid,
        'total' => $res['total'], 'done' => $res['done'], 'processed' => $res['processed'],
        'succeeded' => $res['ok'], 'reused' => $res['reused'], 'failed' => $res['failed'],
        'errors' => $res['errors'], 'finished' => $res['finished'],
    ]);
    exit;
}

if ($action_key === 'cover-migrate-run') {
    $input = $__coverInput();
    $_POST['_csrf'] = $input['_csrf'] ?? '';
    csrf_require();
    $__releaseSessionLock();   // 长任务：同上
    if (!s3_available()) { http_response_code(400); echo json_encode(['ok' => false, 'error' => '未配置存储']); exit; }

    $limit = (int)($input['limit'] ?? 10);
    $res = cover_migrate_batch($db, $limit);
    echo json_encode([
        'ok' => true, 'pid' => 0,
        'total' => $res['total'], 'done' => $res['done'], 'processed' => $res['processed'],
        'succeeded' => $res['ok'], 'reused' => $res['reused'], 'failed' => $res['failed'],
        'errors' => $res['errors'], 'finished' => $res['finished'],
    ]);
    exit;
}

if ($action_key === 'cover-gc-run') {
    $input = $__coverInput();
    $_POST['_csrf'] = $input['_csrf'] ?? '';
    csrf_require();
    $__releaseSessionLock();   // 长任务：GC 会对每个回收对象做一次 S3 删除，最该放开锁

    // 保留期默认 7 天：歌单删了又加回来的话，同一张图还在桶里能直接复用（sha256 命中），
    // 不必回源再抓一次。只有"确实没人用了且过了宽限期"的才回收。
    $days  = (int)($input['days'] ?? 7);
    if ($days < 0) $days = 7;
    $dry   = !empty($input['dry_run']);
    $limit = (int)($input['limit'] ?? 500);

    $res = cover_gc($db, $days, $limit, $dry);
    echo json_encode(array_merge(['ok' => true, 'days' => $days], $res));
    exit;
}

/**
 * 「清空回收池」—— 把池里的孤儿对象**现在就真删掉**（不等默认 7 天保留期）。
 *
 * 与 `cover-gc-run` 的区别**只有保留期**：`cover-gc-run` 按 days 走宽限期，
 * 本入口把保留期压到 0（立刻可回收）。其余判据**一个都不放宽**：
 *     `refs=0` **且** `orphaned_at IS NOT NULL`
 *   ⇒ **有引用的对象一个都不碰** ✗（这是本入口唯一真正危险的地方，所以复用 cover_gc 的同一段 SQL，
 *     不另写一份筛选条件 —— 两份实现迟早会漂移）
 *
 * ⚠ 破坏性：S3 删除**不可恢复** ✗ ⇒ 必须先 dry-run，由前端二次确认后再真删。
 * dry=1 时**只报不删**（列出将删哪些、共几个、多少字节），且**不校准引用计数**（干跑不动库）。
 *
 * 每次删除前**再查一次该对象的实时 refs**：`cover_gc` 开头的 `cover_refs_recount()` 已经把计数
 * 校准过，这里是第二道防线 —— 万一两次读之间有人把这首歌又关联回来，就跳过它（宁可少删，不可误删）。
 */
if ($action_key === 'cover-gc-purge') {
    $input = $__coverInput();
    $_POST['_csrf'] = $input['_csrf'] ?? '';
    csrf_require();
    $__releaseSessionLock();   // 长任务：每个对象一次 S3 删除

    if (!s3_available()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => '未配置存储']);
        exit;
    }
    if (!cover_local_on()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => '封面本地化未开启']);
        exit;
    }

    $dry   = !empty($input['dry_run']);
    $limit = (int)($input['limit'] ?? 500);

    if ($dry) {
        // 干跑：只读。列出确切的删除清单，让用户在真删之前看到"到底会动哪些"。
        $lim = max(1, min($limit, 2000));
        $items = [];
        $bytes = 0;
        $r = $db->query("SELECT sha256, object_key, bytes FROM mapi_cover_objects
                         WHERE refs=0 AND orphaned_at IS NOT NULL
                         ORDER BY orphaned_at ASC LIMIT $lim");
        while ($r && $x = $r->fetch_assoc()) {
            $items[] = ['key' => (string)$x['object_key'], 'bytes' => (int)$x['bytes']];
            $bytes += (int)$x['bytes'];
        }
        echo json_encode([
            'ok'        => true,
            'dry_run'   => true,
            'count'     => count($items),
            'bytes'     => $bytes,
            'objects'   => $items,
            'truncated' => count($items) >= $lim,
            'refs_fixed' => 0,      // 干跑不改库
            'objects_removed' => 0,
            'objects_failed'  => 0,
            'errors'    => [],
            'stats'     => cover_gc_stats($db, 0),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 真删：保留期压到 0（立刻可回收）。「只删 refs=0 且 orphaned_at 非空」这条判据仍由
    // cover_gc 内部那段 SQL 给出 —— 不在本文件再写一遍筛选条件，避免两份实现漂移。
    // 第 5 个参数 = 破坏性模式：删每个对象前再查一次实时 refs，非 0 就跳过（宁可少删）。
    $res = cover_gc($db, 0, $limit, false, true);

    // 失败必须逐条带出（不许静默跳过 ✗）：cover_gc 里的 'errors' 已含 "对象key: 原因"，
    // cover-gc-run 也是同一个形状，前端照旧读它。
    echo json_encode(array_merge([
        'ok'      => true,
        'dry_run' => false,
        'days'    => 0,
        'count'   => (int)($res['objects_removed'] ?? 0),
        'bytes'   => (int)($res['bytes_freed'] ?? 0),
    ], $res), JSON_UNESCAPED_UNICODE);
    exit;
}
