<?php

// 封面存储 (数据库版)

$_coverLegacyDir  = __DIR__ . '/../assets';
$_plCoverFile     = $_coverLegacyDir . '/playlist_covers.json';   // 仅用于一次性迁移
$_songCoverFile   = $_coverLegacyDir . '/song_covers.json';       // 仅用于一次性迁移
$_coverStoreFlag  = 'cover_store_v2';

function cover_is_sqlite($db): bool
{
    return stripos(get_class($db), 'sqlite') !== false;
}

function cover_table_exists($db, string $table): bool
{
    try {
        $r = $db->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
        return $r !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function cover_column_exists($db, string $table, string $column): bool
{
    try {
        $r = $db->query('SELECT ' . $column . ' FROM ' . $table . ' LIMIT 1');
        return $r !== false;
    } catch (Throwable $e) {
        return false;
    }
}

// 幂等: 建列/建表 + 迁移旧 JSON + 删除 JSON 文件. 每个请求一次轻量查询即可短路.
function cover_store_ensure($db): void
{
    global $_coverStoreFlag, $_plCoverFile, $_songCoverFile;
    if (!$db) return;
    $now = date('Y-m-d H:i:s');
    try {
        $r = $db->query("SELECT setting_value FROM mapi_super_settings WHERE setting_key='$_coverStoreFlag'");
        if ($r && $r->fetch_assoc()) return;                       // 已迁移完成
    } catch (Throwable $e) {
        return;                                                    // 没有该表（未安装完）时不动
    }

    $sqlite = cover_is_sqlite($db);

    // 1) 歌单封面列
    if (!cover_column_exists($db, 'mapi_playlists', 'cover_data')) {
        $db->query($sqlite
            ? "ALTER TABLE mapi_playlists ADD COLUMN cover_data TEXT"
            : "ALTER TABLE mapi_playlists ADD COLUMN cover_data MEDIUMTEXT NULL");
    }
    if (!cover_column_exists($db, 'mapi_playlists', 'cover_updated_at')) {
        $db->query($sqlite
            ? "ALTER TABLE mapi_playlists ADD COLUMN cover_updated_at TEXT"
            : "ALTER TABLE mapi_playlists ADD COLUMN cover_updated_at DATETIME NULL");
    }

    // 2) 歌曲封面表
    if (!cover_table_exists($db, 'mapi_song_covers')) {
        if ($sqlite) {
            $db->query("CREATE TABLE IF NOT EXISTS mapi_song_covers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                song_ref TEXT NOT NULL DEFAULT '',
                key_id INTEGER NOT NULL DEFAULT 0,
                cover_url TEXT DEFAULT '',
                cover_data TEXT,
                updated_at TEXT
            )");
            $db->query("CREATE UNIQUE INDEX IF NOT EXISTS uniq_song_covers_ref ON mapi_song_covers (song_ref)");
        } else {
            $db->query("CREATE TABLE IF NOT EXISTS mapi_song_covers (
                id INT NOT NULL AUTO_INCREMENT,
                song_ref VARCHAR(191) NOT NULL DEFAULT '',
                key_id INT NOT NULL DEFAULT 0,
                cover_url VARCHAR(500) DEFAULT '',
                cover_data MEDIUMTEXT NULL,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_song_covers_ref (song_ref)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    }

    // 3) 迁移旧 JSON → 数据库
    $plCount = 0;
    $songCount = 0;
    if (is_file($_plCoverFile)) {
        $data = json_decode((string)@file_get_contents($_plCoverFile), true);
        if (is_array($data)) {
            foreach ($data as $user => $items) {
                if (!is_array($items)) continue;
                foreach ($items as $pid => $b64) {
                    $pid = (int)$pid;
                    if (!$pid || !is_string($b64) || strpos($b64, 'data:') !== 0) continue;
                    $esc = $db->real_escape_string($b64);
                    $db->query("UPDATE mapi_playlists SET cover_data='$esc', cover_updated_at='$now' WHERE id=$pid AND (cover_data IS NULL OR cover_data='')");
                    if ($db->affected_rows > 0) $plCount++;
                }
            }
        }
        @unlink($_plCoverFile);
    }
    if (is_file($_songCoverFile)) {
        $data = json_decode((string)@file_get_contents($_songCoverFile), true);
        if (is_array($data)) {
            foreach ($data as $user => $items) {
                if (!is_array($items)) continue;
                $kid = 0;
                $kr = $db->query("SELECT k.id FROM mapi_keys k LEFT JOIN mapi_users u ON k.user_id=u.id WHERE u.username='" . $db->real_escape_string((string)$user) . "' ORDER BY k.id ASC LIMIT 1");
                if ($kr && $krow = $kr->fetch_assoc()) $kid = (int)$krow['id'];
                foreach ($items as $ref => $b64) {
                    $ref = trim((string)$ref);
                    if ($ref === '' || !is_string($b64) || strpos($b64, 'data:') !== 0) continue;
                    $refEsc = $db->real_escape_string($ref);
                    $esc = $db->real_escape_string($b64);
                    $exists = $db->query("SELECT id, cover_data FROM mapi_song_covers WHERE song_ref='$refEsc'");
                    if ($exists && $row = $exists->fetch_assoc()) {
                        if (empty($row['cover_data'])) {
                            $db->query("UPDATE mapi_song_covers SET cover_data='$esc', updated_at='$now' WHERE id=" . (int)$row['id']);
                            $songCount++;
                        }
                    } else {
                        $db->query("INSERT INTO mapi_song_covers (song_ref, key_id, cover_url, cover_data, updated_at) VALUES ('$refEsc', $kid, '', '$esc', '$now')");
                        $songCount++;
                    }
                }
            }
        }
        @unlink($_songCoverFile);
    }

    // 4) 只有封面地址、还没有图片数据的歌单，抓一次补齐（每个歌单都应有自己的封面）
    $filled = 0;
    $br = $db->query("SELECT id, cover_url, server, cover_data FROM mapi_playlists");
    if ($br) while ($bpl = $br->fetch_assoc()) {
        if (!empty($bpl['cover_data'])) continue;
        $url = trim((string)$bpl['cover_url']);
        if ($url === '') continue;
        $abs = cover_resolve_url(['api' => ['base_url' => '', 'param_id' => 'id']], $url, $bpl['server'] ?: 'netease');
        if ($abs === '') $abs = $url;                                  // 已是绝对地址
        if (!preg_match('#^https?://#i', $abs)) continue;              // 相对地址且无法解析，跳过
        $data = fetch_image_b64($abs);
        if ($data !== '') {
            $db->query("UPDATE mapi_playlists SET cover_data='" . $db->real_escape_string($data) . "', cover_updated_at='$now' WHERE id=" . (int)$bpl['id']);
            $filled++;
        }
    }

    // 5) 记录迁移标记（避免每次请求重复检查）
    $val = 'v2 pl=' . $plCount . ' song=' . $songCount . ' filled=' . $filled . ' at=' . date('Y-m-d H:i:s');
    $db->query("DELETE FROM mapi_super_settings WHERE setting_key='$_coverStoreFlag'");
    $db->query("INSERT INTO mapi_super_settings (setting_key, setting_value) VALUES ('$_coverStoreFlag', '" . $db->real_escape_string($val) . "')");
}

/** 把接口返回的 pic（可能是 {action:pic,...} 相对串）解析成可下载的绝对地址 */
function cover_resolve_url(array $cfg, string $pic, string $server): string
{
    $pic = trim($pic);
    if ($pic === '') return '';
    if (preg_match('#^https?://#i', $pic) || strpos($pic, 'data:') === 0) return $pic;
    $apiBase = $cfg['api']['base_url'] ?? '';
    if (!$apiBase) return '';
    $pId = $cfg['api']['param_id'] ?? 'id';
    if (preg_match('/[?&]' . preg_quote($pId, '/') . '=([^&]+)/', $pic, $m)) {
        $sv = $server ?: 'netease';
        if (preg_match('/server=([^&]+)/', $pic, $sm)) $sv = $sm[1];
        return $apiBase . '?' . http_build_query([
            $cfg['api']['param_server'] ?? 'server' => $sv,
            $cfg['api']['param_type'] ?? 'type'     => 'pic',
            $pId                                    => $m[1],
        ]);
    }
    return '';
}

/** 部分图源原图过大（网易云常见 1～10MB），入库前统一取 300×300 缩略图 */
function cover_thumb_url(string $url, int $size = 300): string
{
    if ($url === '' || strpos($url, 'data:') === 0) return $url;
    $host = (string)parse_url($url, PHP_URL_HOST);
    if (stripos($host, 'music.126.net') !== false) {
        if (preg_match('/[?&]param=/', $url)) return $url;
        return $url . (strpos($url, '?') === false ? '?' : '&') . 'param=' . $size . 'y' . $size;
    }
    if (stripos($host, 'gtimg.cn') !== false) {
        if (preg_match('/[?&]param=/', $url)) return $url;
        return $url . (strpos($url, '?') === false ? '?' : '&') . 'param=' . $size . 'y' . $size;
    }
    return $url;
}

function fetch_image_b64(string $url, int $maxSize = 524288): string
{
    if (!$url) return '';
    $thumb = cover_thumb_url($url);
    // 原图可能很大（中转接口返回的是 CDN 原图，网易云常见 1～10MB），先放宽下载上限，入库前统一压缩
    $raw = fetch_image_raw($thumb, 12582912);
    if ($raw === '' && $thumb !== $url) $raw = fetch_image_raw($url, 12582912);
    if ($raw === '') return '';
    return cover_encode_b64($raw);
}

/** 校验图片并压到 maxDim 以内（JPEG），存库体积可控 */
function cover_encode_b64(string $raw, int $maxDim = 400): string
{
    if (strlen($raw) < 100) return '';
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($raw);
    $allowed = ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'];
    if (!in_array($mime, $allowed, true)) {
        if (substr($raw, 0, 3) === "\xFF\xD8\xFF") $mime = 'image/jpeg';
        elseif (substr($raw, 0, 4) === "\x89PNG") $mime = 'image/png';
        elseif (substr($raw, 0, 4) === 'GIF8') $mime = 'image/gif';
        elseif (substr($raw, 0, 4) === 'RIFF') $mime = 'image/webp';
        else return '';
    }
    if (function_exists('imagecreatefromstring') && $mime !== 'image/svg+xml') {
        $img = @imagecreatefromstring($raw);
        if ($img) {
            $w = imagesx($img); $h = imagesy($img);
            if (max($w, $h) > $maxDim) {
                $scale = $maxDim / max($w, $h);
                $nw = max(1, (int)round($w * $scale));
                $nh = max(1, (int)round($h * $scale));
                $dst = @imagescale($img, $nw, $nh);
                if ($dst) $img = $dst;                 // PHP 8 起无需手动释放旧图
            }
            ob_start();
            @imagejpeg($img, null, 82);
            $jpeg = ob_get_clean();
            if ($jpeg && strlen($jpeg) > 100) return 'data:image/jpeg;base64,' . base64_encode($jpeg);
        }
    }
    if (strlen($raw) > 524288) return '';
    return 'data:' . $mime . ';base64,' . base64_encode($raw);
}

function fetch_image_raw(string $url, int $maxSize = 524288): string
{
    if ($url === '') return '';
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 8,
            'follow_location' => true,
            'max_redirects' => 3,
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36\r\nReferer: https://music.163.com/\r\n",
        ],
        'ssl'  => ['verify_peer' => false],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if (!$raw || strlen($raw) > $maxSize || strlen($raw) < 100) return '';
    return $raw;
}

/* ------------------------------ 歌单封面 ------------------------------ */

function cover_pl_row($db, int $id): ?array
{
    $r = $db->query("SELECT id, type, remote_id, server, cover_url, cover_mode, cover_data FROM mapi_playlists WHERE id=$id");
    if ($r && $row = $r->fetch_assoc()) return $row;
    return null;
}

/** 取歌单封面（优先数据库里的图片数据，其次封面地址） */
function cover_pl_src($db, int $id): string
{
    $pl = cover_pl_row($db, $id);
    if (!$pl) return '';
    if (!empty($pl['cover_data'])) return $pl['cover_data'];
    return (string)($pl['cover_url'] ?? '');
}

function cover_pl_set($db, int $id, string $url, string $data): void
{
    $sets = [];
    if ($url !== '') $sets[] = "cover_url='" . $db->real_escape_string($url) . "'";
    if ($data !== '') $sets[] = "cover_data='" . $db->real_escape_string($data) . "'";
    $sets[] = "cover_updated_at='" . date('Y-m-d H:i:s') . "'";
    if ($id > 0) $db->query('UPDATE mapi_playlists SET ' . implode(', ', $sets) . " WHERE id=$id");
}

function cover_pl_clear($db, int $id): void
{
    if ($id > 0) $db->query("UPDATE mapi_playlists SET cover_data=NULL, cover_updated_at='" . date('Y-m-d H:i:s') . "' WHERE id=$id");
}

// 依据 cover_mode 取歌单"该用的封面"-返回 [url, data].
function cover_pl_compute($db, array $cfg, array $pl): array
{
    $mode = $pl['cover_mode'] ?? 'auto';
    if (!in_array($mode, ['auto', 'first_song', 'last_song', 'url'], true)) $mode = 'auto';
    $server = $pl['server'] ?: 'netease';

    if ($mode === 'url') {
        $url = trim((string)($pl['cover_url'] ?? ''));
        return [$url, ''];
    }

    $wantLast = ($mode === 'last_song');
    $pic = '';
    if (($pl['type'] ?? '') === 'remote' && !empty($pl['remote_id'])) {
        $apiBase = $cfg['api']['base_url'] ?? '';
        if ($apiBase) {
            $fetchUrl = $apiBase . '?' . http_build_query([
                $cfg['api']['param_server'] ?? 'server' => $server,
                $cfg['api']['param_type'] ?? 'type'     => 'playlist',
                $cfg['api']['param_id'] ?? 'id'         => $pl['remote_id'],
            ]);
            $ctx = stream_context_create(['http' => ['timeout' => 10], 'ssl' => ['verify_peer' => false]]);
            $raw = @file_get_contents($fetchUrl, false, $ctx);
            if ($raw) {
                $list = json_decode($raw, true);
                if (is_array($list) && $list) {
                    $item = $wantLast ? $list[count($list) - 1] : $list[0];
                    $fPic = $cfg['api']['field_pic'] ?? 'pic';
                    if (is_array($item) && !empty($item[$fPic])) $pic = (string)$item[$fPic];
                }
            }
        }
    } else {
        $order = $wantLast ? 'DESC' : 'ASC';
        $r = $db->query("SELECT song_id, server FROM mapi_songs WHERE playlist_id=" . (int)$pl['id'] . " ORDER BY sort_order $order, id $order LIMIT 1");
        if ($r && $song = $r->fetch_assoc()) {
            $sServer = $song['server'] ?: $server;
            $apiBase = $cfg['api']['base_url'] ?? '';
            if ($apiBase && $song['song_id'] !== '') {
                $pickUrl = $apiBase . '?' . http_build_query([
                    $cfg['api']['param_server'] ?? 'server' => $sServer,
                    $cfg['api']['param_type'] ?? 'type'     => 'song',
                    $cfg['api']['param_id'] ?? 'id'         => $song['song_id'],
                ]);
                $ctx = stream_context_create(['http' => ['timeout' => 10], 'ssl' => ['verify_peer' => false]]);
                $raw = @file_get_contents($pickUrl, false, $ctx);
                if ($raw) {
                    $data = json_decode($raw, true);
                    $item = (is_array($data) && isset($data[0])) ? $data[0] : $data;
                    $fPic = $cfg['api']['field_pic'] ?? 'pic';
                    if (is_array($item) && !empty($item[$fPic])) $pic = (string)$item[$fPic];
                }
            }
        }
    }

    $url = cover_resolve_url($cfg, $pic, $server);
    if ($url === '' && !empty($pl['cover_url']) && $mode !== 'url' && empty($pl['cover_data'])) {
        $url = (string)$pl['cover_url'];                       // 取不到新图时保留已有封面
    }
    return [$url, ''];
}

// 刷新并保存歌单封面 (保存歌单 / 增删歌曲 / 手动换封面后调用) .
function cover_pl_refresh($db, array $cfg, int $id, bool $force = false): string
{
    $pl = cover_pl_row($db, $id);
    if (!$pl) return '';
    if (!$force && !empty($pl['cover_data'])) {
        $since = date('Y-m-d H:i:s', time() - 30);
        $fresh = $db->query("SELECT cover_updated_at FROM mapi_playlists WHERE id=$id AND cover_updated_at IS NOT NULL AND cover_updated_at > '$since'");
        if ($fresh && $fresh->fetch_assoc()) return (string)$pl['cover_data'];
    }
    list($url) = cover_pl_compute($db, $cfg, $pl);
    if ($url === '') {
        if (empty($pl['cover_data'])) return '';
        return (string)$pl['cover_data'];
    }
    $data = fetch_image_b64($url);
    if ($data === '') {
        if (!empty($pl['cover_url']) && $pl['cover_url'] !== $url) {
            $data = fetch_image_b64((string)$pl['cover_url']);
        }
        if ($data === '') {
            cover_pl_set($db, $id, $url, '');                   // 至少把地址记下来
            return '';
        }
    }
    cover_pl_set($db, $id, $url, $data);
    return $data;
}

/* ------------------------------ 歌曲封面 ------------------------------ */

function cover_song_get($db, string $server, string $songId): string
{
    $ref = $server . '_' . $songId;
    if ($ref === '_') return '';
    $r = $db->query("SELECT cover_data FROM mapi_song_covers WHERE song_ref='" . $db->real_escape_string($ref) . "'");
    if ($r && $row = $r->fetch_assoc()) return (string)($row['cover_data'] ?? '');
    return '';
}

/** 批量取（页面渲染歌曲列表时用），返回 ["server_songId" => dataURI] */
function cover_songs_load($db, array $songs): array
{
    $refs = [];
    foreach ($songs as $s) {
        if (empty($s['song_id'])) continue;
        $refs[] = ($s['server'] ?: 'netease') . '_' . $s['song_id'];
    }
    if (!$refs) return [];
    $refs = array_values(array_unique($refs));
    $in = [];
    foreach ($refs as $ref) $in[] = "'" . $db->real_escape_string($ref) . "'";
    $out = [];
    $r = $db->query('SELECT song_ref, cover_data FROM mapi_song_covers WHERE song_ref IN (' . implode(',', $in) . ')');
    if ($r) while ($row = $r->fetch_assoc()) {
        if (!empty($row['cover_data'])) $out[$row['song_ref']] = (string)$row['cover_data'];
    }
    return $out;
}

function cover_song_set($db, string $server, string $songId, int $keyId, string $url, string $data): void
{
    $ref = $server . '_' . $songId;
    if ($ref === '_') return;
    $refEsc = $db->real_escape_string($ref);
    $urlEsc = $db->real_escape_string($url);
    $dataEsc = $db->real_escape_string($data);
    $now = date('Y-m-d H:i:s');
    $r = $db->query("SELECT id, cover_data FROM mapi_song_covers WHERE song_ref='$refEsc'");
    if ($r && $row = $r->fetch_assoc()) {
        // 已有图片数据时不覆盖（除非本次明确给了新数据）
        $keepOld = ($data === '' && !empty($row['cover_data']));
        $dataSql = $keepOld ? "cover_data='" . $db->real_escape_string((string)$row['cover_data']) . "'" : "cover_data='$dataEsc'";
        $db->query("UPDATE mapi_song_covers SET cover_url='$urlEsc', $dataSql, key_id=$keyId, updated_at='$now' WHERE id=" . (int)$row['id']);
    } else {
        $db->query("INSERT INTO mapi_song_covers (song_ref, key_id, cover_url, cover_data, updated_at) VALUES ('$refEsc', $keyId, '$urlEsc', '$dataEsc', '$now')");
    }
}

function cover_song_unset($db, string $server, string $songId): void
{
    $ref = $server . '_' . $songId;
    $refEsc = $db->real_escape_string($ref);
    // 删索引行之前先记下它指向哪张图：删完要把对象的引用计数减回去。
    // 歌单删除（handlers/playlists.php）与歌曲移除（handlers/songs.php）都汇到这里，
    // 是唯一的收口点 —— 少了这一步，S3 里的图永远回收不掉、refs 也会越漂越脏。
    $sha = '';
    $r = $db->query("SELECT sha256 FROM mapi_song_covers WHERE song_ref='$refEsc'");
    if ($r && $row = $r->fetch_assoc()) $sha = (string)($row['sha256'] ?? '');
    $db->query("DELETE FROM mapi_song_covers WHERE song_ref='$refEsc'");
    if ($sha !== '' && function_exists('cover_object_release')) cover_object_release($db, $sha);
}

/** 把 data URI 直接输出成图片响应（播放器取封面时命中数据库缓存就直接出图，不再回源） */
function cover_data_output(string $dataUri): bool
{
    if (strpos($dataUri, 'data:') !== 0) return false;
    $comma = strpos($dataUri, ',');
    if ($comma === false) return false;
    $meta = substr($dataUri, 5, $comma - 5);
    $payload = substr($dataUri, $comma + 1);
    $mime = 'image/jpeg';
    if (preg_match('#^([\w/+.\-]+)#', $meta, $m)) $mime = $m[1];
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=86400');
    echo (stripos($meta, 'base64') !== false) ? base64_decode($payload) : rawurldecode($payload);
    return true;
}

/** 抓取并保存某首歌的封面（增删歌曲等改动后调用） */
function cover_song_refresh($db, array $cfg, string $server, string $songId, int $keyId = 0): string
{
    if ($songId === '') return '';
    $cached = cover_song_get($db, $server, $songId);
    if ($cached !== '') return $cached;
    $apiBase = $cfg['api']['base_url'] ?? '';
    if (!$apiBase) return '';
    $picUrl = $apiBase . '?' . http_build_query([
        $cfg['api']['param_server'] ?? 'server' => $server ?: 'netease',
        $cfg['api']['param_type'] ?? 'type'     => 'pic',
        $cfg['api']['param_id'] ?? 'id'         => $songId,
    ]);
    $data = fetch_image_b64($picUrl);
    if ($data !== '') cover_song_set($db, $server, $songId, $keyId, '', $data);
    return $data;
}
