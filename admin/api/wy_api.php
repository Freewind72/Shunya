<?php
declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Range');
header('Access-Control-Max-Age: 86400');
set_time_limit(120);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$CFG = require __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../assets/lib/db.php';
require_once __DIR__ . '/../../assets/lib/api_config.php';
require_once __DIR__ . '/../../assets/lib/helpers.php';

$ua     = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';

$db    = db_connect();
$api   = read_mapi_api_config($db, $CFG);
$pId   = $api['param_id'];
$pAuth = $api['param_auth'];
$rServer = $api['param_server'];
$rType   = $api['param_type'];
$rId     = $api['param_id'];

$action = $_GET['action'] ?? '';
$mid    = $_GET[$pId] ?? '';
$auth   = $_GET[$pAuth] ?? '';

match ($action) {
    'url' => (function() use ($mid, $api, $ua): void {
        if (!$mid) { http_response_code(400); echo json_encode(['error' => '缺少 id 参数']); exit; }
        $src = resolve_play_url($mid, $api['base_url'], $ua, $api['qq_referer'], 'netease', $api['param_server'], $api['param_type'], $api['param_id']);
        if (!$src) { http_response_code(404); echo json_encode(['error' => '无法获取播放地址']); exit; }
        proxy_audio($src, $ua);
    })(),
    default => (function(): void {
        http_response_code(400);
        echo json_encode(['error' => '不支持的操作']);
    })(),
};

function proxy_audio(string $url, string $ua): void {
    $url = preg_replace('/^http:/i', 'https:', $url);

    $respHeaders = [];
    $rangeOffset = 0;          // 客户端要的起点：只用于「向上游要哪一段」
    $rangeEnd = 0;             // 客户端要的终点（0 = 一直到结尾）
    $isRange = false;

    if (!empty($_SERVER['HTTP_RANGE'])) {
        if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
            $rangeOffset = (int)$m[1];
            $rangeEnd = $m[2] !== '' ? (int)$m[2] : 0;
            $isRange = true;
        }
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_REFERER => 'https://music.163.com/',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HEADERFUNCTION => function($ch, string $line) use (&$respHeaders): int {
            $len = strlen($line);
            // 每来一轮新的状态行就把头清空：只认最后一轮。跳转链上前几轮的头若留在手里会被当成最终响应
            //（302 自带的 Content-Length: 0 / Content-Range 一旦发出去，就又是「头与 body 不符」）
            if (preg_match('/^HTTP\//i', $line)) {
                $respHeaders = ['_http' => trim($line)];
                return $len;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            return $len;
        },
        CURLOPT_WRITEFUNCTION => function($ch, string $data) use (&$respHeaders): int {
            static $sent = false;
            if (!$sent) {
                $sent = true;
                $httpCode = 200;
                if (!empty($respHeaders['_http']) && preg_match('/\s(\d{3})\s/', $respHeaders['_http'], $m)) {
                    $httpCode = (int)$m[1];
                }
                if ($httpCode !== 200 && $httpCode !== 206) {
                    http_response_code(502);
                    exit('upstream error: ' . $httpCode);
                }
                // 这一段头只用来描述「紧接着要发出去的那段字节」，所以状态码与长度信息一律以上游**实际**
                // 响应为准：上游可能完全不理会我们发的 Range（回 200 整文件），也可能自己更窄地切一段。
                // 若还按客户端请求的区间去拼 Content-Range / Content-Length，声明与 body 就对不上 ——
                // 浏览器按字节比例换算播放位置，进度就会从一个错误的位置开始（内容也可能整段错位）。
                http_response_code($httpCode);
                if ($httpCode === 206 && !empty($respHeaders['content-range'])) {
                    header('Content-Range: ' . $respHeaders['content-range']);   // 原样透传，不自己算
                }
                // 上游给了长度才声明长度：宁可不声明（由连接关闭定界），也不能发一个对不上的长度
                if (isset($respHeaders['content-length']) && ctype_digit($respHeaders['content-length'])) {
                    header('Content-Length: ' . $respHeaders['content-length']);
                }
                header('Content-Type: ' . ($respHeaders['content-type'] ?? 'audio/mpeg'));
                header('Cache-Control: no-cache, no-store, must-revalidate');
                header('Pragma: no-cache');
                header('Expires: 0');
                header('Accept-Ranges: bytes');
            }
            echo $data;
            flush();
            return strlen($data);
        },
    ]);

    // 客户端要哪一段就向上游要哪一段；至于上游给不给、给多宽，看它自己（响应头以上游实际为准）
    if ($isRange) {
        curl_setopt($ch, CURLOPT_RANGE, $rangeOffset . '-' . ($rangeEnd > 0 ? $rangeEnd : ''));
    }

    curl_exec($ch);
    exit;
}