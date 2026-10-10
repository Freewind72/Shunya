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
        $prog = cover_playlist_progress($db, $pid);
        echo json_encode(['ok' => true, 'enabled' => cover_local_on(), 's3' => s3_available(),
                          'pid' => $pid, 'total' => $prog['total'], 'done' => $prog['done'], 'left' => $prog['left']]);
        exit;
    }

    // 全局：各歌单进度 + 旧数据迁移进度
    // 范围与「歌单管理」页一致：只算当前管理员的 key 下的歌单（否则会把别人的歌单也报出来）
    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $pls = [];
    $r = $db->query("SELECT p.id, p.name FROM mapi_playlists p
                     LEFT JOIN mapi_keys k ON k.id = p.key_id
                     WHERE k.user_id = $adminId
                     ORDER BY p.sort_order ASC, p.id ASC");
    while ($r && $row = $r->fetch_assoc()) {
        $p = cover_playlist_progress($db, (int)$row['id']);
        // 空歌单也要报（与页面一致）：跳过的话新加的歌单在卡片里根本不出现，
        // 用户会以为"得重新添加一遍歌单"。前端对 total=0 显示"本地还没有歌"。
        $pls[] = ['id' => (int)$row['id'], 'name' => (string)$row['name'],
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
    $pid = (int)($input['pid'] ?? 0);
    $limit = (int)($input['limit'] ?? 10);
    if ($pid <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => '缺少歌单 id']); exit; }
    if (!s3_available()) { http_response_code(400); echo json_encode(['ok' => false, 'error' => '未配置存储']); exit; }
    if (!cover_local_on()) { http_response_code(400); echo json_encode(['ok' => false, 'error' => '封面本地化未开启']); exit; }

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
