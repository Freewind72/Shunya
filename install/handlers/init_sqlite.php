<?php

try {
    if (!is_dir($sqlDir)) {
        @mkdir($sqlDir, 0755, true);
        stream_line('[建立] assets/sql/ 目录');
    }

    $db = new SQLite3($dbFile);
    $db->enableExceptions(true);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=ON');
    stream_line('[建立] 数据库文件 assets/sql/msapi.db');

    // 结构以 install/lib/migrations.php 为唯一来源：与"每次访问的增量"同一套逻辑
    require_once __DIR__ . '/../lib/migrations.php';
    require_once __DIR__ . '/../lib/up_sqlite.php';

    $ourTables = array_keys(get_migrations());
    $existing  = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
    $cleaned   = 0;
    while ($row = $existing->fetchArray(SQLITE3_ASSOC)) {
        $tbl = $row['name'];
        if (!in_array($tbl, $ourTables) && strpos($tbl, 'mapi_') === 0) {
            $db->exec("DROP TABLE IF EXISTS \"{$tbl}\"");
            stream_line("[清理] 删除冗余表 {$tbl}");
            $cleaned++;
        }
    }
    if ($cleaned === 0) {
        stream_line('[清理] 无冗余');
    }

    schema_sync_sqlite($db, true);      // 安装器是一次性动作：强制全量，不看结构指纹
    stream_line('[数据库] 表结构已同步（缺失的表 / 列 / 索引自动补齐）');

    $username = $input['username'] ?? 'admin';
    $password = password_hash($input['password'], PASSWORD_BCRYPT);
    $qq    = $input['qq'] ?? '';
    $email = $input['email'] ?? '';

    $stmt = $db->prepare("INSERT OR REPLACE INTO mapi_users (id, username, password, qq, email, is_admin) VALUES (1, ?, ?, ?, ?, 0)");
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $stmt->bindValue(2, $password, SQLITE3_TEXT);
    $stmt->bindValue(3, $qq, SQLITE3_TEXT);
    $stmt->bindValue(4, $email, SQLITE3_TEXT);
    $stmt->execute();
    stream_line('[用户] 管理员创建完成');

    $defaultKeys = [
        'key_limit'   => json_encode(['limit' => 1]),
        'login_bg'    => '',
        'login_theme' => 'light',
        // ── 配置进库（2026-10-20 起的新结构）：文件里只留 db，其余权威值在这里 ──
        // api_secrets：两个随机密钥（物理隔离，后台整行 REPLACE 不会把它们冲掉）。
        'api_secrets' => json_encode([
            'jwt_secret' => bin2hex(random_bytes(32)),   // ≥32 字符高熵；缺失时 jwt_secret() 会抛异常
            'demo_key'   => '',                           // 演示播放器专用；留空 = 首页不显示（安全默认）
        ]),
        // site / admin / api：后台按功能分组可编辑的三行
        'site'        => json_encode(['name' => 'MSAPI', 'url' => get_site_url()], JSON_UNESCAPED_SLASHES),
        'admin'       => json_encode(['rate_limit' => 5, 'rate_window' => 60]),
        'api'         => json_encode([
            'base_url'   => '',
            'qq_referer' => 'https://y.qq.com',
            'qq_cover'   => 'https://y.qq.com',
        ], JSON_UNESCAPED_SLASHES),
    ];
    foreach ($defaultKeys as $k => $v) {
        // JSON 里可能带单引号，必须转义后再拼 SQL（sqlite 无 prepare 的兼容写法）
        $vEsc = str_replace("'", "''", $v);
        $db->exec("INSERT OR IGNORE INTO mapi_config (config_key, config_value) VALUES ('{$k}', '{$vEsc}')");
    }
    stream_line('[配置] 默认配置已写入（api_secrets / site / admin / api 各一行）');

    /* ── config.php 只写 `db` 一段（2026-10-20 新结构，与 init_mysql 同口径）──
       其余段的权威副本在 mapi_config 各行，运行时由 mapi_app_config() 合并。 */
    @mkdir(dirname($configFile), 0755, true);
    $config = [
        'db' => [
            'type' => 'sqlite',
            'path' => 'assets/sql/msapi.db',
        ],
    ];
    $configContent = "<?php\nreturn " . var_export($config, true) . ";\n";
    file_put_contents($configFile, $configContent);
    stream_line('[写入] config/config.php');

    $lockContent = "install_time: " . date('Y-m-d H:i:s') . "\n"
        . "install_type: sqlite\n"
        . "php_version: " . PHP_VERSION . "\n"
        . "signature: msapi\n";
    file_put_contents($lockFile, $lockContent);
    @mkdir($self . '/assets', 0755, true);
    file_put_contents($self . '/assets/sha256', hash_file('sha256', $lockFile));
    stream_line('[写入] install/install.lock');

    stream_done();
} catch (Throwable $e) {
    stream_error($e->getMessage());
}