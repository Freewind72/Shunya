-- 表结构唯一来源；变更方式与注意事项见 install/schema.md

-- mapi_users
CREATE TABLE IF NOT EXISTS `mapi_users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(50) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `qq` VARCHAR(20) DEFAULT '',
    `email` VARCHAR(100) DEFAULT '',
    `is_admin` TINYINT DEFAULT 0,
    `auto_theme` TINYINT DEFAULT 1,
    `theme_mode` VARCHAR(10) DEFAULT 'light',
    `admin_theme` VARCHAR(10) DEFAULT 'light',
    `lyrics_default` TINYINT DEFAULT 1,
    `autoplay_default` TINYINT DEFAULT 0,
    `player_pos` VARCHAR(24) DEFAULT '',
    `player_skin` VARCHAR(32) DEFAULT '',
    `player_skin_cfg` VARCHAR(1000) DEFAULT '{"rose":{"pos":"left:88"},"router":{"pos":"right:80"}}',
    `background` VARCHAR(500) DEFAULT '',
    `background_url` VARCHAR(500) DEFAULT '',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `username` (`username`),
    UNIQUE KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mapi_config
CREATE TABLE IF NOT EXISTS `mapi_config` (
    `config_key` VARCHAR(50) NOT NULL,
    `config_value` TEXT,
    PRIMARY KEY (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mapi_keys
-- name：密钥名称（创建时必填，一个站/一个用途配一条密钥时便于区分）
-- domain：该密钥的「授权域名」，非空时只有这个主机名能用这条密钥（get-config 校验）；
--         创建时填的域名会同时写进 mapi_domains 并置为已授权。
CREATE TABLE IF NOT EXISTS `mapi_keys` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `api_key` VARCHAR(64) NOT NULL,
    `name` VARCHAR(64) NOT NULL DEFAULT '',
    `domain` VARCHAR(190) NOT NULL DEFAULT '',
    `status` TINYINT DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_api_key` (`api_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mapi_orders
CREATE TABLE IF NOT EXISTS `mapi_orders` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `product_name` VARCHAR(100) DEFAULT '',
    `type` VARCHAR(20) DEFAULT '',
    `price` DECIMAL(10,2) DEFAULT 0.00,
    `duration_days` INT DEFAULT 0,
    `trade_no` VARCHAR(64) DEFAULT '',
    `status` TINYINT DEFAULT 0,
    `paid_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_trade_no` (`trade_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mapi_passkeys
CREATE TABLE IF NOT EXISTS `mapi_passkeys` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `credential_id` VARCHAR(500) NOT NULL,
    `public_key_pem` TEXT,
    `counter` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_credential_id` (`credential_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mapi_products
CREATE TABLE IF NOT EXISTS `mapi_products` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `type` VARCHAR(20) DEFAULT 'subscription',
    `price` DECIMAL(10,2) DEFAULT 0.00,
    `duration_days` INT DEFAULT 0,
    `description` TEXT,
    `status` TINYINT DEFAULT 1,
    `sort_order` INT DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mapi_mail_templates
CREATE TABLE IF NOT EXISTS `mapi_mail_templates` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL DEFAULT '',
    `subject` VARCHAR(200) NOT NULL DEFAULT '顺雅音乐 - 验证码邮件',
    `body` TEXT,
    `is_html` TINYINT NOT NULL DEFAULT 0,
    `is_default` TINYINT NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mapi_tokens
CREATE TABLE IF NOT EXISTS `mapi_tokens` (
    `token` VARCHAR(80) NOT NULL,
    `api_key` VARCHAR(255) NOT NULL,
    `expires_at` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`token`),
    KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mapi_logs
CREATE TABLE IF NOT EXISTS `mapi_logs` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `ip` VARCHAR(45) DEFAULT '',
    `referer` VARCHAR(500) DEFAULT '',
    `endpoint` VARCHAR(200) DEFAULT '',
    `user_agent` VARCHAR(500) DEFAULT '',
    `api_key` VARCHAR(64) DEFAULT '',
    `traffic_bytes` BIGINT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_api_key` (`api_key`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mapi_geetest
CREATE TABLE IF NOT EXISTS `mapi_geetest` (
    `captcha_id` VARCHAR(64) NOT NULL DEFAULT '',
    `key` VARCHAR(64) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- mapi_super_settings
CREATE TABLE IF NOT EXISTS `mapi_super_settings` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(50) NOT NULL,
    `setting_value` TEXT,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mapi_playlists` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `key_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL DEFAULT '',
    `type` VARCHAR(20) NOT NULL DEFAULT 'custom',
    `remote_id` VARCHAR(100) DEFAULT '',
    `server` VARCHAR(20) DEFAULT 'netease',
    `cover_url` VARCHAR(500) DEFAULT '',
    `cover_data` MEDIUMTEXT,
    `cover_mode` VARCHAR(20) DEFAULT 'auto',
    `sort_order` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `cover_updated_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_key_id` (`key_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mapi_song_covers` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `song_ref` VARCHAR(191) NOT NULL DEFAULT '',
    `key_id` INT NOT NULL DEFAULT 0,
    `cover_url` VARCHAR(500) DEFAULT '',
    `cover_data` MEDIUMTEXT,
    `updated_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_song_covers_ref` (`song_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mapi_songs` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `key_id` INT NOT NULL DEFAULT 0,
    `playlist_id` INT NOT NULL,
    `song_id` VARCHAR(64) NOT NULL DEFAULT '',
    `name` VARCHAR(255) NOT NULL DEFAULT '',
    `artist` VARCHAR(255) DEFAULT '',
    `server` VARCHAR(20) DEFAULT 'netease',
    `sort_order` INT DEFAULT 0,
    `missing` TINYINT NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_playlist_id` (`playlist_id`),
    KEY `idx_key_id` (`key_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mapi_stats` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `stat_date` DATE NOT NULL,
    `call_count` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_stat_date` (`stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 宿主域名授权与底部偏移 ──────────────────────────────────────────
-- 播放器被嵌入到哪个站，就按那个站的 Host 找这一行。判定顺序：
--   mapi_config.domain_authorize = 0（默认）→ 一律放行，只按本行的 auto/修正量走
--   mapi_config.domain_authorize = 1        → 域名必须在本表且 authorized=1，否则拒绝启动
--   mapi_config.domain_auto_add = 1（默认，缺失即视为 1）→ 主机名第一次加载播放器时自动登记并放行
-- 自动登记只补新行，不会动已有行（手动停用 authorized=0 的行不会被复活）。auto_added=1 标记自动登记。
-- auto=1 时客户端自动探测宿主底部导航栏高度；lyrics_bottom / player_bottom 是额外修正量，
-- player_bottom 为 NULL 表示跟随 lyrics_bottom。
CREATE TABLE IF NOT EXISTS `mapi_domains` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL DEFAULT 0,
    `domain` VARCHAR(190) NOT NULL DEFAULT '',
    `authorized` TINYINT(1) NOT NULL DEFAULT 1,
    `auto` TINYINT(1) NOT NULL DEFAULT 1,
    `lyrics_bottom` INT NOT NULL DEFAULT 0,
    `player_bottom` INT DEFAULT NULL,
    `note` VARCHAR(255) NOT NULL DEFAULT '',
    `auto_added` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_domain` (`user_id`, `domain`),
    KEY `idx_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;