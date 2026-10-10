<?php

function upgrade_sqlite(array $cfg): void {
    try {
        $path = $cfg['db']['path'] ?? '';
        if (!$path) {
            return;
        }

        $path = $path[0] === '/' || preg_match('#^[A-Z]:#i', $path) ? $path : dirname(__DIR__, 2) . '/' . $path;
        if (!file_exists($path)) {
            return;
        }
        $db   = new SQLite3($path);
        $db->enableExceptions(true);

        schema_sync_sqlite($db);            // 与安装器共用同一套结构同步

        seed_config($db, 'sqlite');
        $db->close();
    } catch (Throwable $e) {
        error_log("MAPI upgrade (SQLite): " . $e->getMessage());
    }
}

// 同步表结构（安装与每次访问共用）
// $force = true 时不做稳态短路（安装器必须真的全查一遍）。
function schema_sync_sqlite(SQLite3 $db, bool $force = false): void {
    // 与 MySQL 同一套短路：指纹（install/sql/mysql.sql 内容 md5）一致且无跳过/失败标记就跳过全量探测。
    // SQLite 的结构本来就是从 mysql.sql 推导的，所以两种驱动共用同一个 _schema_stamp。
    if (!$force && schema_can_skip($db)) {
        return;
    }

    $migrations = get_migrations();
    $dir        = schema_directives();

    // 显式删除
    foreach ($dir['drop_table'] as $t) {
        if (!preg_match('/^\w+$/', $t)) continue;
        try { $db->exec("DROP TABLE IF EXISTS \"{$t}\""); } catch (Throwable $e) { mig_note_failure(); error_log("MAPI schema(SQLite): drop table {$t}: " . $e->getMessage()); }
    }
    foreach ($dir['drop_index'] as $d) {
        if (!preg_match('/^\w+$/', $d['index'])) continue;
        try { $db->exec("DROP INDEX IF EXISTS \"" . $d['index'] . "\""); } catch (Throwable $e) { mig_note_failure(); error_log("MAPI schema(SQLite): drop index {$d['index']}: " . $e->getMessage()); }
    }

    // 需要重建的表
    $force = [];
    foreach ($dir['sync'] as $t)              $force[$t] = 'full';
    foreach ($dir['sync_keep_extra'] as $t)   $force[$t] = 'keep-extra';
    foreach ($dir['modify'] as $m)            $force[$m['table']] = $force[$m['table']] ?? 'keep-extra';
    foreach ($dir['drop_column'] as $d)       $force[$d['table']] = 'full';

    foreach ($migrations as $table => $columns) {
        $st = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'");
        $tableExists = $st && $st->fetchArray();

        if (!$tableExists) {
            create_sqlite_table($db, $table, $columns);
            continue;
        }

        $st = $db->query("PRAGMA table_info({$table})");
        $existingCols = [];
        while ($row = $st->fetchArray(SQLITE3_ASSOC)) {
            $existingCols[$row['name']] = strtoupper((string)$row['type']);      // 列名 => 类型
        }

        $needsRebuild = false;
        foreach ($columns as $colName => $colDef) {
            if (!isset($existingCols[$colName])) {
                $needsRebuild = true;
                break;
            }
        }
        if (!$needsRebuild) {
            $dropCols = ['playlist_id', 'server', 'playlists'];
            if ($table === 'mapi_keys') {
                foreach ($dropCols as $dc) {
                    if (isset($existingCols[$dc])) {
                        $needsRebuild = true;
                        break;
                    }
                }
            }
        }

        // 按签名只重建一次
        $mode = $force[$table] ?? '';
        if ($mode !== '') {
            $dropHere = [];
            foreach ($dir['drop_column'] as $d) { if ($d['table'] === $table) $dropHere[] = $d['col']; }
            $sig = md5(json_encode($columns) . '|' . $mode . '|' . implode(',', $dropHere));
            if (sqlite_schema_kv_get($db, '_schema_sync_sqlite_' . $table) !== $sig) {
                $desired = $columns;
                foreach ($dropHere as $c) unset($desired[$c]);
                if ($mode === 'keep-extra') {
                    foreach ($existingCols as $c => $t) { if (!isset($desired[$c])) $desired[$c] = $t; }
                }
                rebuild_sqlite_table($db, $table, $desired, $existingCols);
                sqlite_schema_kv_set($db, '_schema_sync_sqlite_' . $table, $sig);
                error_log("MAPI schema(SQLite): 按声明重建表 {$table}（mode={$mode}）");
                continue;
            }
        }

        if ($needsRebuild) {
            rebuild_sqlite_table($db, $table, $columns, $existingCols);
        }
    }

    apply_sqlite_indexes($db, get_migration_indexes());

    // 全部跑完且没有失败 → 落指纹（与 MySQL 共用一个 key，因为结构来源都是 mysql.sql）
    schema_stamp_commit($db);
}

// 读取结构标记
function sqlite_schema_kv_get(SQLite3 $db, string $key): ?string {
    try {
        $k = SQLite3::escapeString($key);
        $v = $db->querySingle("SELECT config_value FROM mapi_config WHERE config_key='{$k}' LIMIT 1");
        return $v === null || $v === false ? null : (string)$v;
    } catch (Throwable $e) {
        return null;
    }
}

function sqlite_schema_kv_set(SQLite3 $db, string $key, string $value): void {
    try {
        $k = SQLite3::escapeString($key);
        $v = SQLite3::escapeString($value);
        if ((int)$db->querySingle("SELECT COUNT(*) FROM mapi_config WHERE config_key='{$k}'") > 0) {
            $db->exec("UPDATE mapi_config SET config_value='{$v}' WHERE config_key='{$k}'");
        } else {
            $db->exec("INSERT INTO mapi_config (config_key, config_value) VALUES ('{$k}', '{$v}')");
        }
    } catch (Throwable $e) {
    }
}

function create_sqlite_table(SQLite3 $db, string $table, array $columns): void {
    $primary = get_migration_primary_keys()[$table] ?? null;
    $defs = [];
    $hasId = false;
    foreach ($columns as $name => $def) {
        $sqliteDef = mysql_to_sqlite_type($def);
        if ($name === 'id' && stripos($def, 'AUTO_INCREMENT') !== false) {
            $defs[] = "id INTEGER PRIMARY KEY AUTOINCREMENT";
            $hasId = true;
        } else {
            $defs[] = "\"{$name}\" {$sqliteDef}";
            if ($name === 'id') $hasId = true;
        }
    }
    if ($primary && !($hasId && count($primary) === 1 && $primary[0] === 'id')) {
        $defs[] = 'PRIMARY KEY (' . implode(',', array_map(function ($c) { return "\"{$c}\""; }, $primary)) . ')';
    } elseif (!$hasId) {
        $defs[] = 'id INTEGER PRIMARY KEY AUTOINCREMENT';
    }
    $sql = "CREATE TABLE IF NOT EXISTS \"{$table}\" (\n  " . implode(",\n  ", $defs) . "\n)";
    $db->exec($sql);
}

function rebuild_sqlite_table(SQLite3 $db, string $table, array $desired, array $existing): void {
    $temp   = "{$table}_migrate_" . time();
    $primary = get_migration_primary_keys()[$table] ?? null;
    $columns    = [];
    $commonCols = [];
    $hasId = false;
    foreach ($desired as $name => $def) {
        $sqliteDef = mysql_to_sqlite_type($def);
        if ($name === 'id' && stripos($def, 'AUTO_INCREMENT') !== false) {
            $columns[] = "\"{$name}\" INTEGER PRIMARY KEY AUTOINCREMENT";
            $hasId = true;
        } else {
            $columns[] = "\"{$name}\" {$sqliteDef}";
            if ($name === 'id') $hasId = true;
        }
        if (isset($existing[$name])) {
            $commonCols[] = "\"{$name}\"";
        }
    }
    if ($primary && !($hasId && count($primary) === 1 && $primary[0] === 'id')) {
        $columns[] = 'PRIMARY KEY (' . implode(',', array_map(function ($c) { return "\"{$c}\""; }, $primary)) . ')';
    }
    $colNames = implode(', ', $commonCols);

    // 重建必须"先 DROP 旧表、再改名"——中间任何一步失败都会两头落空
    // （旧表已删、临时表又清掉），整段包进事务：失败即 ROLLBACK，连 DROP 一并撤销，原表数据完好。
    try {
        $db->exec('BEGIN IMMEDIATE');
        $db->exec("CREATE TABLE \"{$temp}\" (\n  " . implode(",\n  ", $columns) . "\n)");
        $db->exec("INSERT INTO \"{$temp}\" ({$colNames}) SELECT {$colNames} FROM \"{$table}\"");
        $db->exec("DROP TABLE \"{$table}\"");
        $db->exec("ALTER TABLE \"{$temp}\" RENAME TO \"{$table}\"");
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        mig_note_failure();
        error_log("MAPI upgrade: failed to rebuild {$table}: " . $e->getMessage());
        @$db->exec('ROLLBACK');                                  // 撤销 DROP：原表恢复
        @$db->exec("DROP TABLE IF EXISTS \"{$temp}\"");
    }
}

function mysql_to_sqlite_type(string $def): string {
    $def = strtoupper($def);
    if (strpos($def, 'BIGINT') !== false)   { return 'INTEGER'; }
    if (strpos($def, 'TINYINT') !== false)  { return 'INTEGER'; }
    if (strpos($def, 'INT') !== false)      { return 'INTEGER'; }
    if (strpos($def, 'DECIMAL') !== false)  { return 'REAL'; }
    if (strpos($def, 'FLOAT') !== false)    { return 'REAL'; }
    if (strpos($def, 'DOUBLE') !== false)   { return 'REAL'; }
    if (strpos($def, 'DATETIME') !== false) { return 'TEXT'; }
    if (strpos($def, 'DATE') !== false)     { return 'TEXT'; }
    if (strpos($def, 'TEXT') !== false)     { return 'TEXT'; }
    if (strpos($def, 'BLOB') !== false)     { return 'BLOB'; }
    return 'TEXT';
}

// 同步索引
function apply_sqlite_indexes(SQLite3 $db, array $indexes): void {
    foreach ($indexes as $table => $keys) {
        if (!$keys) continue;
        try {
            $st = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . SQLite3::escapeString($table) . "'");
            if (!$st || !$st->fetchArray()) continue;
            $have = [];
            $idx = $db->query("PRAGMA index_list('{$table}')");
            while ($row = $idx->fetchArray(SQLITE3_ASSOC)) { $have[$row['name']] = true; }
        } catch (Throwable $e) {
            continue;
        }
        foreach ($keys as $name => $k) {
            if (isset($have[$name])) continue;
            if (sqlite_migration_skipped($db, $table, $name)) continue;
            $cols = '"' . implode('","', $k['cols']) . '"';

            if (!empty($k['unique']) && $table === 'mapi_song_covers') {
                try {
                    $db->exec("DELETE FROM mapi_song_covers WHERE id NOT IN (SELECT MAX(id) FROM mapi_song_covers GROUP BY song_ref)");
                } catch (Throwable $e) { /* 忽略 */ }
            }

            $done = false;
            try {
                $db->exec('CREATE ' . (!empty($k['unique']) ? 'UNIQUE ' : '') . 'INDEX IF NOT EXISTS "' . $name . '" ON "' . $table . '" (' . $cols . ')');
                $done = true;
            } catch (Throwable $e) {
                error_log("MAPI upgrade (SQLite): index {$table}.{$name} failed: " . $e->getMessage());
            }
            if (!$done && !empty($k['unique'])) {
                try {
                    $db->exec('CREATE INDEX IF NOT EXISTS "' . $name . '" ON "' . $table . '" (' . $cols . ')');
                    $done = true;
                    // 降级成功 ≠ 结构达标（唯一性没落实）→ 记一次失败，别让指纹把问题掩盖掉
                    mig_note_failure();
                    error_log("MAPI upgrade (SQLite): {$table}.{$name} 唯一索引失败（可能有重复值），已降级为普通索引");
                } catch (Throwable $e2) {
                    error_log("MAPI upgrade (SQLite): index {$table}.{$name} fallback failed: " . $e2->getMessage());
                }
            }
            if (!$done) sqlite_migration_mark_skipped($db, $table, $name);
        }
    }
}

function sqlite_migration_skipped(SQLite3 $db, string $table, string $name): bool {
    try {
        $key = SQLite3::escapeString('_mig_skip_' . $table . '_' . $name);
        $r = $db->querySingle("SELECT COUNT(*) FROM mapi_config WHERE config_key='{$key}'");
        return (int)$r > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function sqlite_migration_mark_skipped(SQLite3 $db, string $table, string $name): void {
    mig_note_failure();                     // 有跳过项 → 本次不写结构指纹，保证下次仍会全量校验
    try {
        $key = SQLite3::escapeString('_mig_skip_' . $table . '_' . $name);
        if ((int)$db->querySingle("SELECT COUNT(*) FROM mapi_config WHERE config_key='{$key}'") > 0) {
            $db->exec("UPDATE mapi_config SET config_value='1' WHERE config_key='{$key}'");
        } else {
            $db->exec("INSERT INTO mapi_config (config_key, config_value) VALUES ('{$key}', '1')");
        }
    } catch (Throwable $e) {
    }
}
