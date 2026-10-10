<?php

require_once __DIR__ . '/schema_parse.php';
require_once __DIR__ . '/schema_directives.php';

// 取期望的表与列
function get_migrations(): array {
    $tables = schema_tables();
    foreach (schema_legacy_skip_columns() as $table => $cols) {
        if (!isset($tables[$table])) continue;
        foreach ($cols as $c) unset($tables[$table][$c]);
    }
    return $tables;
}

// 取期望的索引
function get_migration_indexes(): array {
    return schema_indexes();
}

// 取期望的主键
function get_migration_primary_keys(): array {
    $pks    = schema_primary_keys();
    $tables = get_migrations();
    foreach ($pks as $table => $cols) {
        if (!is_array($cols) || !$cols) { unset($pks[$table]); continue; }
        $have = $tables[$table] ?? [];
        foreach ($cols as $c) {
            if (!isset($have[$c])) { unset($pks[$table]); break; }
        }
    }
    return $pks;
}

/* ============================ 结构指纹：跳过没必要的全量探测 ============================
 *
 * 背景：`admin/includes/guard.php` 会在**每次后台请求**里跑一遍增量同步。实测（17 张表）
 * 稳态下要发 101 条 SQL、耗时 ~89ms —— 而结构其实一个字都没变。这里做一个"内容指纹短路"：
 *
 *     跳过 = 指纹一致  AND  没有任何 _mig_skip_*  AND  没有任何 _schema_fail_*
 *
 * 三条同时成立才跳过（任何一条不满足都照旧跑全量，绝不冒险跳过）。
 *
 * 为什么用 `install/sql/mysql.sql` 的内容 md5：
 *   · 它是**唯一结构来源**（SQLite 的结构也是从它推导的），所以一份指纹两种驱动共用；
 *   · 用内容而非 mtime —— mtime 会被 touch / 重新解压 / git checkout 无意义地改动。
 *
 * 什么时候会失效重跑：改了 mysql.sql、删掉 `_schema_stamp` 那一行、出现跳过/失败标记、
 * 或者上一次同步有任何一处失败（失败时**故意不写指纹**，保证下次还会重跑）。
 */

/** 结构来源文件（唯一结构来源，两种数据库共用） */
function schema_stamp_source(): string {
    return dirname(__DIR__) . '/sql/mysql.sql';
}

/** 当前结构指纹；文件读不到时返回空串（调用方会因此不跳过） */
function schema_stamp_current(): string {
    $md5 = @md5_file(schema_stamp_source());
    return is_string($md5) ? $md5 : '';
}

/** 记一次"同步没做成"：只要有一处失败，本次就不写指纹 */
function mig_note_failure(): void {
    $GLOBALS['__mig_fail'] = (int)($GLOBALS['__mig_fail'] ?? 0) + 1;
}

function mig_failures(): int {
    return (int)($GLOBALS['__mig_fail'] ?? 0);
}

/** 读结构指纹（PDO 与 SQLite3 两种句柄都支持） */
function schema_stamp_read($db): ?string {
    try {
        if ($db instanceof SQLite3) {
            $v = $db->querySingle("SELECT config_value FROM mapi_config WHERE config_key='_schema_stamp' LIMIT 1");
            return ($v === null || $v === false) ? null : (string)$v;
        }
        $q = $db->prepare("SELECT config_value FROM mapi_config WHERE config_key='_schema_stamp' LIMIT 1");
        $q->execute();
        $v = $q->fetchColumn();
        return $v === false ? null : (string)$v;
    } catch (Throwable $e) {
        return null;                       // 表还不存在（全新安装）也算"读不到"
    }
}

/** 写结构指纹 —— 复用各驱动自己的 config 读写，保持行为一致 */
function schema_stamp_write($db, string $value): void {
    if ($db instanceof SQLite3) {
        if (function_exists('sqlite_schema_kv_set')) sqlite_schema_kv_set($db, '_schema_stamp', $value);
        return;
    }
    if (function_exists('schema_kv_set')) schema_kv_set($db, '_schema_stamp', $value);
}

/** 有没有"跳过项 / 失败项"标记（有就说明结构还没收敛，别短路） */
function schema_skip_flag_count($db): int {
    try {
        // 注意 LIKE 里的 _ 在两种数据库里都是单字符通配符 —— 这里只用来计数，
        // 匹配到别的键也无害（不存在 '_xmig_skip_*' 这类键）。
        $sql = "SELECT COUNT(*) FROM mapi_config WHERE config_key LIKE '_mig_skip_%' OR config_key LIKE '_schema_fail_%'";
        if ($db instanceof SQLite3) {
            return (int)$db->querySingle($sql);
        }
        $q = $db->query($sql);
        return $q ? (int)$q->fetchColumn() : 0;
    } catch (Throwable $e) {
        return 0;                          // 读不到就当作没有；指纹那条会兜住安全性
    }
}

/** 能否跳过这次全量结构探测（宁可不跳，也不冒险跳） */
function schema_can_skip($db): bool {
    try {
        $want = schema_stamp_current();
        if ($want === '') return false;
        if (schema_stamp_read($db) !== $want) return false;
        return schema_skip_flag_count($db) === 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** 同步完整跑完且**没有任何失败**时才落指纹 */
function schema_stamp_commit($db): void {
    if (mig_failures() > 0) {
        error_log('MAPI schema: 本次同步有 ' . mig_failures() . ' 处失败，不写结构指纹（下次仍会全量校验）');
        return;
    }
    $want = schema_stamp_current();
    if ($want !== '') schema_stamp_write($db, $want);
}