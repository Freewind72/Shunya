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
        'key_limit'   => json_encode(['limit' => 1]),
        'login_bg'    => '',
        'login_theme' => 'light',
    ];
    $st = $m->prepare("INSERT IGNORE INTO mapi_config (config_key, config_value) VALUES (?, ?)");
    foreach ($defaultKeys as $k => $v) {
        $st->execute([$k, $v]);
    }
    stream_line('[配置] 默认配置已写入');

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
        'site' => [
            'name' => 'MSAPI',
            'url'  => get_site_url(),
        ],
        'admin' => [
            'rate_limit'  => 5,
            'rate_window' => 60,
        ],
        'api' => [
            'qq_referer' => 'https://y.qq.com',
            'qq_cover'   => 'https://y.qq.com',
            // 签名密钥：必须 ≥32 字符高熵随机值。缺失时 jwt_secret() 会抛异常，
            // 绝不允许回退到由数据库密码派生的弱密钥（那种回退下拖库即可伪造任意 token）。
            'jwt_secret' => bin2hex(random_bytes(32)),
            // 落地页「演示播放器」专用密钥。留空 = 不显示演示播放器（安全默认值）。
            // 切勿填站长正式密钥：它会被拼进页面 HTML 下发给所有访客。
            'demo_key'   => '',
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