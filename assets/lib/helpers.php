<?php

// 共享工具函数库

// 文件限流检查 — 基于临时文件的轻量限流
function rate_limit_check(string $prefix, int $max_requests, int $window_seconds): void
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $rlFile = sys_get_temp_dir() . '/mapi_rl_' . md5($ip . $prefix);

    $rlCount = 0;
    $rlTime  = time();
    $rlData  = @file_get_contents($rlFile);

    if ($rlData !== false) {
        $parts = explode('|', $rlData);
        $rlCount = (int)($parts[0] ?? 0);
        $rlTime  = (int)($parts[1] ?? time());
        if (time() - $rlTime > $window_seconds) {
            $rlCount = 0;
        }
    }

    $rlCount++;
    @file_put_contents($rlFile, $rlCount . '|' . time(), LOCK_EX);

    if ($rlCount > $max_requests) {
        http_response_code(429);
        // code 供播放器端给出「请求太频繁」这条独立提示（而不是笼统的“密钥无效”）
        die(json_encode(['error' => '请求过于频繁，请稍后再试', 'code' => 'rate_limited', 'http' => 429], JSON_UNESCAPED_UNICODE));
    }
}

// 登录限流 — 独立于通用限流, 窗口更长
function login_rate_limit(): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $f = sys_get_temp_dir() . '/mapi_rl_' . md5($ip);

    $c = 0;
    $d = @file_get_contents($f);
    if ($d !== false) {
        [$c, $tm] = explode('|', $d);
        if (time() - (int)$tm > 120) $c = 0;
    }

    $c = (int)$c + 1;
    @file_put_contents($f, $c . '|' . time(), LOCK_EX);

    if ($c > 30) {
        http_response_code(429);
        die('登录尝试过于频繁');
    }
}

// HTTP GET 请求 — 使用 cURL
function http_get(string $url, string $ua, string $referer): ?string
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_REFERER        => $referer,
        CURLOPT_TCP_NODELAY    => true,
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return ($httpCode === 200 && is_string($raw)) ? $raw : null;
}

// 遵循重定向获取最终 URL
function resolve_final_url(string $url, string $ua, string $referer): string
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_REFERER        => $referer,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_NOBODY         => true,
    ]);
    curl_exec($ch);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    return $finalUrl ?: $url;
}

// 解析播放地址 (获取重定向 URL)
function resolve_play_url(string $id, string $apiBase, string $ua, string $qqRef, string $server, string $rServer, string $rType, string $rId): string
{
    $url = Uri\Rfc3986\Uri::parse($apiBase)
        ->withQuery(http_build_query([$rServer => $server, $rType => 'url', $rId => $id]))
        ->toString();

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_REFERER        => $qqRef,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => true,
        CURLOPT_NOBODY         => false,
        CURLOPT_TCP_NODELAY    => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $redirectUrl = '';
    if ($httpCode === 302 && is_string($response)) {
        preg_match('/^Location:\s+(.+)/im', $response, $m);
        $redirectUrl = trim($m[1] ?? '');
    } elseif ($httpCode === 200 && is_string($response)) {
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = substr($response, $headerSize);
        $json = json_decode($body, true);
        if (is_array($json) && !empty($json['url'])) {
            $redirectUrl = $json['url'];
        }
    }
    return $redirectUrl;
}

// 封面 URL 解析 — 相对路径转绝对路径
function resolve_cover_url(string $pic, string $apiBase, string $server, string $pId, string $rServer, string $rType): string
{
    if (!$pic) {
        return '';
    }
    if (preg_match('/^https?:\/\//', $pic)) {
        return $pic;
    }
    $picId = '';
    if (preg_match('/[?&]' . preg_quote($pId, '/') . '=([^&]+)/', $pic, $m)) {
        $picId = $m[1];
    }
    if ($picId && $apiBase) {
        return $apiBase . '?' . http_build_query([$rServer => $server, $rType => 'pic', $pId => $picId]);
    }
    return '';
}

// JSON 输出并退出
#[NoReturn]
function json_ok(mixed $data, string $action = '', string $id = '', string $apiKey = '', $dbLog = null): never
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo $json;

    if ($dbLog && !$dbLog->connect_error) {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
        $ref = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
        $ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $dbLog->prepare("INSERT INTO mapi_logs (ip, referer, endpoint, user_agent, api_key, traffic_bytes) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $endpoint = $action . ($id ? ':' . $id : '');
            $size = strlen($json);
            $stmt->bind_param('sssssi', $ip, $ref, $endpoint, $ua, $apiKey, $size);
            $stmt->execute();
            $stmt->close();
        }
    }
    exit;
}

// 给本地静态资源追加修改时间做版本号（前后台共用）：页面/样式/脚本变更后浏览器自动拿到新文件
if (!function_exists('asset_ver')) {
    function asset_ver(string $path): string
    {
        if ($path === '' || strpos($path, '://') !== false) return $path;
        $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
        if ($docRoot === '' || $path[0] !== '/') return $path;
        $local = $docRoot . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (!is_file($local)) return $path;
        return $path . (strpos($path, '?') === false ? '?' : '&') . 'v=' . filemtime($local);
    }
}