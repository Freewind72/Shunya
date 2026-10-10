<?php defined('MAPI_ADMIN') or defined('MAPI_PLAYER_API') or die('禁止直接访问');
// MAPI_ADMIN：后台页面 / handler 入口；MAPI_PLAYER_API：播放器 api.php（与后台共用这份数据层）

/* ══════════════════════════════════════════════════════════════════════════
 * 封面本地化：内容寻址（sha256）→ S3
 *
 * 与 cover_cache.php 的分工
 *   cover_cache.php   抓取 +「图片塞进数据库」的历史实现（未迁移的旧数据仍靠它读）
 *   cover_store.php   把图片存到 S3，并按**图片内容的 sha256** 去重
 *
 * 存放规则（内容寻址，表结构见 install/sql/mysql.sql 的 mapi_cover_objects）
 *   covers/<sha 前 2 位>/<sha256>.<ext>
 *   同一张图不论出现在哪个歌单、哪首歌，永远只上传一份（先查对象表，命中就不再上传）；
 *   「哪首歌用哪张」记在 mapi_song_covers，引用计数记在 mapi_cover_objects。
 *   —— 这就是「按歌单分文件夹」与「sha256 一致只存一份」两个诉求的兼容做法：
 *      物理上只存一份，按歌单的分类关系由数据库索引表达。
 *
 * 开关 mapi_config.cover_local（后台手动开）
 *   关着时后台不会主动预热/迁移；但**已经本地化过的封面照旧走 S3 直链**
 *   （那是历史成果，不该因为关开关就退回逐张走 PHP 代理）。
 * ══════════════════════════════════════════════════════════════════════════ */

/** 开关：是否开启封面本地化（后台手动操作） */
function cover_local_on(): bool
{
    global $db;
    if (!$db) return false;
    $r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='cover_local'");
    if ($r && $row = $r->fetch_assoc()) return (string)$row['config_value'] === '1';
    return false;
}

function cover_local_set(bool $on): void
{
    global $db;
    if (!$db) return;
    $v = $on ? '1' : '0';
    $st = $db->prepare("REPLACE INTO mapi_config (config_key, config_value) VALUES ('cover_local', ?)");
    if ($st) { $st->bind_param('s', $v); $st->execute(); }
}

/** 图片字节 → [扩展名, MIME]。按内容嗅探，不信文件名与上游的口头声明 */
function cover_bytes_kind(string $raw): array
{
    if (strncmp($raw, "\xFF\xD8\xFF", 3) === 0)  return ['jpg', 'image/jpeg'];
    if (strncmp($raw, "\x89PNG\r\n\x1a\n", 8) === 0) return ['png', 'image/png'];
    if (strncmp($raw, 'GIF8', 4) === 0)          return ['gif', 'image/gif'];
    if (strncmp($raw, 'RIFF', 4) === 0 && substr($raw, 8, 4) === 'WEBP') return ['webp', 'image/webp'];
    if (strncmp($raw, 'BM', 2) === 0)            return ['bmp', 'image/bmp'];
    return ['jpg', 'image/jpeg'];                 // 认不出来按 jpeg 存：浏览器照样能渲染
}

/** 内容寻址的对象 key */
function cover_object_key(string $sha, string $ext): string
{
    return 'covers/' . substr($sha, 0, 2) . '/' . $sha . '.' . ($ext !== '' ? $ext : 'jpg');
}

/** 对象表里按 sha256 查（命中＝去重命中，直接复用，不再上传） */
function cover_object_row($db, string $sha): ?array
{
    if ($sha === '') return null;
    $r = $db->query("SELECT sha256, object_key, bytes, mime, refs FROM mapi_cover_objects WHERE sha256='" . $db->real_escape_string($sha) . "'");
    if ($r && $row = $r->fetch_assoc()) return $row;
    return null;
}

/**
 * 上传原始图片字节（去重的唯一入口）。
 * 返回 ['ok','sha','key','bytes','mime','reused']；reused=true 表示这张图早就在桶里。
 */
function cover_object_put($db, string $raw): array
{
    if (!s3_available())  return ['ok' => false, 'error' => '未配置存储'];
    if ($raw === '')      return ['ok' => false, 'error' => '图片内容为空'];

    $sha = hash('sha256', $raw);
    $exist = cover_object_row($db, $sha);
    if ($exist) {
        return ['ok' => true, 'sha' => $sha, 'key' => (string)$exist['object_key'],
                'bytes' => (int)$exist['bytes'], 'mime' => (string)$exist['mime'], 'reused' => true];
    }

    [$ext, $mime] = cover_bytes_kind($raw);
    $key = cover_object_key($sha, $ext);
    // 内容寻址的 key 永不改内容 → 让它长期可缓存；列表里几十上百张封面因此不必每次回源
    if (!s3_put($key, $raw, $mime, 'public, max-age=31536000, immutable')) return ['ok' => false, 'error' => '上传存储失败'];

    $bytes = strlen($raw);
    $now = date('Y-m-d H:i:s');
    $st = $db->prepare("INSERT INTO mapi_cover_objects (sha256, object_key, bytes, mime, refs, created_at) VALUES (?,?,?,?,0,?)");
    if ($st) { $st->bind_param('ssiss', $sha, $key, $bytes, $mime, $now); $st->execute(); }
    return ['ok' => true, 'sha' => $sha, 'key' => $key, 'bytes' => $bytes, 'mime' => $mime, 'reused' => false];
}

/** 引用计数：按 sha 重算指向它的歌曲数（换图/迁移后调用，保证计数不漂） */
function cover_object_refs_sync($db, string $sha): void
{
    if ($sha === '') return;
    $e = $db->real_escape_string($sha);
    $r = $db->query("SELECT COUNT(*) c FROM mapi_song_covers WHERE sha256='$e'");
    $n = 0;
    if ($r && $row = $r->fetch_assoc()) $n = (int)$row['c'];
    // 同一份引用计数还兼管"孤儿时刻"：
    //   归零 → 记下第一次没人用的时间（COALESCE 保证只记一次，不被后来的重算刷新）
    //   又被引用 → 清掉标记，免得正在用的对象被当成垃圾回收
    // 时间一律用 **UTC**（gmdate）：写标记的可能是网页进程、判保留期的可能是别的进程/CLI，
    // 时区不一致会让"7 天宽限期"凭空多算或少算几个小时。
    $now = gmdate('Y-m-d H:i:s');
    $tail = ($n === 0) ? ", orphaned_at=COALESCE(orphaned_at,'$now')" : ", orphaned_at=NULL";
    $db->query("UPDATE mapi_cover_objects SET refs=$n$tail WHERE sha256='$e'");
}

/**
 * 索引行被删掉之后调用：重算引用计数（归零即记为孤儿，进回收池）。
 * 歌单删除 / 歌曲移除都汇到 cover_song_unset()，那里会调这个 ——
 * 没有这一步，S3 对象永远回收不掉、refs 也会越漂越脏。
 */
function cover_object_release($db, string $sha): void
{
    cover_object_refs_sync($db, $sha);
}

/**
 * 引用计数自愈：按"当前真实引用"重算一遍，返回被修正的对象数。
 * 防的是绕过 cover_song_unset() 的删除路径（直接改库、级联删用户、外部脚本）——
 * 那些情况下 refs 会虚高、orphaned_at 永远为空，回收池就看不见它们了。
 * 用一次分组查询取全部计数，只更新对不上的行（不是逐行 COUNT）。
 */
function cover_refs_recount($db): int
{
    $counts = [];
    $r = $db->query("SELECT sha256, COUNT(*) c FROM mapi_song_covers WHERE sha256<>'' GROUP BY sha256");
    while ($r && $x = $r->fetch_assoc()) $counts[(string)$x['sha256']] = (int)$x['c'];

    $fixed = 0;
    $r = $db->query("SELECT sha256, refs FROM mapi_cover_objects");
    while ($r && $x = $r->fetch_assoc()) {
        $sha  = (string)$x['sha256'];
        $real = $counts[$sha] ?? 0;
        if ($real !== (int)$x['refs']) { cover_object_refs_sync($db, $sha); $fixed++; }
    }
    return $fixed;
}

/** 「歌曲已经不在任何歌单里、索引行却还在」的 ref 列表（可限量） */
function cover_index_orphans($db, int $limit = 200): array
{
    // 先把现存的歌曲身份拉成集合（不在 SQL 里 CONCAT/|| 拼，两种驱动写法不一样）
    $alive = [];
    $r = $db->query("SELECT DISTINCT song_id, server FROM mapi_songs WHERE song_id<>''");
    while ($r && $x = $r->fetch_assoc()) {
        $sv = ($x['server'] ?? '') !== '' ? $x['server'] : 'netease';
        $alive[$sv . '_' . $x['song_id']] = true;
    }
    $out = [];
    $lim = $limit > 0 ? ' LIMIT ' . (int)$limit : '';
    $r = $db->query("SELECT song_ref FROM mapi_song_covers$lim");
    while ($r && $x = $r->fetch_assoc()) {
        if (!isset($alive[(string)$x['song_ref']])) $out[] = (string)$x['song_ref'];
    }
    return $out;
}

/** 回收池现状：孤儿对象、过了保留期可回收的、以及无人引用的索引行 */
function cover_gc_stats($db, int $minAgeDays = 7): array
{
    $out = ['orphans' => 0, 'orphan_bytes' => 0, 'reclaimable' => 0, 'reclaimable_bytes' => 0,
            'index_orphans' => 0, 'min_age_days' => $minAgeDays];
    $r = $db->query("SELECT COUNT(*) c, COALESCE(SUM(bytes),0) b FROM mapi_cover_objects WHERE refs=0");
    if ($r && $x = $r->fetch_assoc()) { $out['orphans'] = (int)$x['c']; $out['orphan_bytes'] = (float)$x['b']; }
    $cut = gmdate('Y-m-d H:i:s', time() - max(0, $minAgeDays) * 86400);
    $cutEsc = $db->real_escape_string($cut);
    $r = $db->query("SELECT COUNT(*) c, COALESCE(SUM(bytes),0) b FROM mapi_cover_objects WHERE refs=0 AND orphaned_at IS NOT NULL AND orphaned_at <= '$cutEsc'");
    if ($r && $x = $r->fetch_assoc()) { $out['reclaimable'] = (int)$x['c']; $out['reclaimable_bytes'] = (float)$x['b']; }
    $out['index_orphans'] = count(cover_index_orphans($db, 0));
    return $out;
}

/**
 * 清理无用封面（后台手动触发，可干跑预览）。
 *   ① 索引行：歌曲已不在任何歌单 → 删行并释放引用
 *   ② 对象：refs=0 且过了保留期 → 删存储对象 + 删行，腾出空间
 * 保留期是故意的：歌单删了又加回来时，同一张图还在桶里能直接复用（sha256 命中），
 * 不必再回源抓一次；只有"确实没人用了"的才回收。
 */
function cover_gc($db, int $minAgeDays = 7, int $limit = 500, bool $dryRun = false): array
{
    @set_time_limit(0);
    $res = ['refs_fixed' => 0, 'index_orphans' => 0, 'objects_removed' => 0, 'bytes_freed' => 0,
            'objects_failed' => 0, 'dry_run' => $dryRun, 'errors' => []];

    // ⓪ 先把引用计数校准一遍：绕过 cover_song_unset() 的删除（直接改库、级联删用户）
    //    会让 refs 虚高、orphaned_at 为空 —— 不校准的话这些对象回收池永远看不到。
    if (!$dryRun) $res['refs_fixed'] = cover_refs_recount($db);

    // ① 孤儿索引行
    foreach (cover_index_orphans($db, 0) as $ref) {
        $refEsc = $db->real_escape_string($ref);
        $sha = '';
        $r = $db->query("SELECT sha256 FROM mapi_song_covers WHERE song_ref='$refEsc'");
        if ($r && $x = $r->fetch_assoc()) $sha = (string)($x['sha256'] ?? '');
        if (!$dryRun) {
            $db->query("DELETE FROM mapi_song_covers WHERE song_ref='$refEsc'");
            if ($sha !== '') cover_object_release($db, $sha);
        }
        $res['index_orphans']++;
    }

    // ② 过了保留期的孤儿对象
    $cut = $db->real_escape_string(gmdate('Y-m-d H:i:s', time() - max(0, $minAgeDays) * 86400));
    $lim = max(1, min($limit, 2000));
    $rows = [];
    $r = $db->query("SELECT sha256, object_key, bytes FROM mapi_cover_objects
                     WHERE refs=0 AND orphaned_at IS NOT NULL AND orphaned_at <= '$cut'
                     ORDER BY orphaned_at ASC LIMIT $lim");
    while ($r && $x = $r->fetch_assoc()) $rows[] = $x;
    foreach ($rows as $o) {
        if ($dryRun) { $res['objects_removed']++; $res['bytes_freed'] += (int)$o['bytes']; continue; }
        if (s3_delete((string)$o['object_key'])) {
            $shaEsc = $db->real_escape_string((string)$o['sha256']);
            $db->query("DELETE FROM mapi_cover_objects WHERE sha256='$shaEsc'");
            $res['objects_removed']++;
            $res['bytes_freed'] += (int)$o['bytes'];
        } else {
            $res['objects_failed']++;
            if (count($res['errors']) < 5) $res['errors'][] = $o['object_key'] . ': 存储删除失败（下轮再试）';
        }
    }

    $res['stats'] = cover_gc_stats($db, $minAgeDays);
    return $res;
}

/**
 * 把一首歌关联到某个封面对象：写 sha256/object_key/bytes/mime，并**清掉库里那份 base64**。
 * 换图时把旧对象的引用计数刷新回去，避免计数虚高。
 */
function cover_song_link($db, string $server, string $songId, int $keyId, array $obj): void
{
    $ref = $server . '_' . $songId;
    if ($ref === '_') return;
    $refEsc = $db->real_escape_string($ref);
    $now = date('Y-m-d H:i:s');
    $cur = $db->query("SELECT id, sha256 FROM mapi_song_covers WHERE song_ref='$refEsc'");
    $old = ($cur && $r1 = $cur->fetch_assoc()) ? $r1 : null;

    $sha = (string)$obj['sha'];
    $key = (string)$obj['key'];
    $bytes = (int)$obj['bytes'];
    $mime = (string)$obj['mime'];

    if ($old) {
        $id = (int)$old['id'];
        // 类型串必须与占位符一一对应：sha(s) key(s) bytes(i) mime(s) keyId(i) now(s) id(i)。
        // 曾经写成 'sssissi' —— mime 被当整数绑定，'image/jpeg' 直接落库成 0（真实数据校验抓到的）。
        $st = $db->prepare("UPDATE mapi_song_covers SET sha256=?, object_key=?, bytes=?, mime=?, key_id=?, cover_data=NULL, updated_at=? WHERE id=?");
        if ($st) { $st->bind_param('ssisisi', $sha, $key, $bytes, $mime, $keyId, $now, $id); $st->execute(); }
        if ((string)$old['sha256'] !== '' && (string)$old['sha256'] !== $sha) cover_object_refs_sync($db, (string)$old['sha256']);
    } else {
        $st = $db->prepare("INSERT INTO mapi_song_covers (song_ref, key_id, cover_url, cover_data, sha256, object_key, bytes, mime, updated_at) VALUES (?,?,'',NULL,?,?,?,?,?)");
        if ($st) { $st->bind_param('sississ', $ref, $keyId, $sha, $key, $bytes, $mime, $now); $st->execute(); }   // ref(s) keyId(i) sha(s) key(s) bytes(i) mime(s) now(s)
    }
    cover_object_refs_sync($db, $sha);
}

/** data URI（历史 base64）→ 原始字节 */
function cover_data_decode(string $dataUri): string
{
    if ($dataUri === '') return '';
    if (strpos($dataUri, 'data:') !== 0) {
        $bin = base64_decode(preg_replace('#\s+#', '', $dataUri), true);   // 不是 data URI：可能直接是 base64 串
        return $bin === false ? '' : $bin;
    }
    $comma = strpos($dataUri, ',');
    if ($comma === false) return '';
    $meta = substr($dataUri, 5, $comma - 5);
    $payload = substr($dataUri, $comma + 1);
    if (stripos($meta, 'base64') !== false) {
        $bin = base64_decode($payload, true);
        return $bin === false ? '' : $bin;
    }
    return rawurldecode($payload);
}

/** 上游取图地址（中转接口的 type=pic） */
function cover_api_pic_url(array $cfg, string $server, string $songId): string
{
    $apiBase = $cfg['api']['base_url'] ?? '';
    if ($apiBase === '' || $songId === '') return '';
    return $apiBase . '?' . http_build_query([
        $cfg['api']['param_server'] ?? 'server' => $server !== '' ? $server : 'netease',
        $cfg['api']['param_type']   ?? 'type'   => 'pic',
        $cfg['api']['param_id']     ?? 'id'     => $songId,
    ]);
}

/** 不跟随跳转，从 Location 里取出 CDN 真链（取不到返回空）—— 拿它才能要缩略图 */
function cover_cdn_url(string $url): string
{
    if ($url === '') return '';
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 8, 'follow_location' => 0,
                   'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n"],
        'ssl'  => ['verify_peer' => false],
    ]);
    @file_get_contents($url, false, $ctx);
    foreach (($http_response_header ?? []) as $h) {
        if (stripos($h, 'Location:') === 0) return trim(substr($h, 9));
    }
    return '';
}

/**
 * 原图 → 播放器真正要用的压缩版（与历史入库口径一致：≤400px 的 JPEG）。
 * 压过之后再取 sha256，能和「旧库迁移上来的那批」天然命中同一份对象（同图同 hash）。
 * 压不动的大图返回空 —— 由调用方走缩略图退路，**绝不原样存几 MB 的原图**（那比改造前更费流量）。
 */
function cover_optimize(string $raw): string
{
    if ($raw === '') return '';

    // ⚠ 先量尺寸，再决定解不解码。
    // imagecreatefromstring 对超大图会把 PHP 内存打爆，而「内存耗尽」是**致命错误**（catch 不住）：
    // 整个批次请求会当场中断、前端只拿到半截响应 —— 表现就是「预热卡在某个数字上不走了」
    //（本机实测：一张巨图即触发 Allowed memory size of 134217728 bytes exhausted）。
    // 一张 w×h 的图解码后至少占 w*h*4 字节，缩放还要再一份；这里按 8MP 画线（≈32MB×2）。
    $w = 0; $h = 0;
    if (function_exists('getimagesizefromstring')) {
        $info = @getimagesizefromstring($raw);
        if (is_array($info) && !empty($info[0]) && !empty($info[1])) { $w = (int)$info[0]; $h = (int)$info[1]; }
    }
    if ($w > 0 && $h > 0 && $w * $h > 8000000) return '';      // 太大：交给缩略图退路，别冒险解码

    if (function_exists('cover_encode_b64')) {
        $uri = cover_encode_b64($raw, 400);
        if ($uri !== '') {
            $bin = cover_data_decode($uri);
            if ($bin !== '') return $bin;
        }
    }
    if (strlen($raw) > 1048576) return '';      // 压不了又很大：交给退路处理
    return $raw;
}

/**
 * 取一首歌的封面：返回「要存进 S3 的字节」，兼顾去重与体积。
 *   ① 回源原图 → GD 压到 ≤400px（与历史入库同一口径，能和旧数据去重命中）
 *   ② 压缩失败（大图解码失败/内存不足）或不划算 → 顺着 302 找到 CDN 真链，要 ?param=300y300 缩略图
 *   ③ 都拿不到就放弃这一首（宁可留着走老路径，也不往桶里塞巨图）
 * 实测教训：某次预热把 2.6MB / 5.6MB 的原图直接存进了桶，播放器反而要多拉几百倍流量。
 */
function cover_fetch_optimized(array $cfg, string $server, string $songId): string
{
    $apiUrl = cover_api_pic_url($cfg, $server, $songId);
    if ($apiUrl === '') return '';

    // ① 原图（中转接口回的是 CDN 原图，网易云常见 1～10MB，所以上限给到 12MB）
    $raw = fetch_image_raw($apiUrl, 12582912);
    if ($raw !== '') {
        $opt = cover_optimize($raw);
        if ($opt !== '') return $opt;
    }

    // ② 缩略图退路：CDN 支持 ?param=300y300（cover_thumb_url 只对网易/QQ 的 CDN 生效）
    $cdn = cover_cdn_url($apiUrl);
    if ($cdn !== '') {
        $small = fetch_image_raw(cover_thumb_url($cdn, 300), 4194304);
        if ($small !== '') {
            $opt2 = cover_optimize($small);
            if ($opt2 !== '') return $opt2;
            if (strlen($small) <= 1048576) return $small;
        }
    }

    // ③ 原图不大就直接用
    if ($raw !== '' && strlen($raw) <= 1048576) return $raw;
    return '';
}

/**
 * 把一首歌的封面本地化（幂等）。
 * 优先用库里已有的 base64（迁移路径，不再回源），没有才回源抓。
 * 返回 ['status' => done|failed|skip, 'reused', 'key','sha','bytes','error']
 */
function cover_store_song($db, array $cfg, string $server, string $songId, int $keyId = 0): array
{
    if (!s3_available()) return ['status' => 'skip', 'error' => '未配置存储'];
    $ref = $server . '_' . $songId;
    if ($ref === '_') return ['status' => 'skip', 'error' => '缺少歌曲标识'];

    $refEsc = $db->real_escape_string($ref);
    $row = null;
    $r = $db->query("SELECT object_key, cover_data FROM mapi_song_covers WHERE song_ref='$refEsc'");
    if ($r && $x = $r->fetch_assoc()) $row = $x;
    if ($row && (string)$row['object_key'] !== '') {
        return ['status' => 'done', 'reused' => true, 'key' => (string)$row['object_key']];
    }

    $raw = '';
    if ($row && !empty($row['cover_data'])) $raw = cover_data_decode((string)$row['cover_data']);   // 迁移路径：库里那份已经是压缩过的
    if ($raw === '') $raw = cover_fetch_optimized($cfg, $server, $songId);                          // 回源：原图→压缩→缩略图退路
    if ($raw === '') return ['status' => 'failed', 'error' => '未取到封面'];

    $obj = cover_object_put($db, $raw);
    if (empty($obj['ok'])) return ['status' => 'failed', 'error' => (string)($obj['error'] ?? '上传失败')];

    cover_song_link($db, $server, $songId, $keyId, $obj);
    return ['status' => 'done', 'reused' => !empty($obj['reused']),
            'key' => (string)$obj['key'], 'sha' => (string)$obj['sha'], 'bytes' => (int)$obj['bytes']];
}

/** 某歌单里待本地化的歌：[{id, song_id, server, key_id}]，按歌单顺序 */
function cover_playlist_pending($db, int $playlistId): array
{
    $plEsc = (int)$playlistId;
    $songs = [];
    $r = $db->query("SELECT id, song_id, server, key_id FROM mapi_songs WHERE playlist_id=$plEsc AND song_id<>'' AND missing=0 ORDER BY sort_order ASC, id ASC");
    if ($r) while ($row = $r->fetch_assoc()) $songs[] = $row;
    if (!$songs) return [];

    // 一次性取出「已本地化」的 ref（不做 N 次查询；CONCAT/|| 两种驱动写法不同，所以 ref 在 PHP 里拼）
    $refs = [];
    foreach ($songs as $s) {
        $sv = ($s['server'] ?? '') !== '' ? $s['server'] : 'netease';
        $refs[] = $sv . '_' . $s['song_id'];
    }
    $done = cover_localized_refs($db, $refs);

    $out = [];
    foreach ($songs as $s) {
        $sv = ($s['server'] ?? '') !== '' ? $s['server'] : 'netease';
        if (!empty($done[$sv . '_' . $s['song_id']])) continue;
        $out[] = ['id' => (int)$s['id'], 'song_id' => (string)$s['song_id'], 'server' => $sv, 'key_id' => (int)$s['key_id']];
    }
    return $out;
}

/** 给定一批 ref，返回其中「已本地化」的集合（一次查询） */
function cover_localized_refs($db, array $refs): array
{
    $out = [];
    $in = [];
    foreach (array_unique($refs) as $ref) {
        if ($ref === '' || $ref === '_') continue;
        $in[] = "'" . $db->real_escape_string($ref) . "'";
    }
    if (!$in) return $out;
    $r = $db->query("SELECT song_ref FROM mapi_song_covers WHERE object_key<>'' AND song_ref IN (" . implode(',', $in) . ")");
    if ($r) while ($row = $r->fetch_assoc()) $out[(string)$row['song_ref']] = true;
    return $out;
}

/** 某首歌已本地化的对象 key（没有则空串）—— 网关据此 302 到 S3 直链 */
function cover_song_object_key($db, string $server, string $songId): string
{
    $ref = $server . '_' . $songId;
    if ($ref === '_') return '';
    $r = $db->query("SELECT object_key FROM mapi_song_covers WHERE object_key<>'' AND song_ref='" . $db->real_escape_string($ref) . "'");
    if ($r && $row = $r->fetch_assoc()) return (string)$row['object_key'];
    return '';
}

/**
 * 批量：ref => S3 公开直链（只返回已本地化的）。
 * 播放器与后台列表都用它：一次查询换出整列表的封面地址。
 */
function cover_song_urls($db, array $songs): array
{
    $refs = [];
    foreach ($songs as $s) {
        $sv = ($s['server'] ?? '') !== '' ? $s['server'] : 'netease';
        $sid = (string)($s['song_id'] ?? ($s['id'] ?? ''));
        if ($sid !== '') $refs[] = $sv . '_' . $sid;
    }
    $out = [];
    if (!$refs) return $out;
    $in = [];
    foreach (array_unique($refs) as $ref) $in[] = "'" . $db->real_escape_string($ref) . "'";
    $r = $db->query("SELECT song_ref, object_key FROM mapi_song_covers WHERE object_key<>'' AND song_ref IN (" . implode(',', $in) . ")");
    if ($r) while ($row = $r->fetch_assoc()) {
        $out[(string)$row['song_ref']] = s3_get_url((string)$row['object_key']);
    }
    return $out;
}

/** 本地化进度：total = 歌单里有效的歌；done = 其中已本地化的 */
function cover_playlist_progress($db, int $playlistId): array
{
    $pending = cover_playlist_pending($db, $playlistId);
    $plEsc = (int)$playlistId;
    $total = 0;
    $r = $db->query("SELECT COUNT(*) c FROM mapi_songs WHERE playlist_id=$plEsc AND song_id<>'' AND missing=0");
    if ($r && $row = $r->fetch_assoc()) $total = (int)$row['c'];
    return ['total' => $total, 'done' => max(0, $total - count($pending)), 'left' => count($pending)];
}

/**
 * 处理一批待本地化的封面。
 * 每首歌一张图 = 最多 1 次上游请求（命中库里 base64 时连这一步都省）；
 * 图相同的走 sha256 复用、不再上传 —— 请求数与存储都省在这里。
 */
function cover_cache_playlist_batch($db, array $cfg, int $playlistId, int $limit = 10): array
{
    // 图片解码很吃内存：先给这个请求留足头寸（宿主禁 ini_set 时无效、也无副作用；
    // 真正的防线是 cover_optimize() 里的尺寸守卫）
    $lim = (string)ini_get('memory_limit');
    if (preg_match('/^\s*(\d+)\s*([KMG]?)/i', $lim, $m)) {
        $mult = ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper($m[2])] ?? 1;
        if ((int)$m[1] * $mult < 268435456) @ini_set('memory_limit', '256M');
    }

    $pending = cover_playlist_pending($db, $playlistId);
    $batch = array_slice($pending, 0, max(1, min($limit, 50)));

    $ok = 0; $reused = 0; $failed = 0; $errors = [];
    foreach ($batch as $s) {
        $res = cover_store_song($db, $cfg, $s['server'], $s['song_id'], $s['key_id']);
        if ($res['status'] === 'done') {
            $ok++;
            if (!empty($res['reused'])) $reused++;
        } elseif ($res['status'] === 'failed') {
            $failed++;
            if (count($errors) < 5) $errors[] = $s['song_id'] . ': ' . ($res['error'] ?? '失败');
        }
    }
    $after = cover_playlist_progress($db, $playlistId);
    return ['total' => $after['total'], 'done' => $after['done'], 'processed' => count($batch),
            'ok' => $ok, 'reused' => $reused, 'failed' => $failed, 'errors' => $errors,
            'finished' => $after['total'] > 0 && $after['left'] === 0];
}

/* ── 旧数据迁移：数据库里那份 base64 → S3 ── */

/** 迁移进度：total = 有 base64 的旧行；done = 已上传 S3 的行 */
function cover_migrate_progress($db): array
{
    $total = 0; $done = 0;
    $r = $db->query("SELECT COUNT(*) c FROM mapi_song_covers WHERE cover_data IS NOT NULL AND cover_data<>''");
    if ($r && $row = $r->fetch_assoc()) $total = (int)$row['c'];
    $r = $db->query("SELECT COUNT(*) c FROM mapi_song_covers WHERE object_key<>''");
    if ($r && $row = $r->fetch_assoc()) $done = (int)$row['c'];
    return ['total' => $total, 'done' => $done, 'finished' => $total === 0];
}

/** 处理一批旧封面：base64 → S3，成功后清空 cover_data（DB 体积立刻降下来） */
function cover_migrate_batch($db, int $limit = 10): array
{
    $batch = [];
    $lim = max(1, min($limit, 50));
    $r = $db->query("SELECT id, song_ref, key_id, cover_data FROM mapi_song_covers WHERE cover_data IS NOT NULL AND cover_data<>'' ORDER BY id ASC LIMIT $lim");
    if ($r) while ($row = $r->fetch_assoc()) $batch[] = $row;

    $ok = 0; $reused = 0; $failed = 0; $errors = [];
    foreach ($batch as $row) {
        $ref = (string)$row['song_ref'];
        $pos = strpos($ref, '_');
        $server = $pos !== false ? substr($ref, 0, $pos) : '';
        $songId = $pos !== false ? substr($ref, $pos + 1) : '';
        if ($server === '' || $songId === '') { $failed++; continue; }

        $raw = cover_data_decode((string)$row['cover_data']);
        if ($raw === '') { $failed++; if (count($errors) < 5) $errors[] = $ref . ': 图片数据损坏'; continue; }

        $obj = cover_object_put($db, $raw);
        if (empty($obj['ok'])) { $failed++; if (count($errors) < 5) $errors[] = $ref . ': ' . ($obj['error'] ?? '上传失败'); continue; }

        cover_song_link($db, $server, $songId, (int)$row['key_id'], $obj);
        $ok++;
        if (!empty($obj['reused'])) $reused++;
    }
    $prog = cover_migrate_progress($db);
    return ['total' => $prog['total'], 'done' => $prog['done'], 'processed' => count($batch),
            'ok' => $ok, 'reused' => $reused, 'failed' => $failed, 'errors' => $errors,
            'finished' => $prog['finished']];
}
