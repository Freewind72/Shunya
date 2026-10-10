<?php

// API 配置读取器 — 从数据库 mapi_config 表统一读取 MAPI API 配置
function read_mapi_api_config($db, array $CFG): array
{
    $config = [
        'base_url'     => $CFG['api']['base_url']      ?? '',
        'qq_referer'   => $CFG['api']['qq_referer']    ?? '',
        'qq_cover'     => $CFG['api']['qq_cover']      ?? '',
        'field_title'  => $CFG['api']['field_title']   ?? 'title',
        'field_artist' => $CFG['api']['field_artist']  ?? 'author',
        'field_url'    => $CFG['api']['field_url']     ?? 'url',
        'field_pic'    => $CFG['api']['field_pic']     ?? 'pic',
        'field_lrc'    => $CFG['api']['field_lrc']     ?? 'lrc',
        'param_id'     => $CFG['api']['param_id']      ?? 'id',
        'param_auth'   => $CFG['api']['param_auth']    ?? 'auth',
        'param_server' => $CFG['api']['param_server']  ?? 'server',
        'param_type'   => $CFG['api']['param_type']    ?? 'type',
    ];

    if (!$db) {
        return $config;
    }

    $r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='mapi_api'");
    if (!$r) {
        return $config;
    }

    $row = $r->fetch_assoc();
    if (!$row || !$row['config_value']) {
        return $config;
    }

    $saved = json_decode($row['config_value'], true);
    if (!is_array($saved)) {
        return $config;
    }

    if (!empty($saved['meting']))   $config['base_url']   = $saved['meting'];
    if (!empty($saved['qq_referer'])) $config['qq_referer'] = $saved['qq_referer'];
    if (!empty($saved['qq_cover'])) $config['qq_cover']   = $saved['qq_cover'];
    if (!empty($saved['param_id'])) $config['param_id']   = $saved['param_id'];
    if (!empty($saved['param_auth'])) $config['param_auth'] = $saved['param_auth'];

    if (!empty($saved['req_params']) && is_array($saved['req_params'])) {
        if (!empty($saved['req_params']['server'])) $config['param_server'] = $saved['req_params']['server'];
        if (!empty($saved['req_params']['type']))   $config['param_type']   = $saved['req_params']['type'];
    }

    if (!empty($saved['fields']) && is_array($saved['fields'])) {
        if (!empty($saved['fields']['title']))  $config['field_title']  = $saved['fields']['title'];
        if (!empty($saved['fields']['artist'])) $config['field_artist'] = $saved['fields']['artist'];
        if (!empty($saved['fields']['url']))    $config['field_url']    = $saved['fields']['url'];
        if (!empty($saved['fields']['pic']))    $config['field_pic']    = $saved['fields']['pic'];
        if (!empty($saved['fields']['lrc']))    $config['field_lrc']    = $saved['fields']['lrc'];
    }

    return $config;
}

/**
 * Redis 缓存配置读取器 —— mapi_config 表里 config_key='redis' 那行（JSON）。
 * 只负责"读出来"，默认值与类型收敛在 admin/lib/redis.php 的 redis_conf() 里做，
 * 避免同一个配置在两处各有一套默认值。
 * 读不到（表/行不存在、JSON 坏了、库连不上）一律返回空数组 → 调用方回落默认值（关闭缓存）。
 */
function read_mapi_redis_config($db): array
{
    if (!$db) return [];
    $r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='redis'");
    if (!$r) return [];
    $row = $r->fetch_assoc();
    if (!$row || !isset($row['config_value']) || $row['config_value'] === '') return [];
    $saved = json_decode((string)$row['config_value'], true);
    return is_array($saved) ? $saved : [];
}