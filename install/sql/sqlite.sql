-- 参考文件：SQLite 结构由 install/sql/mysql.sql 派生（类型映射见 install/lib/up_sqlite.php），
-- 本文件不参与建表；后台提示「手动执行本文件补表」时用它（例如数据表 mapi_domains 缺失）。

-- mapi_users
CREATE TABLE IF NOT EXISTS mapi_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    qq VARCHAR(20) DEFAULT '',
    email VARCHAR(100) DEFAULT '',
    is_admin INTEGER DEFAULT 0,
    auto_theme INTEGER DEFAULT 1,
    theme_mode VARCHAR(10) DEFAULT 'light',
    admin_theme VARCHAR(10) DEFAULT 'light',
    lyrics_default INTEGER DEFAULT 1,
    autoplay_default INTEGER DEFAULT 0,
    player_pos VARCHAR(24) DEFAULT '',
    player_skin VARCHAR(32) DEFAULT '',
    player_skin_cfg VARCHAR(1000) DEFAULT '{"rose":{"pos":"left:88"},"router":{"pos":"right:80"}}',
    lrc_font VARCHAR(500) DEFAULT '',
    lrc_font_url VARCHAR(500) DEFAULT '',
    lrc_font_name VARCHAR(100) DEFAULT '',
    lrc_font_size INTEGER DEFAULT 0,
    background VARCHAR(500) DEFAULT '',
    background_url VARCHAR(500) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- mapi_config
CREATE TABLE IF NOT EXISTS mapi_config (
    config_key VARCHAR(50) PRIMARY KEY,
    config_value TEXT
);

-- mapi_keys
CREATE TABLE IF NOT EXISTS mapi_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    api_key VARCHAR(64) NOT NULL,
    name VARCHAR(64) NOT NULL DEFAULT '',
    domain VARCHAR(190) NOT NULL DEFAULT '',
    status INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- mapi_orders
CREATE TABLE IF NOT EXISTS mapi_orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    product_name VARCHAR(100) DEFAULT '',
    type VARCHAR(20) DEFAULT '',
    price DECIMAL(10,2) DEFAULT 0.00,
    duration_days INTEGER DEFAULT 0,
    trade_no VARCHAR(64) DEFAULT '',
    status INTEGER DEFAULT 0,
    paid_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- mapi_passkeys
CREATE TABLE IF NOT EXISTS mapi_passkeys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    credential_id VARCHAR(500) NOT NULL,
    public_key_pem TEXT,
    counter INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- mapi_products
CREATE TABLE IF NOT EXISTS mapi_products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100) NOT NULL,
    type VARCHAR(20) DEFAULT 'subscription',
    price DECIMAL(10,2) DEFAULT 0.00,
    duration_days INTEGER DEFAULT 0,
    description TEXT,
    status INTEGER DEFAULT 1,
    sort_order INTEGER DEFAULT 0
);

-- mapi_mail_templates
CREATE TABLE IF NOT EXISTS mapi_mail_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100) NOT NULL DEFAULT '',
    subject VARCHAR(200) NOT NULL DEFAULT '顺雅音乐 - 验证码邮件',
    body TEXT,
    is_html INTEGER NOT NULL DEFAULT 0,
    is_default INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- mapi_tokens
CREATE TABLE IF NOT EXISTS mapi_tokens (
    token VARCHAR(80) PRIMARY KEY,
    api_key VARCHAR(255) NOT NULL,
    expires_at INTEGER NOT NULL
);

-- mapi_logs
CREATE TABLE IF NOT EXISTS mapi_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip VARCHAR(45) DEFAULT '',
    referer VARCHAR(500) DEFAULT '',
    endpoint VARCHAR(200) DEFAULT '',
    user_agent VARCHAR(500) DEFAULT '',
    api_key VARCHAR(64) DEFAULT '',
    traffic_bytes INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- mapi_geetest
CREATE TABLE IF NOT EXISTS mapi_geetest (
    captcha_id VARCHAR(64) NOT NULL DEFAULT '',
    key VARCHAR(64) NOT NULL DEFAULT ''
);

-- mapi_super_settings
CREATE TABLE IF NOT EXISTS mapi_super_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    setting_key VARCHAR(50) NOT NULL UNIQUE,
    setting_value TEXT
);

CREATE TABLE IF NOT EXISTS mapi_stats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    stat_date DATE NOT NULL UNIQUE,
    call_count INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS mapi_playlists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    key_id INTEGER NOT NULL,
    name VARCHAR(100) NOT NULL DEFAULT '',
    type VARCHAR(20) NOT NULL DEFAULT 'custom',
    remote_id VARCHAR(100) DEFAULT '',
    server VARCHAR(20) DEFAULT 'netease',
    cover_url VARCHAR(500) DEFAULT '',
    cover_data TEXT,
    cover_mode VARCHAR(20) DEFAULT 'auto',
    sort_order INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    cover_updated_at DATETIME DEFAULT NULL
);

-- 歌曲封面索引：一首歌一行，指向封面对象（S3）或历史遗留的 base64。
-- 与 install/sql/mysql.sql 保持同一份结构，SQLite 部署才不会缺列缺表。
CREATE TABLE IF NOT EXISTS mapi_song_covers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    song_ref VARCHAR(191) NOT NULL DEFAULT '',
    key_id INTEGER NOT NULL DEFAULT 0,
    cover_url VARCHAR(500) DEFAULT '',
    cover_data TEXT,
    sha256 VARCHAR(64) NOT NULL DEFAULT '',
    object_key VARCHAR(255) NOT NULL DEFAULT '',
    bytes INTEGER NOT NULL DEFAULT 0,
    mime VARCHAR(40) NOT NULL DEFAULT '',
    updated_at DATETIME DEFAULT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_song_covers_ref ON mapi_song_covers (song_ref);
CREATE INDEX IF NOT EXISTS idx_song_covers_sha ON mapi_song_covers (sha256);
CREATE INDEX IF NOT EXISTS idx_song_covers_obj ON mapi_song_covers (object_key);

-- 封面对象表（内容寻址）：一图一行，主键是图片内容的 sha256，跨歌单/跨歌曲只存一份。
-- refs 减到 0 时记 orphaned_at（没人用了），后台「清理无用封面」按保留期回收。
CREATE TABLE IF NOT EXISTS mapi_cover_objects (
    sha256 VARCHAR(64) NOT NULL DEFAULT '',
    object_key VARCHAR(255) NOT NULL DEFAULT '',
    bytes INTEGER NOT NULL DEFAULT 0,
    mime VARCHAR(40) NOT NULL DEFAULT '',
    refs INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT NULL,
    orphaned_at DATETIME DEFAULT NULL,
    PRIMARY KEY (sha256)
);
CREATE INDEX IF NOT EXISTS idx_cover_obj_key ON mapi_cover_objects (object_key);
CREATE INDEX IF NOT EXISTS idx_cover_obj_orphan ON mapi_cover_objects (refs, orphaned_at);

CREATE TABLE IF NOT EXISTS mapi_songs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    key_id INTEGER NOT NULL DEFAULT 0,
    playlist_id INTEGER NOT NULL,
    song_id VARCHAR(64) NOT NULL DEFAULT '',
    name VARCHAR(255) NOT NULL DEFAULT '',
    artist VARCHAR(255) DEFAULT '',
    server VARCHAR(20) DEFAULT 'netease',
    sort_order INTEGER DEFAULT 0,
    missing INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- mapi_domains（宿主域名授权与底部偏移；老库若还有 uniq_user_domain，由 mysql.sql 的 @drop-index 清掉）
CREATE TABLE IF NOT EXISTS mapi_domains (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL DEFAULT 0,
    key_id INTEGER NOT NULL DEFAULT 0,
    domain VARCHAR(190) NOT NULL DEFAULT '',
    authorized INTEGER NOT NULL DEFAULT 1,
    auto INTEGER NOT NULL DEFAULT 1,
    lyrics_bottom INTEGER NOT NULL DEFAULT 0,
    player_bottom INTEGER DEFAULT NULL,
    pc_auto INTEGER DEFAULT NULL,
    pc_lyrics INTEGER DEFAULT NULL,
    pc_player INTEGER DEFAULT NULL,
    mo_auto INTEGER DEFAULT NULL,
    mo_lyrics INTEGER DEFAULT NULL,
    mo_player INTEGER DEFAULT NULL,
    pc_detected INTEGER NOT NULL DEFAULT 0,
    mo_detected INTEGER NOT NULL DEFAULT 0,
    detected_at DATETIME DEFAULT NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    auto_added INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_user_key_domain ON mapi_domains (user_id, key_id, domain);

-- ═══ 索引（与 MySQL 版保持一致；增量升级会按 install/lib/migrations.php 的声明自动补齐）═══
-- 索引名与 install/sql/mysql.sql **完全一致**：结构由 mysql.sql 派生（自动同步用的就是那套名字），
-- 本文件只是"人工补表"时的参考。名字不一致的后果：手工执行完，再跑一次自动同步，
-- 就会在同一列上留下两个名字不同的重复索引。
CREATE INDEX IF NOT EXISTS idx_user_id            ON mapi_keys (user_id);
CREATE INDEX IF NOT EXISTS idx_api_key            ON mapi_keys (api_key);
CREATE INDEX IF NOT EXISTS idx_user_id            ON mapi_orders (user_id);
CREATE INDEX IF NOT EXISTS idx_trade_no           ON mapi_orders (trade_no);
CREATE INDEX IF NOT EXISTS idx_user_id            ON mapi_passkeys (user_id);
CREATE INDEX IF NOT EXISTS idx_credential_id      ON mapi_passkeys (credential_id);
CREATE INDEX IF NOT EXISTS idx_expires_at         ON mapi_tokens (expires_at);
CREATE INDEX IF NOT EXISTS idx_api_key            ON mapi_logs (api_key);
CREATE INDEX IF NOT EXISTS idx_created_at         ON mapi_logs (created_at);
CREATE INDEX IF NOT EXISTS idx_key_id             ON mapi_playlists (key_id);
CREATE INDEX IF NOT EXISTS idx_playlist_id        ON mapi_songs (playlist_id);
CREATE INDEX IF NOT EXISTS idx_key_id             ON mapi_songs (key_id);
CREATE INDEX IF NOT EXISTS idx_domain             ON mapi_domains (domain);
-- 下面 4 个是 mysql.sql 里有、本文件以前漏掉的
CREATE INDEX IF NOT EXISTS username               ON mapi_users (username);
CREATE INDEX IF NOT EXISTS idx_email              ON mapi_users (email);
CREATE INDEX IF NOT EXISTS idx_setting_key        ON mapi_super_settings (setting_key);
CREATE INDEX IF NOT EXISTS idx_stat_date          ON mapi_stats (stat_date);