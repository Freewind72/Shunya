<?php

/**
 * 应用配置的**唯一读取收口点** —— `mapi_app_config()`。
 *
 * ────────────────────────────────────────────────────────────────────────
 * 为什么要有这个文件
 * ────────────────────────────────────────────────────────────────────────
 * `config/config.php` 曾经是唯一配置源。用户要求把 `site` / `admin` / `api` 这些段
 * **搬进数据库**、并能在后台按功能分组修改。于是需要一个地方把
 * "文件 + 数据库"合成一份 `$cfg`，否则每个入口各写一套合并逻辑，迟早漂移。
 *
 * ────────────────────────────────────────────────────────────────────────
 * 铁律（改这个文件前先读一遍）
 * ────────────────────────────────────────────────────────────────────────
 * ① **`db` 段永远只来自 `config/config.php`** ✗ 绝不从数据库覆盖。
 *    读库得先有连接信息（鸡生蛋）⇒ 把 db 段搬进库 = 应用永远起不来。
 * ② **库不可达 ⇒ 完全回落文件**，一个字不少。`$db` 为空/不健康时本函数就是"原样返回文件配置"。
 *    绝不允许"库里没有就炸"（安全类密钥按既有口径单独处理：缺失即报错）。
 * ③ **逐段逐键覆盖，且只覆盖"非空"值**。空串 = 未配置 = 回落文件 ⇒ 老部署（未迁移）行为**完全不变**。
 * ④ **不建库连接、不读文件**。本函数是**纯函数**（只做数组合并）：
 *    `$base` 由调用方给（各入口自己已经 `require` 了 config.php），`$db` 由调用方给。
 *    这样它既能被 CLI 用、也能被 Web 用，且不会有"悄悄又连一个库"的副作用。
 *
 * ────────────────────────────────────────────────────────────────────────
 * 用法（三个入口 + 后台都走它）
 * ────────────────────────────────────────────────────────────────────────
 *     $base = require __DIR__ . '/config/config.php';
 *     $db   = db_connect();                       // 拿连接
 *     $cfg  = mapi_app_config($base, $db);        // ← 合并；$db 不可用就等价于 $base
 *
 * 合并的段落：site / admin / api / api_secrets
 * （`api` 段里的 `base_url`/`qq_referer`/`qq_cover` 另有 `mapi_api` 行这条既有链路，
 *   不在本函数里重复处理 —— 见 `admin/includes/bootstrap.php` 的 `read_mapi_api_config()`。）
 */

/** 参与"库覆盖文件"的配置段（`db` 段**不在其中**，见铁律 ①） */
const MAPI_APP_DB_SECTIONS = ['site', 'admin', 'api', 'api_secrets'];

/**
 * 把数据库里的分段配置覆盖到文件配置上。
 *
 * @param array $base 来自 `config/config.php` 的配置（可含 db 段）
 * @param mixed $db   数据库连接；为空/不健康时**原样返回** $base
 * @return array 合并后的配置
 */
function mapi_app_config(array $base, $db = null): array
{
    // 铁律 ②：库不可用 ⇒ 完全回落文件（这里就是"什么都不做"）
    if (!$db) return $base;

    // 连接对象可能"存在但连不上"（本项目 DB_Mysql 用 connect_error/_error 表达）。
    // ⚠ 两者命名都判：只要**任一非空**就当作"库不可用"⇒ 回落文件。
    //   为什么这么防御：本项目 `db_connect()` 在连不上时**仍会返回一个对象**（构造函数里 _connect() 失败
    //   只设 connect_error），所以"返回了对象"**不等于**"库可用"。只认一种字段名，将来改名就会静默失效。
    foreach (['connect_error', '_error'] as $ef) {
        $ev = $db->$ef ?? null;
        if ($ev !== null && $ev !== '' && $ev !== false) return $base;
    }

    // 读取器来自 assets/lib/api_config.php；未加载时**不致命**（回落文件即可）
    if (!function_exists('read_mapi_config_section')) return $base;

    foreach (MAPI_APP_DB_SECTIONS as $section) {
        $saved = read_mapi_config_section($db, $section);
        if (!$saved) continue;                       // 该行不存在 ⇒ 不回改，保持文件值

        // 铁律：**只覆盖非空值**。空串 = "明确未配置" ⇒ 保留文件里的值（而不是把它抹成空）。
        // 这样"后台把某项清空"的语义是"回落 config.php"，与既有 websocket 段完全一致。
        foreach ($saved as $k => $v) {
            if (!is_string($k) || $k === '') continue;
            if (is_array($v)) {
                // 嵌套段（如 websocket 将来加子项）：逐键合并，同样只覆盖非空
                if (!isset($base[$section][$k]) || !is_array($base[$section][$k])) $base[$section][$k] = [];
                foreach ($v as $k2 => $v2) {
                    if ($v2 === '' || $v2 === null) continue;
                    $base[$section][$k2] = $v2;
                }
                continue;
            }
            if ($v === '' || $v === null) continue;   // 空 = 未配置 ⇒ 回落文件
            $base[$section][$k] = $v;
        }
    }

    // ★ 兼容镜像：把 `api_secrets` 的两个密钥也放回 `api` 段。
    //   为什么需要（这是删掉 config.php 五段后的**必需**一步，不是可选）：
    //     库里把密钥放在独立的 `api_secrets` 行（与可编辑的 `api` 行物理隔离，防止整行 REPLACE 误删）；
    //     但既有代码仍在读 `$CFG['api']['jwt_secret'|'demo_key']`（`index.php` 的演示播放器就用后者，
    //     `helpers.php::jwt_secret()` 也是这个优先级）。
    //     若不镜像：删掉文件里的 api 段后，演示播放器会**静默消失**、显式传 $CFG 的调用会拿到空密钥。
    //   方向只有「api_secrets → api」一个：`api` 段由后台编辑，`api_secrets` 是权威副本。
    //   ⚠ 绝不反向（那会让后台改 api 行时把密钥写进可编辑行，正是我们要隔离的事故形状）。
    if (!empty($base['api_secrets']) && is_array($base['api_secrets'])) {
        if (!isset($base['api']) || !is_array($base['api'])) $base['api'] = [];
        foreach ($base['api_secrets'] as $k => $v) {
            if ($v === '' || $v === null) continue;
            $base['api'][$k] = $v;
        }
    }

    // 铁律 ①：`db` 段一个字节都不许被覆盖。
    //   上面只遍历白名单段落，天然不会碰到 db —— **白名单本身就是防线**。
    //   这里再加一道**真断言**（不是空操作）：将来若有人手滑把 'db' 写进
    //   MAPI_APP_DB_SECTIONS，立刻抛错暴露，而不是静默地把库连接配置覆盖掉 ——
    //   那会导致"库不可达时连兜底都读不到"，是能锁死整个应用的错误。
    if (in_array('db', MAPI_APP_DB_SECTIONS, true)) {
        throw new RuntimeException(
            'mapi_app_config(): `db` 段绝不允许进入 MAPI_APP_DB_SECTIONS —— '
            . '它是库不可达时的唯一兜底，覆盖它会让应用彻底起不来。'
        );
    }
    return $base;
}

/**
 * 便捷版：从文件加载 + 合并（给"没有现成 $base"的调用方用）。
 * ⚠ 需要调用方给出 `$db`；没有库时就是"读文件"。
 * 返回的数组**始终保留 `db` 段**（它决定应用能不能启动）。
 */
function mapi_app_config_load(?string $configFile = null, $db = null): array
{
    if ($configFile === null) $configFile = dirname(__DIR__, 2) . '/config/config.php';
    $base = is_file($configFile) ? require $configFile : [];
    if (!is_array($base)) $base = [];
    return mapi_app_config($base, $db);
}
