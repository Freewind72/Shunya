<?php

try {
    $host   = $input['host'] ?? '127.0.0.1';
    $port   = (int)($input['port'] ?? 3306);
    $user   = $input['user'] ?? 'root';
    $pass   = $input['pass'] ?? '';
    $dbname = !empty($input['db']) ? $input['db'] : 'msapi';
    $prefix = $input['prefix'] ?? 'mapi';

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $m = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10,
    ]);
    $version = $m->query('SELECT VERSION() v')->fetchColumn();
    stream_line("[数据库] MySQL {$version} 连接成功");

    $m->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $m->exec("USE `{$dbname}`");

    // 结构以 install/lib/migrations.php 为唯一来源：建表 / 补列 / 补索引与"每次访问的增量"同一套逻辑
    require_once __DIR__ . '/../lib/migrations.php';
    require_once __DIR__ . '/../lib/up_mysql.php';

    $ourTables = array_keys(get_migrations());
    $existing = $m->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $cleaned  = 0;
    foreach ($existing as $tbl) {
        if (!in_array($tbl, $ourTables) && strpos($tbl, (string)$prefix) === 0) {
            $m->exec("DROP TABLE IF EXISTS `{$tbl}`");
            stream_line("[清理] 删除冗余表 {$tbl}");
            $cleaned++;
        }
    }
    if ($cleaned === 0) {
        stream_line('[清理] 无冗余');
    }

    schema_sync_mysql($m, true);        // 安装器是一次性动作：强制全量，不看结构指纹
    stream_line('[数据库] 表结构已同步（缺失的表 / 列 / 索引自动补齐）');

    $username = $input['username'] ?? 'admin';
    $password = password_hash($input['password'], PASSWORD_BCRYPT);
    $qq    = $input['qq'] ?? '';
    $email = $input['email'] ?? '';

    $stmt = $m->prepare("INSERT INTO mapi_users (id, username, password, qq, email, is_admin) VALUES (1, ?, ?, ?, ?, 0) ON DUPLICATE KEY UPDATE username=VALUES(username), password=VALUES(password), qq=VALUES(qq), email=VALUES(email), is_admin=VALUES(is_admin)");
    $stmt->execute([$username, $password, $qq, $email]);
    stream_line('[用户] 管理员创建完成');

    $defaultKeys = [
        'key_limit'    => json_encode(['limit' => 1]),
        'login_bg'     => '',
        'login_theme'  => 'light',
        // ── 配置进库（2026-10-20 起的新结构）：文件里只留 db，其余权威值在这里 ──
        // api_secrets：两个随机密钥（物理隔离，后台「接口」页的可编辑 api 行整行 REPLACE
        //   也不会把它们冲掉——这是历史事故的教训）。
        'api_secrets'  => json_encode([
            'jwt_secret' => bin2hex(random_bytes(32)),   // ≥32 字符高熵；缺失时 jwt_secret() 会抛异常
            'demo_key'   => '',                           // 演示播放器专用；留空 = 首页不显示（安全默认）
        ]),
        // site / admin / api：后台按功能分组可编辑的三行（读-改-写，见 assets/lib/api_config.php）
        'site'         => json_encode(['name' => 'MSAPI', 'url' => get_site_url()], JSON_UNESCAPED_SLASHES),
        'admin'        => json_encode(['rate_limit' => 5, 'rate_window' => 60]),
        'api'          => json_encode([
            'base_url'   => '',
            'qq_referer' => 'https://y.qq.com',
            'qq_cover'   => 'https://y.qq.com',
        ], JSON_UNESCAPED_SLASHES),
    ];
    $st = $m->prepare("INSERT IGNORE INTO mapi_config (config_key, config_value) VALUES (?, ?)");
    foreach ($defaultKeys as $k => $v) {
        $st->execute([$k, $v]);
    }
    stream_line('[配置] 默认配置已写入（api_secrets / site / admin / api 各一行）');

    /* ── config.php 只写 `db` 一段（2026-10-20 新结构）─────────────────
       旧版把 site/admin/api（含 jwt_secret/demo_key）全写进文件。现在这些段的
       权威副本在数据库（mapi_config 各行，后台可编辑），文件里只留**库不可达时
       唯一兜底**的 db 段——读库得先有连接信息，鸡生蛋，所以 db 永远只能在文件。
       运行时由 assets/lib/app_config.php 的 mapi_app_config() 把库行合并进 $cfg，
       并把 api_secrets 镜像回 api.jwt_secret / api.demo_key 供旧代码读取。 */
    @mkdir(dirname($configFile), 0755, true);
    $config = [
        'db' => [
            'type'     => 'mysql',
            'hosts'    => [$host],
            'user'     => $user,
            'password' => $pass,
            'database' => $dbname,
            'port'     => $port,
            'prefix'   => $prefix,
        ],
    ];
    $configContent = "<?php\nreturn " . var_export($config, true) . ";\n";
    file_put_contents($configFile, $configContent);
    stream_line('[写入] config/config.php');

    $lockContent = "install_time: " . date('Y-m-d H:i:s') . "\n"
        . "install_type: mysql\n"
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