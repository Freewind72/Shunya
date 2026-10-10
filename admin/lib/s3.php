<?php defined('MAPI_ADMIN') or defined('MAPI_PLAYER_API') or die('禁止直接访问');
// MAPI_ADMIN：后台页面 / handler 入口；MAPI_PLAYER_API：播放器 api.php（要拼封面 S3 直链）

function s3_config(): array {
    // 连接变量名在两个入口不一样：后台是 $db，播放器网关 api.php 是 $db_log。
    // 只认 $db 的话，网关里 s3_config() 会拿到 null 直接崩（Call to a member function query() on null）。
    global $db, $db_log;
    $conn = $db ?: $db_log;
    if (!$conn) return ['endpoint' => '', 'access_key' => '', 'secret_key' => '', 'bucket' => '', 'region' => 'auto', 'path_prefix' => '', 'custom_domain' => ''];
    $r = $conn->query("SELECT config_value FROM mapi_config WHERE config_key='s3'");
    if ($r && $row = $r->fetch_assoc()) {
        $saved = json_decode($row['config_value'], true);
        if (is_array($saved)) {
            return [
                'endpoint' => $saved['endpoint'] ?? '',
                'access_key' => $saved['access_key'] ?? '',
                'secret_key' => $saved['secret_key'] ?? '',
                'bucket' => $saved['bucket'] ?? '',
                'region' => $saved['region'] ?? 'auto',
                'path_prefix' => $saved['path_prefix'] ?? '',
                'custom_domain' => $saved['custom_domain'] ?? '',
            ];
        }
    }
    return [
        'endpoint' => '',
        'access_key' => '',
        'secret_key' => '',
        'bucket' => '',
        'region' => 'auto',
        'path_prefix' => '',
        'custom_domain' => '',
    ];
}

function s3_available(): bool {
    $cfg = s3_config();
    return !empty($cfg['endpoint']) && !empty($cfg['bucket']) && !empty($cfg['access_key']) && !empty($cfg['secret_key']);
}

function s3_full_key(string $key): string {
    $cfg = s3_config();
    $prefix = trim($cfg['path_prefix'] ?? '', '/');
    return $prefix ? $prefix . '/' . ltrim($key, '/') : $key;
}

function s3_get_url(string $key): string {
    $cfg = s3_config();
    if (!empty($cfg['custom_domain'])) {
        return rtrim($cfg['custom_domain'], '/') . '/' . ltrim(s3_full_key($key), '/');
    }
    return rtrim($cfg['endpoint'], '/') . '/' . $cfg['bucket'] . '/' . ltrim(s3_full_key($key), '/');
}

/**
 * 参与签名的头名（含 host，按字母序）。
 * Authorization 里的 SignedHeaders 必须与 s3_sign() 内部实际使用的那份**完全一致**：
 * 少一个 → 上游报 "There were headers present in the request which were not signed"（AccessDenied）。
 * 三个调用点（s3_put / s3_delete）必须与 s3_sign() 内部那份**完全一致**，否则上游报
 * "There were headers present in the request which were not signed"（AccessDenied 400）。
 * 这里曾经写成 implode(';', array_keys(array_change_key_case($headers)))：名字对了，但**没排序**
 * （host 在最前），而 s3_sign() 内部是 ksort 过的 —— 两边不一致，服务端 PUT / DELETE 一直失败。
 */
function s3_signed_header_names(array $headers): array {
    $names = [];
    foreach ($headers as $k => $v) $names[strtolower($k)] = true;
    $names['host'] = true;
    $names = array_keys($names);
    sort($names);
    return $names;
}

function s3_sign(string $method, string $uri, string $body, array $headers, string $date): string {
    $cfg = s3_config();
    $region = $cfg['region'];
    $service = 's3';
    $scope = "$date/$region/$service/aws4_request";
    $signed = [];
    foreach ($headers as $k => $v) $signed[strtolower($k)] = trim($v);
    $signed['host'] = Uri\Rfc3986\Uri::parse($cfg['endpoint'])->getHost();
    ksort($signed);
    $ch = '';
    foreach ($signed as $k => $v) $ch .= "$k:$v\n";
    $signedHeaderNames = implode(';', array_keys($signed));
    // 方法必须**原样大写**：规范请求里的方法要和线上请求行一致（PUT / DELETE / GET）。
    // 这里曾经写成 strtolower($method)，把 "PUT" 签成了 "put"，而请求行发的是大写 ——
    // 两边对不上，签名永远校验不过（SignatureDoesNotMatch 403），且和 region 无关。
    $canonicalRequest = strtoupper($method) . "\n" . ($uri ?: '/') . "\n" . '' . "\n$ch\n" . $signedHeaderNames . "\n" . hash('sha256', $body);
    $stringToSign = "AWS4-HMAC-SHA256\n" . ($signed['x-amz-date'] ?? '') . "\n$scope\n" . hash('sha256', $canonicalRequest);
    $kDate = hash_hmac('sha256', $date, 'AWS4' . $cfg['secret_key'], true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    return hash_hmac('sha256', $stringToSign, $kSigning);
}

/**
 * 上传对象。
 * $cache_control 建议传：内容寻址的 key（封面 covers/<sha>.jpg）永不改内容，
 * 带上 `public, max-age=31536000, immutable` 才能让浏览器/CDN 长期命中、不再回源 ——
 * 不带的话每次打开页面都要重新协商一遍，封面自然"慢"。
 */
function s3_put(string $key, string $data, string $content_type = 'application/octet-stream', string $cache_control = ''): bool {
    $cfg = s3_config();
    if (!s3_available()) return false;
    $date = gmdate('Ymd');
    $ts = gmdate('Ymd\THis\Z');
    $payload_hash = hash('sha256', $data);
    $headers = [
        'Host' => Uri\Rfc3986\Uri::parse($cfg['endpoint'])->getHost(),
        'Content-Type' => $content_type,
        'x-amz-content-sha256' => $payload_hash,
        'x-amz-date' => $ts,
    ];
    // 参与签名的头由 s3_signed_header_names() 从 $headers 现算，这里加头会自动被签进去
    if ($cache_control !== '') $headers['Cache-Control'] = $cache_control;
    $sig = s3_sign('PUT', '/' . $cfg['bucket'] . '/' . s3_full_key($key), $data, $headers, $date);
    $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$cfg['access_key']}/$date/{$cfg['region']}/s3/aws4_request, SignedHeaders=" . implode(';', s3_signed_header_names($headers)) . ", Signature=$sig";
    $h = [];
    foreach ($headers as $k => $v) $h[] = "$k: $v";
    $ctx = stream_context_create(['http' => [
        'method' => 'PUT',
        'header' => implode("\r\n", $h),
        'content' => $data,
        'ignore_errors' => true,
    ]]);
    $r = @file_get_contents($cfg['endpoint'] . '/' . $cfg['bucket'] . '/' . s3_full_key($key), false, $ctx);
    if ($r === false) return false;
    return !str_contains($http_response_header[0] ?? '', ' 40');
}

function s3_delete(string $key): bool {
    $cfg = s3_config();
    if (!s3_available()) return false;
    $date = gmdate('Ymd');
    $ts = gmdate('Ymd\THis\Z');
    $payload_hash = hash('sha256', '');
    $headers = [
        'Host' => Uri\Rfc3986\Uri::parse($cfg['endpoint'])->getHost(),
        'x-amz-content-sha256' => $payload_hash,
        'x-amz-date' => $ts,
    ];
    $sig = s3_sign('DELETE', '/' . $cfg['bucket'] . '/' . s3_full_key($key), '', $headers, $date);
    $headers['Authorization'] = "AWS4-HMAC-SHA256 Credential={$cfg['access_key']}/$date/{$cfg['region']}/s3/aws4_request, SignedHeaders=" . implode(';', s3_signed_header_names($headers)) . ", Signature=$sig";
    $h = [];
    foreach ($headers as $k => $v) $h[] = "$k: $v";
    $ctx = stream_context_create(['http' => [
        'method' => 'DELETE',
        'header' => implode("\r\n", $h),
        'ignore_errors' => true,
    ]]);
    $r = @file_get_contents($cfg['endpoint'] . '/' . $cfg['bucket'] . '/' . s3_full_key($key), false, $ctx);
    if ($r === false) return false;
    return !str_contains($http_response_header[0] ?? '', ' 40');
}

function s3_presigned_put_url(string $key, string $content_type = 'application/octet-stream', int $expires = 300): array {
    $cfg = s3_config();
    if (!s3_available()) return ['ok' => false, 'error' => 'S3 未配置'];
    $key = s3_full_key($key);

    $endpoint = rtrim($cfg['endpoint'], '/');
    $host = Uri\Rfc3986\Uri::parse($endpoint)->getHost();
    $region = $cfg['region'];
    $service = 's3';
    $algorithm = 'AWS4-HMAC-SHA256';

    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $credentialScope = "$dateStamp/$region/$service/aws4_request";

    $canonicalUri = '/' . $cfg['bucket'] . '/' . $key;
    $canonicalQuerystring = http_build_query([
        'X-Amz-Algorithm' => $algorithm,
        'X-Amz-Credential' => $cfg['access_key'] . '/' . $credentialScope,
        'X-Amz-Date' => $amzDate,
        'X-Amz-Expires' => $expires,
        'X-Amz-SignedHeaders' => 'host',
    ]);

    $canonicalHeaders = "host:$host\n";
    $signedHeaders = 'host';

    $payloadHash = 'UNSIGNED-PAYLOAD';

    $canonicalRequest = "PUT\n$canonicalUri\n$canonicalQuerystring\n$canonicalHeaders\n$signedHeaders\n$payloadHash";

    $stringToSign = "$algorithm\n$amzDate\n$credentialScope\n" . hash('sha256', $canonicalRequest);

    $signingKey = hash_hmac('sha256', 'aws4_request',
        hash_hmac('sha256', $service,
            hash_hmac('sha256', $region,
                hash_hmac('sha256', $dateStamp, 'AWS4' . $cfg['secret_key'], true),
            true),
        true),
    true);

    $signature = hash_hmac('sha256', $stringToSign, $signingKey);

    $url = "$endpoint/{$cfg['bucket']}/$key?$canonicalQuerystring&X-Amz-Signature=$signature";

    return ['ok' => true, 'url' => $url];
}