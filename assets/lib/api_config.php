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
 * 通用分段读取器：`mapi_config` 表里 `config_key=$key` 那行（JSON）。
 *
 * 为什么要有这个通用版：`site` / `admin` / `api_secrets` 这些段的读取逻辑
 * **完全一样**（查一行、解 JSON、坏数据当没有）。逐段各写一遍 = N 份同样的代码，
 * 将来改一处忘其余。所以收敛成一个；**只负责读**，不合并、不校验、不带默认值。
 *
 * 读不到（库为空/行不存在/JSON 坏了/连不上）一律返回 `[]` ⇒ 调用方**回落 `config.php`**。
 */
function read_mapi_config_section($db, string $key): array
{
    if (!$db) return [];
    // 键名来自代码内常量（非用户输入）；仍做白名单式校验，杜绝任何拼接注入的可能
    if (!preg_match('/^[a-z_][a-z0-9_]*$/', $key)) return [];
    $r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='" . $key . "'");
    if (!$r) return [];
    $row = $r->fetch_assoc();
    if (!$row || !isset($row['config_value']) || $row['config_value'] === '') return [];
    $saved = json_decode((string)$row['config_value'], true);
    return is_array($saved) ? $saved : [];
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


/**
 * 把某一分段配置**读-改-写**地保存进 `mapi_config`（MySQL / SQLite 双驱动）。
 *
 * ★ 为什么必须是"读-改-写"：这一行是**整套字段共用一个 JSON**。若调用方只提交自己表单里的
 *   几个字段就整行 REPLACE，**没提交的字段会被清空** —— 这正是本会话早先那次事故的形状
 *   （保存 WS 地址时把上游音频 API 那行清空）。
 *   所以：先读出现有值 → 只覆盖**本次明确给出的**键 → 整行写回。
 *   `$patch` 里：键**不存在**或值为 `null` = 不动该键；值为空串 = **显式清空**（= 回落 config.php）。
 *
 * 通用版：`api_secrets` / `site` / `admin` / `smtp` / `s3` 等任何分段都走它，
 * 避免每个分段一份读-改-写。
 *
 * ⚠ 2026-10-20：本函数曾在清理歌厅代码时被误删（连带 WebSocket 专用函数一起切掉），
 *   导致保存 SMTP / S3 / 音乐 API 时 `Call to undefined function`。已按原样恢复。
 *
 * @param array $patch 形如 ['key'=>?string, ...]
 * @return array{ok:bool, error:string}
 */
function save_mapi_config_section($db, string $key, array $patch): array
{
    if (!$db) return ['ok' => false, 'error' => '数据库不可用'];
    // 键名白名单：杜绝任何拼接（键来自代码内常量，不是用户输入）
    if (!preg_match('/^[a-z_][a-z0-9_]*$/', $key)) return ['ok' => false, 'error' => '非法配置键'];
    // 1) 读出现有（**保留未知键**，别把它们丢了）
    $cur = [];
    $r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='" . $key . "'");
    if ($r && $row = $r->fetch_assoc()) {
        $j = json_decode((string)$row['config_value'], true);
        if (is_array($j)) $cur = $j;
    }
    // 2) 只覆盖本次明确给出的键
    foreach ($patch as $k => $v) {
        if (!is_string($k) || $k === '') continue;
        if ($v === null) continue;                       // null = 不动
        // ⚠ 这里**不能**无脑 `(string)$v`：数组会被转成 "Array"，把整棵子树毁成 5 个字节。
        //   2026-10-16 真实事故：`mapi_api.req_params`/`fields` 就是这么变坏的
        //   （267→167、e775204458e8→3237607356df，不可逆）。数组**原样保留** ⇒ json_encode 出对象。
        $cur[$k] = is_array($v) ? $v : (string)$v;
    }
    // 3) 整行写回
    $json = json_encode($cur, JSON_UNESCAPED_UNICODE);
    if ($json === false) return ['ok' => false, 'error' => 'JSON 编码失败'];
    $st = $db->prepare("REPLACE INTO mapi_config (config_key, config_value) VALUES (?, ?)");
    if (!$st) return ['ok' => false, 'error' => '预处理失败'];
    $st->bind_param('ss', $key, $json);
    if (!$st->execute()) return ['ok' => false, 'error' => '写入失败'];
    return ['ok' => true, 'error' => ''];
}

/**
 * 从 `$_POST` 按**字段存在性**生成 patch，再交给 `save_mapi_config_section()` 落库。
 *
 * 为什么必须收成这一个函数（这是本会话第三次同形事故后的收口）：
 *   同一个坑已经踩过三次，形状完全一样 —— **拿请求里的部分字段拼出一整行，再 REPLACE**：
 *     · `mapi_api` 整行被清成默认值（`_api_submit=1` 但不带音乐 API 字段）
 *     · 更早那次"上游音频 API 地址被清空"
 *   三次的根因不是"某一处写错了"，而是**这种写法在设置 handler 里成片存在** ⇒
 *   光修点没用，必须把"怎么从表单生成 patch"收成一个共享函数，各处只声明**字段映射**。
 *
 * 语义（两句话，与 `save_mapi_config_section()` 对齐）：
 *   · **POST 键不在请求里 ⇒ 该键不进 patch ⇒ 库里保持原值**（不误伤别的卡片/脚本/旧表单）
 *   · **POST 键在请求里 ⇒ 写入该值**，空串就是**显式清空**（"留空=清空并回落"是文档承诺的功能）
 *
 * `$map` 支持三种写法：
 *   1. `'存储键' => 'post_字段名'`                        —— 纯字符串
 *   2. `'存储键' => [['post_a','别名'], ['post_b'], ...]` —— 嵌套对象：**按存在性逐子键合并**
 *      （子键的存储名 = POST 字段名的 `post_` 前缀换成 `子键_`；`['post_a','别名']` 可显式指定存储名）
 *   3. `'存储键' => ['post_a' => ?, ...]`                 —— 同 2 的简写
 *
 * 为什么嵌套要**逐子键**而不是整块覆盖：`req_params` / `fields` 这种对象，
 * 整块赋值会把"本次没提交的子键"一起抹掉 —— 那正是要根除的形状。
 *
 * @param mixed  $db
 * @param string $key   分段键（= 库里的 config_key）
 * @param array  $map   字段映射（形如 ['meting'=>'api_meting', 'req_params'=>[...]]）
 * @param array  $opts  ['transform'=>[post字段=>callable|null],  // null 返回 = 不进 patch（如掩码未改）
 *                       'defaults'=>['存储键'=>值],             // 键**在**请求里但为空串时用它，而不是清空
 *                       'required'=>['存储键', ...]]            // 这些键不存在就新建为默认值（首装可用）
 * @return array{ok:bool, error:string, patch:array, present:array}
 */
if (!function_exists('self_nested_spec')) {
    /**
     * 判定一个 `$map` 值是"标量键"还是"嵌套对象"，并把后者**归一化**成
     * `['子存储键' => 'POST 字段名']`。返回 `null` = 标量键。
     *
     * 为什么要单独一个函数：调用方有**两种**嵌套写法，必须在**一处**收敛 ——
     * 2026-10-16 的事故根因就是"两种写法在同一处 if/else"里被混用，子数组落进标量分支。
     *   - `['server' => 'api_req_server']`      ⇒ 显式子存储键
     *   - `['api_req_server']`                   ⇒ 子存储键 = POST 字段名
     *   - `[[ 'api_req_server', 'server' ]]`     ⇒ `[POST 字段, 子存储键]`
     *   - `['server' => ['api_req_server']]`     ⇒ 同上（数组形式）
     *
     * ⚠ 关键：**空数组也算嵌套**（表示"该子树本次无子键"），绝不能落进标量分支 ——
     *   那正是把数组写成字符串 `"Array"` 的入口。
     */
    function self_nested_spec($spec): ?array
    {
        if (is_string($spec)) return null;          // 标量键
        if (!is_array($spec)) return [];            // 异常类型 ⇒ 当空嵌套，不写标量

        $out = [];
        foreach ($spec as $k => $v) {
            if (is_array($v)) {                     // ['server'=>['api_req_server']] / [0=>['api_req_server','server']]
                $postField = (string)($v[0] ?? '');
                $subStore  = (string)($v[1] ?? (is_string($k) ? $k : $postField));
            } elseif (is_string($k)) {              // ['server' => 'api_req_server']
                $postField = (string)$v;
                $subStore  = (string)$k;
            } else {                                // ['api_req_server']
                $postField = (string)$v;
                $subStore  = (string)$v;
            }
            if ($postField === '') continue;
            $out[$subStore] = $postField;
        }
        return $out;
    }
}
if (!function_exists('merge_mapi_config_from_post')) {
    function merge_mapi_config_from_post($db, string $key, array $map, array $opts = []): array
    {
        $transform = $opts['transform'] ?? [];
        $defaults  = $opts['defaults']  ?? [];
        $required  = $opts['required']  ?? [];

        $patch   = [];
        $present = [];

        // 现状读**一次**就复用（`required` 与嵌套合并都要用；避免同一行被反复查库）
        $current = read_mapi_config_section($db, $key);
        if (!is_array($current)) $current = [];

        // ① `required` 的键若库里还没有，就先用默认值补上（首装路径）
        foreach ($required as $rk) {
            if (!array_key_exists($rk, $current) && array_key_exists($rk, $defaults)) {
                $patch[$rk] = $defaults[$rk];
                $present[]  = $rk;
            }
        }

        // ② 按存在性生成 patch
        foreach ($map as $storeKey => $spec) {
            $subSpecs = self_nested_spec($spec);          // null=标量键；array=嵌套对象
            if ($subSpecs === null) {                     // 纯标量字段
                if (!array_key_exists($spec, $_POST)) continue;
                $val = trim((string)$_POST[$spec]);
                if (array_key_exists($storeKey, $transform)) {
                    $val = $transform[$storeKey]($val);
                    if ($val === null) continue;          // 明确"不动"（掩码/未改）
                }
                if ($val === '' && array_key_exists($storeKey, $defaults)) {
                    $val = (string)$defaults[$storeKey];  // 空串 = 用默认值（不是清空）
                }
                // ★ 存储形状保护：库里的键是**数组**，而本次只提交了标量 ⇒ 拒写。
                //   否则 `(string)array` 会写出字符串 "Array"，把整棵子树毁成 5 个字节
                //   且不可逆（2026-10-16 真实事故：mapi_api 267→167 / e775204458e8→3237607356df）。
                //   正常调用方不会走到这里：数组键必须传数组 spec（下面的分支）。
                if (isset($current[$storeKey]) && is_array($current[$storeKey])) {
                    continue;
                }
                $patch[$storeKey] = $val;
                $present[] = $storeKey;
                continue;
            }

            // 嵌套对象：逐子键按存在性合并（`$subSpecs` = ['子存储键' => 'POST 字段名']）
            $subPatch = [];
            foreach ($subSpecs as $subStore => $postField) {
                if ($postField === '' || !array_key_exists($postField, $_POST)) continue;
                $val = trim((string)$_POST[$postField]);
                $flat = $storeKey . '.' . $subStore;
                if (array_key_exists($flat, $transform)) {
                    $val = $transform[$flat]($val);
                    if ($val === null) continue;
                }
                if ($val === '' && array_key_exists($flat, $defaults)) $val = (string)$defaults[$flat];
                $subPatch[$subStore] = $val;
                $present[] = $flat;
            }
            if ($subPatch) {
                // ★ 逐子键合并：先取库里现有子键，再覆盖本次提交的 —— 绝不整块替换。
                //   更要紧的是**保住数组类型**：整棵子树始终是 PHP 数组 ⇒ json_encode 出对象，
                //   绝不会退化成字符串 "Array"（见上面标量分支的同款保护注释）。
                $merged = (isset($current[$storeKey]) && is_array($current[$storeKey])) ? $current[$storeKey] : [];
                $patch[$storeKey] = array_merge($merged, $subPatch);
            }
        }

        $res = save_mapi_config_section($db, $key, $patch);
        $res['patch']   = $patch;
        $res['present'] = $present;
        return $res;
    }
}