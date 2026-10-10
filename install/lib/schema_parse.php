<?php

// 取结构来源文件路径
function schema_sql_file(string $type = 'mysql'): string {
    return __DIR__ . '/../sql/' . ($type === 'sqlite' ? 'sqlite.sql' : 'mysql.sql');
}

// 解析结构文件得到表、索引与主键
function schema_parse(string $type = 'mysql'): array {
    static $cache = [];
    if (isset($cache[$type])) return $cache[$type];

    $out = ['tables' => [], 'indexes' => [], 'primary' => []];
    $file = schema_sql_file($type);
    if (!is_file($file)) return $cache[$type] = $out;

    $sql = (string)file_get_contents($file);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    // 表定义有两种收尾写法：MySQL 是 ") ENGINE=…"，SQLite 是 ");"。**两种都要认** ——
    // 只认 ENGINE= 时 sqlite.sql 会解析出 0 张表（静默返回空集），
    // 参考副本就无法做一致性校验，将来若真按文件建表也会"什么都不建"。
    if (!preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\((.*?)\)\s*(?:ENGINE\s*=|;)/is', $sql, $m, PREG_SET_ORDER)) {
        return $cache[$type] = $out;
    }

    foreach ($m as $one) {
        $table   = $one[1];
        $cols    = [];
        $indexes = [];
        $primary = null;

        foreach (schema_split_defs($one[2]) as $def) {
            $def = trim($def);
            if ($def === '') continue;

            if (preg_match('/^PRIMARY\s+KEY\s*\(([^)]*)\)/i', $def, $k)) {
                $primary = schema_col_list($k[1]);
                continue;
            }
            // 索引定义必须"只有" KEY/INDEX 名(列清单)，后面不能再有别的东西：
            // 否则 `key VARCHAR(64) NOT NULL DEFAULT ''`（列名正好叫 key、又没写反引号）会被
            // 误判成"名为 VARCHAR、列为 64"的索引，那一列就被静默吃掉、同步永远不建它。
            if (preg_match('/^(UNIQUE\s+)?(?:KEY|INDEX)\s+`?(\w+)`?\s*\(([^)]*)\)\s*$/i', $def, $k)) {
                $indexes[$k[2]] = ['cols' => schema_col_list($k[3]), 'unique' => $k[1] !== ''];
                continue;
            }
            if (preg_match('/^`?(\w+)`?\s+(.+)$/s', $def, $k)) {
                $name = $k[1];
                $body = trim($k[2]);
                if (preg_match('/\bUNIQUE\b/i', $body)) {
                    $indexes['uniq_' . $table . '_' . $name] = ['cols' => [$name], 'unique' => true];
                }
                $cols[$name] = $body;
            }
        }

        $out['tables'][$table]  = $cols;
        $out['indexes'][$table] = $indexes;
        $out['primary'][$table] = $primary;
    }
    return $cache[$type] = $out;
}

// 按顶层逗号切分表定义
function schema_split_defs(string $body): array {
    $defs = [];
    $buf  = '';
    $depth = 0;
    $quote = '';
    $len = strlen($body);

    for ($i = 0; $i < $len; $i++) {
        $ch = $body[$i];
        if ($quote !== '') {
            $buf .= $ch;
            if ($ch === $quote) $quote = '';
            continue;
        }
        if ($ch === "'" || $ch === '"') { $quote = $ch; $buf .= $ch; continue; }
        if ($ch === '(') { $depth++; $buf .= $ch; continue; }
        if ($ch === ')') { $depth--; $buf .= $ch; continue; }
        if ($ch === ',' && $depth === 0) { $defs[] = $buf; $buf = ''; continue; }
        $buf .= $ch;
    }
    if (trim($buf) !== '') $defs[] = $buf;
    return $defs;
}

// 解析索引的列清单
function schema_col_list(string $s): array {
    $out = [];
    foreach (explode(',', $s) as $c) {
        $c = trim(str_replace('`', '', trim($c)));
        if ($c !== '') $out[] = $c;
    }
    return $out;
}

// 取全部表与列
function schema_tables(string $type = 'mysql'): array {
    return schema_parse($type)['tables'];
}

// 取全部索引
function schema_indexes(string $type = 'mysql'): array {
    return schema_parse($type)['indexes'];
}

// 取全部主键
function schema_primary_keys(string $type = 'mysql'): array {
    return schema_parse($type)['primary'];
}

// 取老库遗留且不需要补的列
function schema_legacy_skip_columns(): array {
    return [
        'mapi_stats' => ['id'],
    ];
}