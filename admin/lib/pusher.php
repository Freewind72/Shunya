<?php defined('MAPI_ADMIN') or die('禁止直接访问');

require __DIR__ . '/../api/relay.php';

// Pusher 凭据的来源，优先级：config/config.php 的 pusher 段 > 数据库 mapi_config.pusher 行。
// 刻意**不留硬编码默认值** —— 那等于把应用密钥（secret 可用来向该应用发事件、读频道数据）
// 抄进了仓库，任何拿到代码的人都能滥用。留空时下面 pusher_enabled() 为假，相关功能自动静默停用。
// config/config.php 已被 .gitignore 忽略，是本站点放这类密钥的地方。
function _pusher_config(): array {
    global $db, $cfg;
    $out = ['app_id' => '', 'key' => '', 'secret' => '', 'cluster' => '', 'channel' => ''];

    $src = [];
    if (is_array($cfg['pusher'] ?? null)) $src = $cfg['pusher'];
    if ($db && empty($db->connect_error)) {
        $r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='pusher'");
        if ($r && $row = $r->fetch_assoc()) {
            $saved = json_decode($row['config_value'], true);
            if (is_array($saved)) $src = array_merge($src, $saved);
        }
    }
    foreach ($out as $k => $_) {
        if (!empty($src[$k])) $out[$k] = (string)$src[$k];
    }
    return $out;
}

// 只取一次：以前 5 个 define 各查一次库，等于每个后台请求白跑 5 条 SQL。
$_pusherCfg = _pusher_config();

define('PUSHER_APP_ID',  $_pusherCfg['app_id']);
define('PUSHER_KEY',     $_pusherCfg['key']);
define('PUSHER_SECRET',  $_pusherCfg['secret']);
define('PUSHER_CLUSTER', $_pusherCfg['cluster']);
define('PUSHER_CHANNEL', $_pusherCfg['channel']);

// 是否配好了 Pusher。没配时上游调用一律短路，避免空 app_id 拼出 /apps//events 这种请求。
function pusher_enabled(): bool {
    return PUSHER_APP_ID !== '' && PUSHER_KEY !== '' && PUSHER_SECRET !== '';
}

function pusher_trigger(string $channel, string $event, array $data): bool {
    global $RELAY;
    if (!pusher_enabled()) return false;
    $ch = curl_init($RELAY['api']['pusher_base'] . '/apps/' . PUSHER_APP_ID . '/events');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'name' => $event,
            'data' => json_encode($data),
            'channels' => [$channel],
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_USERPWD => PUSHER_KEY . ':' . PUSHER_SECRET,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return $http === 202;
}

// 生成 Presence Channel 鉴权签名
function pusher_auth(string $socket_id, string $channel_name, string $user_id, array $user_info = []): string {
    $channel_data = json_encode([
        'user_id' => $user_id,
        'user_info' => $user_info,
    ]);
    $string_to_sign = $socket_id . ':' . $channel_name . ':' . $channel_data;
    $signature = hash_hmac('sha256', $string_to_sign, PUSHER_SECRET);
    return json_encode([
        'auth' => PUSHER_KEY . ':' . $signature,
        'channel_data' => $channel_data,
    ]);
}

// 获取当前在线用户列表 (通过 Pusher HTTP API)
function pusher_online_users(): array {
    global $RELAY;
    if (!pusher_enabled()) return [];
    $ch = curl_init($RELAY['api']['pusher_base'] . '/apps/' . PUSHER_APP_ID . '/channels/' . PUSHER_CHANNEL . '/users');
    curl_setopt_array($ch, [
        CURLOPT_USERPWD => PUSHER_KEY . ':' . PUSHER_SECRET,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $res = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($http !== 200) return [];
    $data = json_decode($res, true);
    return $data['users'] ?? [];
}