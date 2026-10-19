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

/**
 * JWT 签名密钥 —— 全站唯一来源。
 *
 * 只认 config/config.php 的 api.jwt_secret，且要求 ≥32 字符。
 * 缺失或过短时**抛异常**，绝不回退到 hash('sha256', db_password . site_url)：
 * 那种回退把签名密钥的熵压缩成数据库密码的熵 —— 拖库即可伪造任意 token。
 *
 * 生成方式：php -r "echo bin2hex(random_bytes(32));"
 * 安装器（install/handlers/init_mysql.php / init_sqlite.php）会自动生成。
 *
 * @param array|null $cfg 已加载的配置数组；不传则自行 require config/config.php
 */
if (!function_exists('jwt_secret')) {
    function jwt_secret(?array $cfg = null): string
    {
        static $cached = null;

        // ⚠ 缓存只有在"没人给我 $cfg"时才无条件复用。
        //   为什么：`api.jwt_secret` 现在可以从**数据库**来（`mapi_app_config()` 合并进 `$cfg['api']`）。
        //   若某个调用方先无参调用（读文件）、另一个调用方后来传了**合并后**的 $cfg，
        //   无脑复用缓存就会让后者拿到**文件里的旧密钥** —— 表现为"签名/校验不一致"的诡异故障。
        //   所以：传了 $cfg 就以它为准（只把它当缓存，不当权威）。
        if ($cfg === null) {
            if ($cached !== null) return $cached;
            $cfgFile = dirname(__DIR__, 2) . '/config/config.php';
            $cfg = is_file($cfgFile) ? require $cfgFile : [];
            if (!is_array($cfg)) $cfg = [];
            // 无参调用时也尽量走唯一收口点（能连上库就拿到合并后的值）
            if (function_exists('mapi_app_config') && function_exists('db_connect')) {
                $db = @db_connect();
                if ($db) $cfg = mapi_app_config($cfg, $db);
            }
        }

        $secret = trim((string)($cfg['api']['jwt_secret'] ?? ''));
        if (strlen($secret) < 32) {
            throw new RuntimeException(
                'api.jwt_secret 缺失或过短（需 ≥32 字符，当前 ' . strlen($secret) . ' 个）。'
                . '生成命令：php -r "echo bin2hex(random_bytes(32));"'
                . ' —— 它现在来自数据库 `mapi_config` 的 `api_secrets` 行（或 config/config.php 的 api 段）。'
                . ' —— 拒绝回退到由数据库密码派生的弱密钥。'
            );
        }
        return $cached = $secret;
    }
}

/**
 * 取签名密钥；**取不到时给"明确可读的失败"，绝不抛未捕获异常**。
 *
 * 为什么要这个包装（背景，别简化掉）：
 *   `api.jwt_secret` 现在只从数据库来（`mapi_config.api_secrets`）。config.php 里原来那份
 *   `api` 段已按用户批准删除 ⇒ **库连不上时这些值没有任何来源**。
 *   若直接调 `jwt_secret($cfg)`，它会抛 `RuntimeException` ⇒ 五个入口全部 **500 白屏**，
 *   用户只看到"服务器错误"。用户已明确要求：库不可达时给 **503 + 一句人话**，
 *   不要 500、不要白屏、不要未捕获异常。
 *
 * 行为：
 *   ① 正常：返回密钥（库优先，config.php 兜底）。
 *   ② 入参里没有密钥，但**库其实可用** ⇒ 改走无参 `jwt_secret()`（它自己连库取合并后的值）。
 *      这一条是为了救"调用方只传了文件配置、没合并"的旧调用点（例如 `$cfg` 是别处传来的局部变量）。
 *   ③ 两者都拿不到（真·库不可达）⇒ 发 **503 + 中文说明** 并 `exit`。CLI 环境改成 stderr + exit(1)。
 *
 * @param array|null $cfg 已加载/已合并的配置
 * @param string     $format 失败时的响应格式：`text`（默认，HTML 页面用）或 `json`（API 用）
 */
if (!function_exists('jwt_secret_or_503')) {
    function jwt_secret_or_503(?array $cfg = null, string $format = 'text'): string
    {
        try {
            $s = jwt_secret($cfg);
            if ($s !== '') return $s;
        } catch (Throwable $e) {
            // 落到下面尝试无参兜底
        }
        // ② 无参兜底：只有"库可用"时才可能成功（它会自己 db_connect + mapi_app_config 合并）
        try {
            $s = jwt_secret();
            if ($s !== '') return $s;
        } catch (Throwable $e) {
            // 真拿不到 ⇒ 走 ③
        }

        // ③ 明确失败
        $msg = '数据库连接不可用，暂时无法生成访问凭据，请稍后重试。'
             . '（原因：签名密钥存放于数据库 `mapi_config.api_secrets`，库连不上时无法取得）';
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $msg . "\n");
            exit(1);
        }
        if (!headers_sent()) {
            header('Retry-After: 30');
            header($format === 'json'
                ? 'Content-Type: application/json; charset=utf-8'
                : 'Content-Type: text/plain; charset=utf-8', true, 503);
        }
        http_response_code(503);
        // API 侧保持 JSON 契约（调用方按 JSON 解析），页面侧给人话纯文本
        echo $format === 'json'
            ? json_encode(['ok' => false, 'error' => $msg, 'reason' => 'db_unavailable'], JSON_UNESCAPED_UNICODE)
            : $msg;
        exit;
    }
}