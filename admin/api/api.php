<?php
declare(strict_types=1);

// api.php — 音乐 API 主入口
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');
header('Timing-Allow-Origin: *');
// 动态接口不做任何缓存：配置/歌单/歌词随时可能变，避免浏览器启发式缓存导致"看到旧数据"
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
set_time_limit(30);

$_current_api_key = '';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$CFG = require __DIR__ . '/../../config/config.php';
$jwt_secret = $CFG['api']['jwt_secret'] ?? hash('sha256', ($CFG['db']['password'] ?? '') . ($CFG['site']['url'] ?? ''));
require __DIR__ . '/../../assets/lib/db.php';
require __DIR__ . '/../../assets/lib/api_config.php';
require __DIR__ . '/../../assets/lib/helpers.php';
require __DIR__ . '/../lib/cover_cache.php';      // 封面统一存数据库
// ⚠ 下面两个库带访问守卫（defined('MAPI_ADMIN') or defined('MAPI_PLAYER_API')），
//    必须放在 MAPI_PLAYER_API 定义之后 require，否则会被 die('禁止直接访问') 拦下
if (!defined('MAPI_PLAYER_API')) define('MAPI_PLAYER_API', true);   // 放行 admin/lib 的访问守卫
require_once __DIR__ . '/../lib/s3.php';           // 封面本地化：拼 S3 直链
require_once __DIR__ . '/../lib/cover_store.php';  // 封面本地化：内容寻址索引（已本地化的走直链）
require_once __DIR__ . '/../lib/redis.php';        // 播放器数据缓存（解析结果/配置/状态）；未启用或连不上时全部退化为未命中
require_once __DIR__ . '/../lib/domains.php';      // 域名授权 + 自动登记（与后台共用同一份数据层）

rate_limit_check('api', 120, 60);

$db_log = db_connect();
$api    = read_mapi_api_config($db_log, $CFG);
if ($db_log) cover_store_ensure($db_log);          // 幂等：首次运行建表/建列

$ua     = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

// 常量别名（兼容后续代码引用）
$apiBase = $api['base_url'];
$qqRef   = $api['qq_referer'];
$qqCover = $api['qq_cover'];
$fTitle  = $api['field_title'];
$fArtist = $api['field_artist'];
$fUrl    = $api['field_url'];
$fPic    = $api['field_pic'];
$fLrc    = $api['field_lrc'];
$pId     = $api['param_id'];
$pAuth   = $api['param_auth'];
$rServer = $api['param_server'];
$rType   = $api['param_type'];
$rId     = $api['param_id'];

$action = $_GET['action'] ?? '';
$id     = $_GET['id'] ?? '';
$limit  = min((int)($_GET['limit'] ?? 10), 50);

require __DIR__ . '/../../assets/lib/jwt.php';

// 存储对象 key → 可访问 URL。引擎这侧不能 require admin/lib/s3.php（那份带后台访问守卫），
// 所以这里只读 mapi_config 里的 s3 配置：custom_domain 优先，其次 endpoint/bucket，没配返回空串。
function api_storage_object_url($db, string $key): string {
    $key = trim($key);
    if ($key === '' || !$db) return '';
    try {
        $r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='s3'");
        $row = $r ? $r->fetch_assoc() : null;
        if (!$row) return '';
        $cfg = json_decode((string)($row['config_value'] ?? ''), true);
        if (!is_array($cfg)) return '';
        $prefix = trim((string)($cfg['path_prefix'] ?? ''), '/');
        $full   = $prefix !== '' ? $prefix . '/' . ltrim($key, '/') : ltrim($key, '/');
        $custom = rtrim(trim((string)($cfg['custom_domain'] ?? '')), '/');
        if ($custom !== '') return $custom . '/' . $full;
        $endpoint = rtrim(trim((string)($cfg['endpoint'] ?? '')), '/');
        $bucket   = trim((string)($cfg['bucket'] ?? ''));
        if ($endpoint === '' || $bucket === '') return '';
        return $endpoint . '/' . $bucket . '/' . $full;
    } catch (Throwable $e) { return ''; }
}

function auth_required(): string {
    global $jwt_secret;
    $token = '';
    if (preg_match('/^Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) {
        $token = $m[1];
    }
    $token = $token ?: ($_GET['token'] ?? '');
    if (!$token) { http_response_code(401); json_exit(['error' => '未授权，请提供 token', 'code' => 401, 'reason' => 'missing_token']); }
    $data = jwt_decode($token, $jwt_secret);
    if (!$data || empty($data['key'])) { http_response_code(401); json_exit(['error' => 'token 无效或已过期', 'code' => 401, 'reason' => 'invalid_token']); }
    $cookieSid = $_COOKIE['mapi_sid'] ?? '';
    $tokenSid  = $data['sid'] ?? '';
    if ($tokenSid && $cookieSid && hash_equals($tokenSid, $cookieSid)) {
        setcookie('mapi_sid', $cookieSid, ['expires' => time() + 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    }
    return $data['key'];
}

switch ($action) {
    case 'playlist':
        $_current_api_key = auth_required();
        if (!$id) json_error('缺少 id 参数');
        $server = $_GET['server'] ?? 'tencent';
        $server = in_array($server, ['tencent', 'netease'], true) ? $server : 'tencent';
        $url = Uri\Rfc3986\Uri::parse($apiBase)->withQuery(http_build_query([$rServer => $server, $rType => 'playlist', $rId => $id]))->toString();
        $raw = http_get($url, $ua, $qqRef);
        $songs = $raw ? json_decode($raw, true) : [];
        if (!$songs || !is_array($songs)) { json_exit([]); }
        $songs = array_slice($songs, 0, $limit);
        $result = [];
        $resolveUrls = [];
        $lrcUrls = [];
        foreach ($songs as $idx => $s) {
            $mid = '';
            if (!empty($s[$fLrc]) && preg_match('/[?&]' . preg_quote($pId, '/') . '=([^&]+)/', $s[$fLrc], $m)) {
                $mid = $m[1];
            } elseif (!empty($s[$fUrl]) && preg_match('/[?&]' . preg_quote($pId, '/') . '=([^&]+)/', $s[$fUrl], $m)) {
                $mid = $m[1];
            }
            $picUrl = '';
            if (!empty($s[$fPic])) {
                if (preg_match('/^https?:\/\//', $s[$fPic])) {
                    $picUrl = $s[$fPic];
                } else {
                    $picId = '';
                    $picAuth = '';
                    if (preg_match('/[?&]' . preg_quote($pId, '/') . '=([^&]+)/', $s[$fPic], $m)) {
                        $picId = $m[1];
                    }
                    if (preg_match('/[?&]' . preg_quote($pAuth, '/') . '=([^&]+)/', $s[$fPic], $m)) {
                        $picAuth = $m[1];
                    }
                    if ($picId) {
                        $queryParams = ['action' => 'pic', 'server' => $server, $pId => $picId];
                        if ($picAuth) $queryParams[$pAuth] = $picAuth;
                        $picUrl = '?' . http_build_query($queryParams);
                    }
                }
            }
            $playUrl = '';
            if ($server === 'netease' && $mid) {
                $resolveUrls[$idx] = Uri\Rfc3986\Uri::parse($apiBase)
                    ->withQuery(http_build_query([$rServer => $server, $rType => 'url', $rId => $mid]))
                    ->toString();
            } elseif ($server === 'tencent' && !empty($s[$fUrl])) {
                $playUrl = preg_replace('/^http:/i', 'https:', $s[$fUrl]);
            }
            $result[$idx] = [
                'id'     => $mid,
                'name'   => $s[$fTitle] ?? '未知',
                'artist' => $s[$fArtist] ?? '',
                'url'    => $playUrl,
                'pic'    => $picUrl,
                'lrc'    => '',
            ];
            if ($mid && !empty($s[$fLrc])) {
                $lrcUrls[$idx] = preg_replace('/^http:/i', 'https:', $s[$fLrc]);
            }
        }
        if (!empty($resolveUrls) || !empty($lrcUrls)) {
            $mh = curl_multi_init();
            $channels = [];
            foreach ($resolveUrls as $idx => $ru) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL            => $ru,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 6,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_USERAGENT      => $ua,
                    CURLOPT_REFERER        => $qqRef,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_HEADER         => true,
                    CURLOPT_TCP_NODELAY    => true,
                ]);
                curl_multi_add_handle($mh, $ch);
                $channels['r_' . $idx] = ['ch' => $ch, 'idx' => $idx, 'type' => 'resolve'];
            }
            foreach ($lrcUrls as $idx => $lu) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL            => $lu,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 6,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_USERAGENT      => $ua,
                    CURLOPT_REFERER        => $qqRef,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TCP_NODELAY    => true,
                ]);
                curl_multi_add_handle($mh, $ch);
                $channels['l_' . $idx] = ['ch' => $ch, 'idx' => $idx, 'type' => 'lrc'];
            }
            $running = null;
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh, 0.3);
            } while ($running > 0);
            foreach ($channels as $key => $info) {
                $ch = $info['ch'];
                $idx = $info['idx'];
                $raw = curl_multi_getcontent($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($info['type'] === 'resolve') {
                    if ($httpCode === 302 && is_string($raw)) {
                        preg_match('/^Location:\s+(.+)/im', $raw, $m);
                        $result[$idx]['url'] = trim($m[1] ?? '');
                    } elseif ($httpCode === 200 && is_string($raw)) {
                        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                        $body = substr($raw, $headerSize);
                        $json = json_decode($body, true);
                        if (is_array($json) && !empty($json['url'])) {
                            $result[$idx]['url'] = $json['url'];
                        }
                    }
                } else {
                    if ($httpCode === 200 && $raw) {
                        $lrcRaw = $raw;
                        if ($server === 'netease') {
                            $decoded = json_decode($raw, true);
                            if (is_array($decoded)) {
                                $lrcRaw = $decoded['lyric']
                                    ?? $decoded['lrc']['lyric']
                                    ?? $decoded['lrc']
                                    ?? $raw;
                            }
                        }
                        $result[$idx]['lrc'] = $lrcRaw;
                    }
                }
                curl_multi_remove_handle($mh, $ch);
            }
            curl_multi_close($mh);
        }
        cover_apply_urls($db_log, $result);            // 已本地化的封面换成 S3 直链
        json_exit(array_values($result));
        break;

    case 'url':
        $_current_api_key = auth_required();
        if (!$id) json_error('缺少 id 参数');
        $server = $_GET['server'] ?? 'tencent';
        $server = in_array($server, ['tencent', 'netease'], true) ? $server : 'tencent';
        $src = resolve_play_url($id, $apiBase, $ua, $qqRef, $server, $rServer, $rType, $rId);
        json_exit(['url' => $src]);
        break;

    case 'search':
        $_current_api_key = auth_required();
        $keyword = $_GET['keyword'] ?? '';
        if ($keyword === '') json_error('缺少 keyword 参数');
        $server = $_GET['server'] ?? 'netease';
        $server = in_array($server, ['tencent', 'netease'], true) ? $server : 'netease';
        $searchLimit = min((int)($_GET['limit'] ?? 10), 30);
        $url = Uri\Rfc3986\Uri::parse($apiBase)->withQuery(http_build_query([$rServer => $server, $rType => 'search', $rId => $keyword]))->toString();
        $raw = http_get($url, $ua, $qqRef);
        $songs = $raw ? json_decode($raw, true) : [];
        if (!$songs || !is_array($songs)) json_exit([]);
        $songs = array_slice($songs, 0, $searchLimit);
        $result = [];
        foreach ($songs as $s) {
            $mid = '';
            if (!empty($s[$fUrl]) && preg_match('/[?&]' . preg_quote($pId, '/') . '=([^&]+)/', $s[$fUrl], $m)) {
                $mid = $m[1];
            } elseif (!empty($s[$fLrc]) && preg_match('/[?&]' . preg_quote($pId, '/') . '=([^&]+)/', $s[$fLrc], $m)) {
                $mid = $m[1];
            }
            $picUrl = '';
            if (!empty($s[$fPic])) {
                if (preg_match('/^https?:\/\//', $s[$fPic])) {
                    $picUrl = $s[$fPic];
                } else {
                    $picId = '';
                    if (preg_match('/[?&]' . preg_quote($pId, '/') . '=([^&]+)/', $s[$fPic], $m)) $picId = $m[1];
                    if ($picId) $picUrl = (string)Uri\Rfc3986\Uri::parse($apiBase)->withQuery(http_build_query([$rServer => $server, $rType => 'pic', $pId => $picId]));
                }
            }
            $result[] = [
                'id'     => $mid,
                'name'   => $s[$fTitle] ?? '未知',
                'artist' => $s[$fArtist] ?? '',
                'server' => $server,
                'pic'    => $picUrl,
            ];
        }
        json_exit($result);
        break;

    case 'song':
        $_current_api_key = auth_required();
        if (!$id) json_error('缺少 id 参数');
        $server = $_GET['server'] ?? 'tencent';
        $server = in_array($server, ['tencent', 'netease'], true) ? $server : 'tencent';
        // ── 缓存优先（点哪首取哪首时，重复播同一首就不再回源）──
        // 注意：缓存里记了 server（songs 分支要用），但 song 的响应历来只有 id/name/artist/url/pic/lrc，
        // 这里去掉 server 保持"命中"与"回源"两条路响应形状完全一致。
        $hit = gw_song_cache_get($server, (string)$id);
        if ($hit) { unset($hit['server']); json_exit([$hit]); }
        $pm = gw_song_meta_get($server, (string)$id);
        if ($pm && $server === 'netease' && $pm['mid'] !== '') {
            // 元数据与歌词还在、只有播放地址过期：只补这一趟解析，省掉两趟回源
            $u = resolve_play_url($pm['mid'], $apiBase, $ua, $qqRef, $server, $rServer, $rType, $rId);
            if ($u !== '') {
                $row = ['id' => $pm['mid'], 'server' => $server, 'name' => $pm['name'], 'artist' => $pm['artist'],
                        'url' => $u, 'pic' => $pm['pic'], 'lrc' => $pm['lrc']];
                gw_song_cache_put($server, (string)$id, $row);
                json_exit([$row]);
            }
        }
        $url = Uri\Rfc3986\Uri::parse($apiBase)->withQuery(http_build_query([$rServer => $server, $rType => 'song', $rId => $id]))->toString();
        $raw = http_get($url, $ua, $qqRef);
        $song = $raw ? json_decode($raw, true) : [];
        if (!$song || !is_array($song)) json_exit([]);
        $song = $song[0] ?? $song;
        $mid = '';
        if (!empty($song[$fUrl]) && preg_match('/[?&]' . preg_quote($pId, '/') . '=([^&]+)/', $song[$fUrl], $m)) {
            $mid = $m[1];
        }
        $picUrl = '';
        if (!empty($song[$fPic])) {
            if (preg_match('/^https?:\/\//', $song[$fPic])) {
                $picUrl = $song[$fPic];
            } else {
                $picId = '';
                if (preg_match('/[?&]' . preg_quote($pId, '/') . '=([^&]+)/', $song[$fPic], $m)) $picId = $m[1];
                if ($picId) $picUrl = '?action=pic&server=' . $server . '&' . $pId . '=' . $picId;
            }
        }
        $playUrl = '';
        if ($server === 'netease') {
            if ($mid) $playUrl = resolve_play_url($mid, $apiBase, $ua, $qqRef, $server, $rServer, $rType, $rId);
        } else {
            if (!empty($song[$fUrl])) $playUrl = preg_replace('/^http:/i', 'https:', $song[$fUrl]);
        }
        $lrc = '';
        if (!empty($song[$fLrc])) {
            $lrcUrl = preg_replace('/^http:/i', 'https:', $song[$fLrc]);
            $lrcRaw = http_get($lrcUrl, $ua, $qqRef);
            if ($lrcRaw) {
                if ($server === 'netease') {
                    $decoded = json_decode($lrcRaw, true);
                    if (is_array($decoded)) $lrcRaw = $decoded['lyric'] ?? $decoded['lrc']['lyric'] ?? $decoded['lrc'] ?? $lrcRaw;
                }
                $lrc = $lrcRaw;
            }
        }
        // 响应字段保持与原样一致（不多塞 server/mid，免得改动客户端契约）；缓存里额外记 server/mid
        $row = [
            'id'     => $mid,
            'name'   => $song[$fTitle] ?? '未知',
            'artist' => $song[$fArtist] ?? '',
            'url'    => $playUrl,
            'pic'    => $picUrl,
            'lrc'    => $lrc,
        ];
        gw_song_cache_put($server, (string)$id, $row + ['server' => $server, 'mid' => $mid]);
        json_exit([$row]);
        break;

    case 'songs':
        // 批量取歌：给播放器的「依次加载」用。一次请求并行解析多首，
        // 上百首的歌单不必逐个 action=song 串行往返（那样既慢，也容易撞上 120 次/分钟的限流）。
        // ids=a,b,c 必填（≤50 首）；servers=netease,tencent 可省，逐个回落到 server 参数。
        // 返回与 ids 等长、同序的数组：url 为空串表示这首没解析出来（不会少项，前端可以按下标回填）。
        $_current_api_key = auth_required();
        $idsRaw = (string)($_GET['ids'] ?? '');
        if ($idsRaw === '' && $id !== '') $idsRaw = (string)$id;
        $idList = [];
        foreach (explode(',', $idsRaw) as $one) {
            $one = trim($one);
            if ($one !== '') $idList[] = $one;
            if (count($idList) >= 50) break;
        }
        if (!$idList) json_error('缺少 ids 参数');
        $serverDefault = $_GET['server'] ?? 'tencent';
        $serverDefault = in_array($serverDefault, ['tencent', 'netease'], true) ? $serverDefault : 'tencent';
        $serverList = array_map('trim', explode(',', (string)($_GET['servers'] ?? '')));
        $jobs = [];
        foreach ($idList as $i => $sid) {
            $sv = $serverList[$i] ?? '';
            if (!in_array($sv, ['tencent', 'netease'], true)) $sv = $serverDefault;
            $jobs[$i] = ['id' => $sid, 'server' => $sv];
        }

        // ── 缓存优先：命中的直接出结果，只对没命中的回源 ──
        //   完整命中（meta+url+lrc 都在）→ 一个请求都不用回源
        //   只差播放地址（meta+歌词还在，netease 有 mid）→ 只补一次解析，省掉元数据与歌词两趟
        $result = [];
        $resolveUrls = [];
        $lrcUrls = [];
        $needMeta = [];
        foreach ($jobs as $i => $job) {
            $hitSong = gw_song_cache_get($job['server'], $job['id']);
            if ($hitSong) { $result[$i] = $hitSong; continue; }
            $pm = gw_song_meta_get($job['server'], $job['id']);
            if ($pm) {
                $result[$i] = [
                    'id'     => $job['id'], 'server' => $job['server'], 'name' => $pm['name'],
                    'artist' => $pm['artist'], 'url' => '', 'pic' => $pm['pic'], 'lrc' => $pm['lrc'],
                ];
                if ($job['server'] === 'netease' && $pm['mid'] !== '') {
                    $resolveUrls[$i] = Uri\Rfc3986\Uri::parse($apiBase)
                        ->withQuery(http_build_query([$rServer => 'netease', $rType => 'url', $rId => $pm['mid']]))
                        ->toString();
                    continue;
                }
                unset($result[$i]);          // 腾讯等没有"只补地址"的路径：老实回源
            }
            $needMeta[$i] = true;
        }

        // 第一波（并行）：每首的上游元数据 —— 名字/歌手/封面/原始播放地址与歌词地址
        $mh = curl_multi_init();
        $metaChans = [];
        foreach ($needMeta as $i => $_) {
            $job = $jobs[$i];
            $mu = Uri\Rfc3986\Uri::parse($apiBase)->withQuery(http_build_query([$rServer => $job['server'], $rType => 'song', $rId => $job['id']]))->toString();
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $mu,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT      => $ua,
                CURLOPT_REFERER        => $qqRef,
                CURLOPT_TCP_NODELAY    => true,
            ]);
            curl_multi_add_handle($mh, $ch);
            $metaChans[] = ['ch' => $ch, 'idx' => $i];
        }
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.3);
        } while ($running > 0);
        $meta = [];
        foreach ($metaChans as $info) {
            $mi = $info['idx'];
            $mraw = curl_multi_getcontent($info['ch']);
            $mcode = curl_getinfo($info['ch'], CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $info['ch']);
            $meta[$mi] = ($mcode === 200 && is_string($mraw)) ? json_decode($mraw, true) : null;
        }
        curl_multi_close($mh);

        // 第二波：整理出每首的播放地址与歌词来源（netease 的播放地址要再回源换一次真链）
        // $result / $resolveUrls / $lrcUrls 已在上面「缓存优先」那步初始化；这里只处理没命中的（$needMeta）
        foreach ($needMeta as $i => $_) {
            $job = $jobs[$i];
            $server = $job['server'];
            $song = (isset($meta[$i]) && is_array($meta[$i])) ? $meta[$i] : [];
            if (isset($song[0]) && is_array($song[0])) $song = $song[0];       // 上游有时包一层数组
            $mid = '';
            if (!empty($song[$fLrc]) && preg_match('/[?&]' . preg_quote($pId, '/') . '=([^&]+)/', $song[$fLrc], $m)) {
                $mid = $m[1];
            } elseif (!empty($song[$fUrl]) && preg_match('/[?&]' . preg_quote($pId, '/') . '=([^&]+)/', $song[$fUrl], $m)) {
                $mid = $m[1];
            }
            $picUrl = '';
            if (!empty($song[$fPic])) {
                if (preg_match('/^https?:\/\//', $song[$fPic])) {
                    $picUrl = $song[$fPic];
                } else {
                    $picId = '';
                    if (preg_match('/[?&]' . preg_quote($pId, '/') . '=([^&]+)/', $song[$fPic], $m)) $picId = $m[1];
                    if ($picId) $picUrl = '?action=pic&server=' . rawurlencode($server) . '&' . $pId . '=' . rawurlencode($picId);
                }
            }
            $playUrl = '';
            if ($server === 'netease') {
                if ($mid) {
                    // 与 case 'playlist' 同一套：交给第二波并行解析
                    $resolveUrls[$i] = Uri\Rfc3986\Uri::parse($apiBase)
                        ->withQuery(http_build_query([$rServer => $server, $rType => 'url', $rId => $mid]))
                        ->toString();
                }
            } elseif (!empty($song[$fUrl])) {
                $playUrl = preg_replace('/^http:/i', 'https:', $song[$fUrl]);
            }
            $result[$i] = [
                'id'     => $job['id'],
                'server' => $server,
                'name'   => $song[$fTitle] ?? '未知',
                'artist' => $song[$fArtist] ?? '',
                'url'    => $playUrl,
                'pic'    => $picUrl,
                'lrc'    => '',
            ];
            if (!empty($song[$fLrc])) {
                $lrcUrls[$i] = preg_replace('/^http:/i', 'https:', $song[$fLrc]);
            }
        }
        if (!empty($resolveUrls) || !empty($lrcUrls)) {
            $mh = curl_multi_init();
            $channels = [];
            foreach ($resolveUrls as $i => $ru) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL            => $ru,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 6,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_USERAGENT      => $ua,
                    CURLOPT_REFERER        => $qqRef,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_HEADER         => true,
                    CURLOPT_TCP_NODELAY    => true,
                ]);
                curl_multi_add_handle($mh, $ch);
                $channels['r_' . $i] = ['ch' => $ch, 'idx' => $i, 'type' => 'resolve'];
            }
            foreach ($lrcUrls as $i => $lu) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL            => $lu,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 6,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_USERAGENT      => $ua,
                    CURLOPT_REFERER        => $qqRef,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TCP_NODELAY    => true,
                ]);
                curl_multi_add_handle($mh, $ch);
                $channels['l_' . $i] = ['ch' => $ch, 'idx' => $i, 'type' => 'lrc'];
            }
            $running = null;
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh, 0.3);
            } while ($running > 0);
            foreach ($channels as $key => $info) {
                $ch = $info['ch'];
                $ci = $info['idx'];
                $raw = curl_multi_getcontent($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($info['type'] === 'resolve') {
                    if ($httpCode === 302 && is_string($raw)) {
                        preg_match('/^Location:\s+(.+)/im', $raw, $m);
                        $result[$ci]['url'] = trim($m[1] ?? '');
                    } elseif ($httpCode === 200 && is_string($raw)) {
                        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                        $body = substr($raw, $headerSize);
                        $json = json_decode($body, true);
                        if (is_array($json) && !empty($json['url'])) {
                            $result[$ci]['url'] = $json['url'];
                        }
                    }
                } elseif ($httpCode === 200 && $raw) {
                    $lrcRaw = $raw;
                    if (($result[$ci]['server'] ?? '') === 'netease') {
                        $decoded = json_decode($raw, true);
                        if (is_array($decoded)) {
                            $lrcRaw = $decoded['lyric']
                                ?? $decoded['lrc']['lyric']
                                ?? $decoded['lrc']
                                ?? $raw;
                        }
                    }
                    $result[$ci]['lrc'] = $lrcRaw;
                }
                curl_multi_remove_handle($mh, $ch);
            }
            curl_multi_close($mh);
        }
        $rows = [];
        foreach ($jobs as $i => $job) {
            $row = $result[$i] ?? [
                'id' => $job['id'], 'server' => $job['server'], 'name' => '未知',
                'artist' => '', 'url' => '', 'pic' => '', 'lrc' => '',
            ];
            $rows[] = $row;
            gw_song_cache_put($job['server'], $job['id'], $row);   // 只缓存成功解析出地址的行
        }
        cover_apply_urls($db_log, $rows);              // 已本地化的封面换成 S3 直链
        json_exit($rows);
        break;

    case 'pic':
        $_current_api_key = auth_required();
        if (!$id) json_error('缺少 id 参数');
        $auth = $_GET['auth'] ?? '';
        $server = $_GET['server'] ?? 'tencent';
        $server = in_array($server, ['tencent', 'netease'], true) ? $server : 'tencent';
        // 封面已本地化（内容寻址存到 S3）：直接 302 过去。
        // 浏览器/CDN 直连对象存储，本网关不再搬运字节 —— 列表里 100 首就是 100 次请求的省法。
        $objKey = cover_song_object_key($db_log, $server, (string)$id);
        if ($objKey !== '') {
            logRequest('pic:' . $id, 0);
            header('Location: ' . s3_get_url($objKey), true, 302);
            exit;
        }
        // 未本地化的历史数据：库里那份 base64 命中就直接出图，不再回源
        $cachedCover = cover_song_get($db_log, $server, (string)$id);
        if ($cachedCover !== '' && cover_data_output($cachedCover)) {
            logRequest('pic:' . $id, 0);
            exit;
        }
        $params = [$rServer => $server, $rType => 'pic', $rId => $id];
        if ($auth) $params[$pAuth] = $auth;
        $src = Uri\Rfc3986\Uri::parse($apiBase)->withQuery(http_build_query($params))->toString();
        $finalUrl = resolve_final_url($src, $ua, $qqRef);
        logRequest('pic:' . $id, 0);
        header('Location: ' . ($finalUrl ?: $qqCover . $id . '.jpg'));
        exit;

    case 'verify-key':
        $input = json_decode(file_get_contents('php://input'), true);
        $k = $input['key'] ?? $_GET['key'] ?? '';
        $_current_api_key = $k;
        if (!$k) json_exit(['valid' => false, 'code' => 'missing_key', 'msg' => 'missing key']);
        $sid = bin2hex(random_bytes(16));
        $ttl = 86400;
        if (!$db_log) {
            $token = jwt_encode(['key' => $k, 'sid' => $sid, 'exp' => time() + $ttl, 'iat' => time()], $jwt_secret);
            setcookie('mapi_sid', $sid, ['expires' => time() + $ttl, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
            json_exit(['valid' => true, 'token' => $token, 'msg' => 'ok (db offline)']);
        }
        $stmt = $db_log->prepare("SELECT k.id FROM mapi_keys k WHERE k.api_key=? AND k.status=1");
        $stmt->bind_param('s', $k);
        $stmt->execute();
        $r = $stmt->get_result();
        $row = $r ? $r->fetch_assoc() : null;
        // 失败再细分：行还在但 status=0 是「被停用」，和「密钥不存在」不是一回事
        if (!$row) json_exit(['valid' => false, 'code' => key_fail_code($db_log, $k), 'msg' => 'invalid key']);
        $token = jwt_encode(['key' => $k, 'sid' => $sid, 'exp' => time() + $ttl, 'iat' => time()], $jwt_secret);
        setcookie('mapi_sid', $sid, ['expires' => time() + $ttl, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        json_exit(['valid' => true, 'token' => $token, 'msg' => 'ok']);

    case 'get-config':
        $k = $_GET['key'] ?? '';
        $tk = $_GET['token'] ?? '';
        if ($tk) {
            $data = jwt_decode($tk, $jwt_secret);
            if ($data && !empty($data['key'])) {
                $csid = $_COOKIE['mapi_sid'] ?? '';
                $tsid = $data['sid'] ?? '';
                if ($tsid && $csid && hash_equals($tsid, $csid)) {
                    setcookie('mapi_sid', $csid, ['expires' => time() + 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
                }
                $k = $data['key'];
            } elseif ($tk !== '') {
                $k = $tk;
            }
        }
        $_current_api_key = $k;
        if (!$k) json_exit(['ok' => false, 'config' => null, 'code' => 'missing_key', 'msg' => 'missing key']);
        $sourceCfg = [
            'base_url' => $apiBase,
            'params'   => ['server' => $rServer, 'type' => $rType, 'id' => $rId],
            'fields'   => ['title' => $fTitle, 'artist' => $fArtist, 'url' => $fUrl, 'pic' => $fPic, 'lrc' => $fLrc, 'id' => $pId, 'auth' => $pAuth],
        ];
        if (!$db_log) {
            json_exit(['ok' => true, 'config' => [
                'auto_theme' => 1, 'theme_mode' => 'light', 'lyrics_default' => 1,
                'autoplay_default' => 0, 'player_pos' => 'right:88', 'playlists' => [],
                'lrc_font' => '', 'lrc_font_name' => '', 'lrc_font_size' => 0,
                'source' => $sourceCfg,
            ]]);
        }
        $keyStmt = $db_log->prepare("SELECT user_id, id, name, domain FROM mapi_keys WHERE api_key=? AND status=1");
        $keyStmt->bind_param('s', $k);
        $keyStmt->execute();
        $keyRes = $keyStmt->get_result();
        $keyRow = $keyRes->fetch_assoc();
        if (!$keyRow) json_exit(['ok' => false, 'config' => null, 'code' => key_fail_code($db_log, $k), 'msg' => 'invalid key']);
        $userId = (int)$keyRow['user_id'];
        $keyId  = (int)($keyRow['id'] ?? 0);   // 域名授权可以按密钥区分（key_id=0 的行 = 该账号所有密钥共用）
        // 密钥自己的「授权域名」（创建密钥时填的）：非空时只有这一个主机名能用这条密钥
        $keyDomain = strtolower(trim((string)($keyRow['domain'] ?? '')));
        $userStmt = $db_log->prepare("SELECT auto_theme, theme_mode, lyrics_default, autoplay_default FROM mapi_users WHERE id=?");
        $userStmt->bind_param('i', $userId);
        $userStmt->execute();
        $userRes = $userStmt->get_result();
        $userRow = $userRes->fetch_assoc();
        if (!$userRow) {
            json_exit(['ok' => false, 'config' => null, 'code' => 'user_missing', 'msg' => '用户不存在']);
        }
        $autoTheme = $userRow ? (int)$userRow['auto_theme'] : 1;
        $themeMode = $userRow ? $userRow['theme_mode'] : 'light';
        $lyricsDefault = $userRow ? (int)($userRow['lyrics_default'] ?? 1) : 1;
        $autoplayDefault = $userRow ? (int)($userRow['autoplay_default'] ?? 0) : 0;
        // 歌词条字体：外部 URL 优先；没填 URL 就把上传的字体对象 key 换成可访问 URL。
        // 老库没有这几列时 prepare 会失败 → catch → 回落默认（不设置字体）。
        $lrcFont = ''; $lrcFontName = ''; $lrcFontSize = 0;
        try {
            $fontStmt = $db_log->prepare("SELECT lrc_font, lrc_font_url, lrc_font_name, lrc_font_size FROM mapi_users WHERE id=?");
            if ($fontStmt) {
                $fontStmt->bind_param('i', $userId);
                $fontStmt->execute();
                $fontRes = $fontStmt->get_result();
                $fontRow = $fontRes ? $fontRes->fetch_assoc() : null;
                if ($fontRow) {
                    $lrcFontName = trim((string)($fontRow['lrc_font_name'] ?? ''));
                    $lrcFontSize = (int)($fontRow['lrc_font_size'] ?? 0);
                    if ($lrcFontSize < 0 || $lrcFontSize > 200) $lrcFontSize = 0;
                    $fontUrl = trim((string)($fontRow['lrc_font_url'] ?? ''));
                    $fontKey = trim((string)($fontRow['lrc_font'] ?? ''));
                    $lrcFont = $fontUrl !== '' ? $fontUrl : api_storage_object_url($db_log, $fontKey);
                }
            }
        } catch (Throwable $e) { /* 新列不存在：不设自定义字体 */ }
        // 播放器首次加载位置（side:pct）：优先取【当前皮肤】的专属配置，其次老的全局列。
        // 老库没有新列时 prepare 会失败 → 落到 catch → 天然向后兼容。
        $playerPos = 'right:88';
        try {
            $posStmt = $db_log->prepare("SELECT player_pos, player_skin, player_skin_cfg FROM mapi_users WHERE id=?");
            if ($posStmt) {
                $posStmt->bind_param('i', $userId);
                $posStmt->execute();
                $posRes = $posStmt->get_result();
                $posRow = $posRes ? $posRes->fetch_assoc() : null;
                $cand = trim((string)($posRow['player_pos'] ?? ''));
                if (preg_match('/^(left|right):\d{1,3}$/', $cand)) $playerPos = $cand;
                // 皮肤级覆盖：{"router":{"pos":"left:80"},"rose":{"pos":"right:88"}}
                // 皮肤来源：嵌入方显式指定的 ?route= 优先（前端启动脚本会带上），
                // 否则用密钥归属用户配置的皮肤 —— 位置必须与真正渲染的那套皮肤对应。
                $curSkin = '';
                $rqSkin = (string)($_GET['route'] ?? '');
                if ($rqSkin !== '' && preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $rqSkin)) $curSkin = $rqSkin;
                if ($curSkin === '') $curSkin = trim((string)($posRow['player_skin'] ?? ''));
                if ($curSkin === '') $curSkin = 'router';
                $skinMap = json_decode((string)($posRow['player_skin_cfg'] ?? ''), true);
                if (is_array($skinMap)) {
                    $skinPos = trim((string)($skinMap[$curSkin]['pos'] ?? ''));
                    if (preg_match('/^(left|right):\d{1,3}$/', $skinPos)) $playerPos = $skinPos;
                }
            }
        } catch (Throwable $e) { /* 新列不存在：用默认 / 老列 */ }
        $keyId = (int)$keyRow['id'];
        $playlists = [];
        if ($db_log) {
            $plStmt = $db_log->prepare("SELECT id, name, type, remote_id, server, cover_url, cover_mode FROM mapi_playlists WHERE key_id=? ORDER BY sort_order ASC, id ASC");
            $plStmt->bind_param('i', $keyId);
            $plStmt->execute();
            $plRes = $plStmt->get_result();
            while ($pr = $plRes->fetch_assoc()) {
                $plItem = [
                    'pid'  => (int)$pr['id'],   // 稳定歌单标识：播放器用它记忆"上次听的歌单"，后台重排/改名都不受影响
                    'name' => $pr['name'],
                    'server' => $pr['server'],
                    'cover_url' => $pr['cover_url'],
                    'cover_mode' => $pr['cover_mode'],
                ];
                // 歌曲一律从库里取（missing=0 过滤掉上游已下架的）
                $plId = (int)$pr['id'];
                $songStmt = $db_log->prepare("SELECT song_id, name, artist, server FROM mapi_songs WHERE playlist_id=? AND missing=0 ORDER BY sort_order ASC, id ASC");
                $songStmt->bind_param('i', $plId);
                $songStmt->execute();
                $songRes = $songStmt->get_result();
                $plSongs = [];
                while ($sr = $songRes->fetch_assoc()) {
                    $plSongs[] = ['id' => $sr['song_id'], 'name' => $sr['name'], 'artist' => $sr['artist'], 'server' => $sr['server']];
                }
                if ($pr['type'] === 'remote' && $pr['remote_id']) {
                    if ($plSongs) {
                        // 有本地快照：走快照（歌曲有稳定 song_id，身份/排序/封面都稳，上游挂了也能放）
                        $plItem['type'] = 'custom';
                        $plItem['songs'] = $plSongs;
                    } else {
                        // 还没快照（创建时上游没取到）：保持原样，交给前端实时拉
                        $plItem['id'] = $pr['remote_id'];
                        $plItem['type'] = 'playlist';
                    }
                } else {
                    $plItem['type'] = 'custom';
                    $plItem['songs'] = $plSongs;
                }
                // ⚠ 封面必须随配置一起来，**不能等"解析播放地址"时才有**：
                // 播放器已改成点哪首取哪首（不再整单解析），列表若还靠解析结果填封面，
                // 没播过的歌就一张图都没有。所以这里就把封面 URL 发下去：
                //   ① 已本地化 → S3 绝对直链（浏览器直连对象存储，懒加载只取可见那几张）
                //   ② 没本地化 → 相对路径 ?action=pic…（网关按老逻辑出图：库里 base64 或 302 到上游）
                if (!empty($plItem['songs'])) {
                    cover_apply_urls($db_log, $plItem['songs']);
                    foreach ($plItem['songs'] as &$__s) {
                        if (empty($__s['pic'])) {
                            $__sv = (($__s['server'] ?? '') !== '') ? $__s['server'] : 'netease';
                            $__s['pic'] = '?action=pic&server=' . rawurlencode($__sv) . '&id=' . rawurlencode((string)$__s['id']);
                        }
                    }
                    unset($__s);
                }
                $playlists[] = $plItem;
            }
        }
        // ═══ 宿主域名配置（底部 inset 探测 / 域名授权）═══
        // 表不存在或没有匹配行时 $domainCfg 保持 null → 客户端走纯自动探测（与旧行为完全一致）。
        // 全局开关：mapi_config.domain_authorize，缺省视为 0（一律放行，不拦任何站）。
        $domainCfg = null;
        try {
            $hostSrc = '';
            foreach (['HTTP_REFERER', 'HTTP_ORIGIN'] as $_hk) {
                if (!empty($_SERVER[$_hk])) { $hostSrc = (string)$_SERVER[$_hk]; break; }
            }
            $host = $hostSrc !== '' ? (string)parse_url($hostSrc, PHP_URL_HOST) : '';
            $host = strtolower(trim($host, ". \t\n\r\0\x0B"));
            // 不做 www 合并：只认「填过的那个主机名」，主域名不会顺带授权它的子域名
            if ($host !== '' && preg_match('/^[a-z0-9.-]+$/', $host)) {
                if ($keyDomain !== '' && $host !== $keyDomain) {
                    // 密钥绑定了授权域名，而当前主机名不是它 → 直接拦掉。
                    // 也不再走下面的自动登记，免得给无关域名记一堆行。
                    $domainCfg = [
                        'host'         => $host,
                        'authorized'   => false,
                        'blocked'      => true,
                        'auto'         => true,
                        'lyricsBottom' => 0,
                        'playerBottom' => 0,
                        'pcAuto'       => true,
                        'pcLyrics'     => 0,
                        'pcPlayer'     => 0,
                        'moAuto'       => true,
                        'moLyrics'     => 0,
                        'moPlayer'     => 0,
                        'reason'       => 'key_domain',
                        'keyDomain'    => $keyDomain,
                    ];
                } else {
                    $authorizeOn = false;
                    $sw = $db_log->query("SELECT config_value FROM mapi_config WHERE config_key='domain_authorize'");
                    if ($sw && is_object($sw)) { $swRow = $sw->fetch_assoc(); $authorizeOn = ((string)($swRow['config_value'] ?? '0')) === '1'; }
                    $row = null;
                    // 域名授权按密钥区分：绑定到本条密钥的行优先（key_id 大的先），其次才是 key_id=0（不限）的行
                    $dStmt = $db_log->prepare("SELECT * FROM mapi_domains WHERE user_id=? AND domain=? AND (key_id=0 OR key_id=?) ORDER BY key_id DESC LIMIT 1");
                    if ($dStmt) {
                        $dStmt->bind_param('isi', $userId, $host, $keyId);
                        $dStmt->execute();
                        $dRes = $dStmt->get_result();
                        $row = $dRes ? $dRes->fetch_assoc() : null;
                    }
                    // 自动登记：mapi_config.domain_auto_add（缺省视为开）。主机名第一次加载播放器时
                    // 直接写进 mapi_domains 并授权，免得换了域名 / 上了 CDN / 多了个 www 就被自己的开关挡住。
                    // 已有行一律不动：管理员手动停用（authorized=0）的行不会被复活。
                    if (!$row && $authorizeOn && domain_auto_add_on($db_log)) {
                        $autoRow = domain_auto_register($db_log, $userId, $host, $keyId);
                        if ($autoRow) $row = $autoRow;
                    }
                    if ($row || $authorizeOn || $keyDomain !== '') {
                        // 密钥绑定的域名命中主机名时直接算已授权（等于密钥自带一份授权）
                        $authorized = $keyDomain !== '' ? true : ($row ? ((int)$row['authorized'] === 1) : false);
                        // 分 PC / 移动端两套让出配置；老数据（pc_* / mo_* 为 NULL）回落到旧字段
                        $dev = domains_device_cfg($row);
                        $domainCfg = [
                            'host'         => $host,
                            'authorized'   => $authorized,
                            'blocked'      => $authorizeOn && !$authorized,
                            // 旧字段：给老版本客户端兜底（1.6.x 只认这三个）
                            'auto'         => $dev['pc']['auto'],
                            'lyricsBottom' => $dev['pc']['lyrics'],
                            'playerBottom' => $dev['pc']['player'],
                            // 新字段：PC 与移动端各自一套
                            'pcAuto'       => $dev['pc']['auto'],
                            'pcLyrics'     => $dev['pc']['lyrics'],
                            'pcPlayer'     => $dev['pc']['player'],
                            'moAuto'       => $dev['mobile']['auto'],
                            'moLyrics'     => $dev['mobile']['lyrics'],
                            'moPlayer'     => $dev['mobile']['player'],
                        ];
                    }
                }
            }
        } catch (Throwable $e) { $domainCfg = null; }

        // ── 设备标识：播放器新增的 cookie（mapi_device）。没带就发一个，并随配置返回 ──
        //    跨站嵌入时第三方 cookie 可能被浏览器拦掉，所以播放器会把它镜像进宿主站 localStorage，
        //    之后用 ?dev= 显式回传（两条路服务端都认，见 gw_device_id）。
        $gwDev = gw_device_issue(gw_device_id());

        // ── 歌单配置缓存：只缓存"与访客无关"的那部分（歌单/歌曲/封面/字体/主题…）──
        //    以下三个字段必须每请求现算，绝不能进缓存，否则访客之间会互相串：
        //      · domain     —— 按宿主域名授权
        //      · device     —— 按访客（cookie / ?dev=）
        //      · player_pos —— 按皮肤（?route= 决定），不同皮肤位置不同
        $gwCfgTtl = redis_ttl_for('cfg');      // 0 = 这类缓存不启用（连 Redis 往返都省掉）
        $gwCfgKey = 'cfg:' . sha1((string)$k) . ':v' . cfg_cache_version();
        $gwBase = $gwCfgTtl > 0 ? cache_json_get($gwCfgKey, 'config') : null;
        if (!$gwBase) {
            $gwBase = [
                'auto_theme' => $autoTheme, 'theme_mode' => $themeMode, 'lyrics_default' => $lyricsDefault,
                'autoplay_default' => $autoplayDefault, 'lrc_font' => $lrcFont,
                'lrc_font_name' => $lrcFontName, 'lrc_font_size' => $lrcFontSize,
                'playlists' => $playlists, 'source' => $sourceCfg,
            ];
            if ($gwCfgTtl > 0) cache_json_set($gwCfgKey, $gwBase, $gwCfgTtl, 'config');
        }
        $gwBase['player_pos'] = $playerPos;    // 按皮肤：出缓存后再盖
        $gwBase['domain'] = $domainCfg;
        $gwBase['device'] = $gwDev;
        json_exit(['ok' => true, 'config' => $gwBase]);

    case 'state-get':
        // 播放器状态云同步（读）：按 IP + 设备 cookie 双重校验，校验不过一律不发旧状态
        $_current_api_key = auth_required();
        $gwR = device_state_get(gw_device_id(), gw_client_ip());
        if (in_array($gwR['reason'], ['disabled', 'unavailable'], true)) {
            json_exit(['ok' => false, 'reason' => $gwR['reason'], 'state' => [], 'device' => gw_device_id()]);
        }
        if (!empty($gwR['ok'])) {
            if ($gwR['reason'] === 'ip_rebound') gw_device_issue($gwR['dev']);   // 换网段：把（同一）设备号再确认一次
        } else {
            gw_device_issue($gwR['dev']);                                       // new / ip_mismatch：发新设备号
        }
        json_exit(['ok' => !empty($gwR['ok']), 'reason' => $gwR['reason'], 'state' => $gwR['state'], 'device' => $gwR['dev']]);

    case 'state-set':
        // 播放器状态云同步（写）：同样要过 IP + 设备校验；写成功才回 ok，连不上就如实报 unavailable
        $_current_api_key = auth_required();
        $gwDev = gw_device_id();
        $gwRaw = (string)($_POST['state'] ?? ($_GET['state'] ?? ''));
        $gwState = json_decode($gwRaw, true);
        if (!is_array($gwState)) json_exit(['ok' => false, 'reason' => 'bad_state', 'device' => $gwDev]);
        $gwW = device_state_set($gwDev, gw_client_ip(), $gwState);
        if (!empty($gwW['ok'])) gw_device_issue($gwDev);    // 首次写入即绑定，顺便把设备号再发一次
        json_exit(['ok' => !empty($gwW['ok']), 'reason' => $gwW['reason'], 'device' => $gwDev]);


    case 'inset-report':
        // 播放器上报「在宿主页面探测到的底部栏高度」。后台「域名」页据此显示最近探测值，
        // 方便管理员在探测不准的站点上手动填修正量。只影响 mapi_domains 的两个只读展示列，
        // 不参与任何判定；主机名从 Referer / Origin 取，与 get-config 同一套规则。
        if (!$db_log) json_exit(['ok' => false, 'msg' => 'db unavailable']);
        $k = trim((string)($_POST['key'] ?? ($_GET['key'] ?? '')));
        if ($k === '') json_exit(['ok' => false, 'msg' => 'missing key']);
        $kStmt = $db_log->prepare("SELECT id, user_id FROM mapi_keys WHERE api_key=? AND status=1");
        $kStmt->bind_param('s', $k);
        $kStmt->execute();
        $kRes = $kStmt->get_result();
        $kRow = $kRes ? $kRes->fetch_assoc() : null;
        if (!$kRow) json_exit(['ok' => false, 'msg' => 'invalid key']);
        $rUser = (int)$kRow['user_id'];
        $rKey  = (int)($kRow['id'] ?? 0);

        $hostSrc = '';
        foreach (['HTTP_REFERER', 'HTTP_ORIGIN'] as $_hk) {
            if (!empty($_SERVER[$_hk])) { $hostSrc = (string)$_SERVER[$_hk]; break; }
        }
        $rHost = $hostSrc !== '' ? (string)parse_url($hostSrc, PHP_URL_HOST) : '';
        $rHost = strtolower(trim($rHost, ". \t\n\r\0\x0B"));
        if ($rHost === '' || !preg_match('/^[a-z0-9.-]+$/', $rHost)) {
            json_exit(['ok' => false, 'msg' => 'no host']);
        }
        $rDev = ((string)($_POST['device'] ?? '')) === 'mobile' ? 'mobile' : 'pc';
        $rVal = (int)($_POST['value'] ?? 0);
        $rOk  = domains_report_inset($db_log, $rUser, $rHost, $rDev, $rVal, $rKey);
        json_exit(['ok' => $rOk, 'host' => $rHost, 'device' => $rDev, 'value' => $rVal]);

    case 'get-announcement':
        $k = $_GET['key'] ?? '';
        $tk = $_GET['token'] ?? '';
        if ($tk) {
            $data = jwt_decode($tk, $jwt_secret);
            if ($data && !empty($data['key'])) {
                $csid = $_COOKIE['mapi_sid'] ?? '';
                $tsid = $data['sid'] ?? '';
                if ($tsid && $csid && hash_equals($tsid, $csid)) {
                    setcookie('mapi_sid', $csid, ['expires' => time() + 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
                }
                $k = $data['key'];
            }
        }
        $_current_api_key = $k;
        if (!$k) json_exit(['enabled' => false, 'content' => '', 'msg' => 'missing key']);
        if (!$db_log) json_exit(['enabled' => false, 'content' => '', 'msg' => 'db unavailable']);
        $keyStmt = $db_log->prepare("SELECT user_id FROM mapi_keys WHERE api_key=? AND status=1");
        $keyStmt->bind_param('s', $k);
        $keyStmt->execute();
        $keyRes = $keyStmt->get_result();
        $keyRow = $keyRes->fetch_assoc();
        if (!$keyRow) json_exit(['enabled' => false, 'content' => '', 'msg' => 'invalid key']);
        $userId = (int)$keyRow['user_id'];
        $userStmt = $db_log->prepare("SELECT id FROM mapi_users WHERE id=?");
        $userStmt->bind_param('i', $userId);
        $userStmt->execute();
        $userRes = $userStmt->get_result();
        $userRow = $userRes->fetch_assoc();
        if (!$userRow) {
            json_exit(['enabled' => false, 'content' => '', 'msg' => '用户不存在']);
        }
        $r = $db_log->query("SELECT config_value FROM mapi_config WHERE config_key='announcement'");
        $row = $r ? $r->fetch_assoc() : null;
        if (!$row || !$row['config_value']) {
            json_exit(['enabled' => false, 'content' => '']);
        }
        $ann = json_decode($row['config_value'], true);
        if (!is_array($ann)) {
            json_exit(['enabled' => false, 'content' => '']);
        }
        json_exit([
            'enabled' => (bool)($ann['enabled'] ?? false),
            'content' => $ann['content'] ?? '',
            'title'   => $ann['title'] ?? '',
            'align'   => $ann['align'] ?? 'left',
        ]);

    default:
        json_error('不支持的操作');
}

function logRequest(string $endpoint, int $size = 0, string $apiKey = ''): void {
    global $db_log;
    if (!$db_log || $db_log->connect_error) return;
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
    $ref = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $stmt = $db_log->prepare("INSERT INTO mapi_logs (ip, referer, endpoint, user_agent, api_key, traffic_bytes) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('sssssi', $ip, $ref, $endpoint, $ua, $apiKey, $size);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * 列表里「已本地化」的封面换成 S3 绝对直链（一次批量查询）。
 * 播放器拿到绝对 URL 会直连对象存储，不再逐张打到本网关；
 * 未本地化的保持原样（仍走 ?action=pic 或上游直链），两种数据可以长期共存。
 */
function cover_apply_urls($db, array &$items): void {
    if (!$db || !$items) return;
    $urls = cover_song_urls($db, $items);
    if (!$urls) return;
    foreach ($items as &$it) {
        $sv  = ((($it['server'] ?? '') !== '') ? $it['server'] : 'netease');
        $sid = (string)($it['id'] ?? '');
        if ($sid === '') continue;
        $url = $urls[$sv . '_' . $sid] ?? '';
        if ($url !== '') $it['pic'] = $url;
    }
    unset($it);
}

/** 访客 IP：与限流同一套取值（反代优先 X-Forwarded-For） */
function gw_client_ip(): string
{
    $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    if (str_contains($ip, ',')) $ip = trim(explode(',', $ip)[0]);      // 反代链只取第一个
    return trim($ip);
}

/**
 * 设备标识（播放器新增 cookie `mapi_device`）：
 *   · 同源场景：服务端下发 cookie，之后每次请求自动带上
 *   · 跨站嵌入：第三方 cookie 常被浏览器拦掉 → 播放器把它记进宿主站 localStorage 并用 ?dev= 回传
 * 两条路都认，cookie 优先；只有格式合法（32~64 位 hex）才采用，否则当作没有（服务端会发新的）。
 */
function gw_device_id(): string
{
    $d = (string)($_COOKIE['mapi_device'] ?? '');
    if ($d === '') $d = (string)($_GET['dev'] ?? '');
    return device_id_valid($d) ? strtolower($d) : '';
}

/** 下发/续期设备 cookie（1 年）。不是 HttpOnly：同源时播放器要把它镜像进 localStorage */
function gw_device_issue(string $dev): string
{
    if ($dev === '') $dev = device_new_id();
    if (!headers_sent()) {
        setcookie('mapi_device', $dev, ['expires' => time() + 31536000, 'path' => '/', 'httponly' => false, 'samesite' => 'Lax']);
    }
    return $dev;
}

/**
 * 播放器数据缓存（Redis）：把「上游解析结果」按 meta / url / lrc 分开存 —— 三者的有效期完全不同：
 *   · url  ：上游给的是短时效签名链接，只能短缓存（ttl_url，默认 5 分钟）
 *   · lrc  ：歌词基本不变，长缓存（ttl_lrc，默认 7 天）
 *   · meta ：名字/歌手/封面/mid，长缓存（ttl_pic）
 * 键 = 类型 + sha1(server|id)。**只缓存"成功解析出 url"的行**：失败不缓存，下次自然重试。
 * Redis 关闭或连不上时这些函数全部退化成"未命中"，调用方照常回源（fail-open）。
 */
function gw_song_hash(string $server, string $id): string { return sha1($server . '|' . $id); }

/** 完整命中（meta + url + lrc 都在）→ 返回可直接下发的行；否则 null */
function gw_song_cache_get(string $server, string $id): ?array
{
    if (!function_exists('redis_enabled') || !redis_enabled()) return null;
    $h  = gw_song_hash($server, $id);
    $m  = cache_json_get('m:' . $h, 'songmeta');
    if (!$m || empty($m['url_ok'])) return null;
    $u  = cache_json_get('u:' . $h, 'songurl');
    if (!$u || empty($u['url'])) return null;
    $l  = cache_json_get('l:' . $h, 'songlrc');
    return [
        'id'     => $id,
        'server' => $server,
        'name'   => (string)($m['name'] ?? '未知'),
        'artist' => (string)($m['artist'] ?? ''),
        'url'    => (string)$u['url'],
        'pic'    => (string)($m['pic'] ?? ''),
        'lrc'    => (string)($l['lrc'] ?? ''),
    ];
}

/** 只有 meta + 歌词（播放地址已过期）：netease 可以凭 mid 只补一次地址，省掉元数据与歌词两趟回源 */
function gw_song_meta_get(string $server, string $id): ?array
{
    if (!function_exists('redis_enabled') || !redis_enabled()) return null;
    $h = gw_song_hash($server, $id);
    $m = cache_json_get('m:' . $h, 'songmeta');
    if (!$m || empty($m['url_ok'])) return null;
    $l = cache_json_get('l:' . $h, 'songlrc');
    if (!$l) return null;                      // 歌词也没了 → 当作未命中，走完整流程
    return [
        'name'   => (string)($m['name'] ?? '未知'),
        'artist' => (string)($m['artist'] ?? ''),
        'pic'    => (string)($m['pic'] ?? ''),
        'mid'    => (string)($m['mid'] ?? ''),
        'lrc'    => (string)($l['lrc'] ?? ''),
    ];
}

function gw_song_cache_put(string $server, string $id, array $row): void
{
    if (!function_exists('redis_enabled') || !redis_enabled()) return;
    if (empty($row['url'])) return;             // 没解析出地址 → 不缓存，交给下次重试
    $h = gw_song_hash($server, $id);
    cache_json_set('m:' . $h, [
        'name'   => (string)($row['name'] ?? ''),
        'artist' => (string)($row['artist'] ?? ''),
        'pic'    => (string)($row['pic'] ?? ''),
        'mid'    => (string)($row['mid'] ?? ($row['id'] ?? '')),
        'url_ok' => 1,
    ], redis_ttl_for('pic'), 'songmeta');
    cache_json_set('u:' . $h, ['url' => (string)$row['url']], redis_ttl_for('url'), 'songurl');
    cache_json_set('l:' . $h, ['lrc' => (string)($row['lrc'] ?? '')], redis_ttl_for('lrc'), 'songlrc');
}

/** 歌单配置 JSON 的缓存版本号/失效：实现在 admin/lib/redis.php（管理端写操作也要用同一份） */

#[\NoReturn]
function json_exit(mixed $data): never {
    global $_current_api_key;
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo $json;
    logRequest(($_GET['action'] ?? '?') . ':' . ($_GET['id'] ?? ''), strlen($json), $_current_api_key ?? '');
    exit;
}

#[\NoReturn]
function json_error(string $msg): never {
    json_exit(['error' => $msg, 'code' => 'server_error']);
}

// 密钥校验失败时细分原因：被停用（行还在、status≠1）还是根本不存在。
// 播放器端据此提示「密钥已被停用」而不是笼统的「密钥无效」。
function key_fail_code($db, string $k): string {
    if (!$db || $k === '') return 'invalid_key';
    try {
        $st = $db->prepare("SELECT status FROM mapi_keys WHERE api_key=? LIMIT 1");
        if (!$st) return 'invalid_key';
        $st->bind_param('s', $k);
        $st->execute();
        $res = $st->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        if ($row && (int)$row['status'] !== 1) return 'key_disabled';
    } catch (Throwable $e) {}
    return 'invalid_key';
}