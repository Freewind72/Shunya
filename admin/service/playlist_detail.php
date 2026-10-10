<?php defined('MAPI_ADMIN') or die('禁止直接访问');

// 读取歌单详情页所需数据
function playlist_detail_load($db, array $cfg, array $session, array $request): array {
    $plId = (int)($request['id'] ?? 0);
    if ($plId <= 0) return ['ok' => false, 'error' => '参数错误'];

    // 读取歌单与归属信息
    $pl = null;
    $r = $db->query("SELECT p.*, k.api_key, k.user_id as key_user_id, u.username FROM mapi_playlists p LEFT JOIN mapi_keys k ON p.key_id=k.id LEFT JOIN mapi_users u ON k.user_id=u.id WHERE p.id=$plId");
    if ($r) $pl = $r->fetch_assoc();
    if (!$pl) return ['ok' => false, 'error' => '歌单不存在'];

    // 校验歌单归属
    $isAdmin = (int)($session['admin_is_admin'] ?? 99) <= 1;
    $isOwner = $isAdmin || (int)$pl['key_user_id'] === (int)($session['admin_id'] ?? 0);
    if (!$isOwner) return ['ok' => false, 'error' => '无权限'];

    // 读取歌曲并统计已下架数量
    $songs = [];
    $missingCount = 0;
    $r = $db->query("SELECT id, song_id, name, artist, server, sort_order, missing FROM mapi_songs WHERE playlist_id=$plId ORDER BY sort_order ASC, id ASC");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            if ((int)($row['missing'] ?? 0) === 1) $missingCount++;
            $songs[] = $row;
        }
    }

    $isRemote = ($pl['type'] ?? '') === 'remote';
    $apiBase  = $cfg['api']['base_url'] ?? '';
    $pId      = $cfg['api']['param_id'] ?? 'id';
    $rServer  = $cfg['api']['param_server'] ?? 'server';
    $rType    = $cfg['api']['param_type'] ?? 'type';

    // 读取歌曲封面缓存：优先「已本地化」的 S3 直链（内容寻址），其次库里那份历史 base64，
    // 都没有才回落到上游取图地址 —— 迁移到 S3 之后这里不能再依赖 base64。
    $covers = cover_songs_load($db, $songs);
    $s3Covers = function_exists('cover_song_urls') ? cover_song_urls($db, $songs) : [];

    // 组装每首歌的输出数据
    $rows = [];
    foreach ($songs as $song) {
        $ref = $song['server'] . '_' . $song['song_id'];
        $pic = $covers[$ref] ?? '';
        if ($pic === '') $pic = $s3Covers[$ref] ?? '';
        if ($pic === '' && $apiBase !== '' && $song['song_id'] !== '') {
            $pic = $apiBase . '?' . http_build_query([$rServer => $song['server'], $rType => 'pic', $pId => $song['song_id']]);
        }
        $rows[] = [
            'id'        => (int)$song['id'],
            'name'      => (string)$song['name'],
            'artist'    => (string)$song['artist'],
            'pic'       => (string)$pic,
            'isMissing' => (int)($song['missing'] ?? 0) === 1,
        ];
    }

    // 生成播放器搜索令牌
    $searchToken = '';
    $firstKey = (string)($pl['api_key'] ?? '');
    if ($firstKey !== '') {
        $jwtSecret = $cfg['api']['jwt_secret'] ?? hash('sha256', ($cfg['db']['password'] ?? '') . ($cfg['site']['url'] ?? ''));
        $searchToken = jwt_encode(['key' => $firstKey, 'exp' => time() + 300, 'iat' => time()], $jwtSecret);
    }

    // 汇总页面数据
    return [
        'ok'           => true,
        'pl'           => $pl,
        'songs'        => $rows,
        'songCount'    => count($rows),
        'missingCount' => $missingCount,
        'isRemote'     => $isRemote,
        'pageData'     => [
            'csrf'        => csrf_token(),
            'playlistId'  => $plId,
            'searchToken' => $searchToken,
            'apiBase'     => $apiBase,
            'pId'         => $pId,
            'pServer'     => $pl['server'] ?? 'netease',
            'pType'       => $pl['type'] ?? '',
            'plName'      => $pl['name'],
            'plCoverMode' => $pl['cover_mode'] ?? 'auto',
        ],
    ];
}