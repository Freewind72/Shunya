<?php

// $action_key 由路由层传入，值为实际的action名

if (in_array($action_key, ['settings','settings-site','settings-mail','settings-security','settings-api','settings-storage'], true)) {
    csrf_require();
    if (isset($_POST['_geetest_submit'])) {
        $geetestCaptchaId = trim($_POST['geetest_captcha_id'] ?? '');
        $geetestKey = trim($_POST['geetest_key'] ?? '');
        $r = $db->query("SELECT COUNT(*) as cnt FROM mapi_geetest");
        $exists = $r && (int)$r->fetch_assoc()['cnt'] > 0;
        if ($exists) {
            $st = $db->prepare("UPDATE mapi_geetest SET captcha_id=?, `key`=?");
            $st->bind_param('ss', $geetestCaptchaId, $geetestKey);
        } else {
            $st = $db->prepare("INSERT INTO mapi_geetest (captcha_id, `key`) VALUES (?, ?)");
            $st->bind_param('ss', $geetestCaptchaId, $geetestKey);
        }
        if ($st->execute()) flash_set('msg', '极验配置已保存');
        else flash_set('err', '保存失败');
    }
    if (isset($_POST['_ann_submit'])) {
        $annContent = trim($_POST['announcement'] ?? '');
        $annSave = [
            'enabled' => $annContent !== '',
            'title' => '系统公告',
            'content' => $annContent,
            'align' => in_array($_POST['ann_align'] ?? '', ['left','center']) ? $_POST['ann_align'] : 'left',
            'updated_at' => time(),
        ];
        $annJson = json_encode($annSave, JSON_UNESCAPED_UNICODE);
        $annSt = $db->prepare("REPLACE INTO mapi_config (config_key, config_value) VALUES ('announcement', ?)");
        $annSt->bind_param('s', $annJson);
        if ($annSt->execute()) flash_set('msg', '公告已' . ($annContent !== '' ? '发布' : '关闭'));
        else flash_set('err', '公告保存失败');
    }
    if (isset($_POST['_login_submit'])) {
        $loginTheme = in_array($_POST['login_theme'] ?? '', ['light','dark']) ? $_POST['login_theme'] : 'light';
        $loginBg = mb_substr(trim($_POST['login_bg'] ?? ''), 0, 500);
        $st = $db->prepare("REPLACE INTO mapi_config (config_key, config_value) VALUES ('login_theme', ?)");
        $st->bind_param('s', $loginTheme); $st->execute();
        $st = $db->prepare("REPLACE INTO mapi_config (config_key, config_value) VALUES ('login_bg', ?)");
        $st->bind_param('s', $loginBg); $st->execute();
        flash_set('msg', '登录页配置已保存');
    }
    if (isset($_POST['_key_limit_submit']) && ($_SESSION['admin_is_admin'] ?? 99) === 0) {
        $limit = max(1, min(100, (int)($_POST['key_limit'] ?? 1)));
        $json = json_encode(['limit' => $limit], JSON_UNESCAPED_UNICODE);
        $st = $db->prepare("REPLACE INTO mapi_config (config_key, config_value) VALUES ('key_limit', ?)");
        $st->bind_param('s', $json);
        if ($st->execute()) flash_set('msg', '密钥限制已更新为 ' . $limit . ' 个');
        else flash_set('err', '保存失败');
    }
    if (isset($_POST['_s3_submit']) && ($_SESSION['admin_is_admin'] ?? 99) === 0) {
        $s3Save = [
            'endpoint' => trim($_POST['s3_endpoint'] ?? ''),
            'access_key' => trim($_POST['s3_access_key'] ?? ''),
            'secret_key' => trim($_POST['s3_secret_key'] ?? ''),
            'bucket' => trim($_POST['s3_bucket'] ?? ''),
            'region' => trim($_POST['s3_region'] ?? 'auto'),
            'path_prefix' => trim($_POST['s3_path_prefix'] ?? ''),
            'custom_domain' => trim($_POST['s3_custom_domain'] ?? ''),
        ];
        $json = json_encode($s3Save, JSON_UNESCAPED_UNICODE);
        $st = $db->prepare("REPLACE INTO mapi_config (config_key, config_value) VALUES ('s3', ?)");
        $st->bind_param('s', $json);
        if ($st->execute()) flash_set('msg', 'S3 配置已保存');
        else flash_set('err', '保存失败');
    }
    if (isset($_POST['_api_submit']) && ($_SESSION['admin_is_admin'] ?? 99) === 0) {
        $apiSave = [
            'meting' => trim($_POST['api_meting'] ?? ''),
            'qq_referer' => trim($_POST['api_qq_referer'] ?? ''),
            'qq_cover' => trim($_POST['api_qq_cover'] ?? ''),
            'param_id' => trim($_POST['api_param_id'] ?? 'id') ?: 'id',
            'param_auth' => trim($_POST['api_param_auth'] ?? 'auth') ?: 'auth',
            'req_params' => [
                'server' => trim($_POST['api_req_server'] ?? 'server') ?: 'server',
                'type' => trim($_POST['api_req_type'] ?? 'type') ?: 'type',
                'id' => trim($_POST['api_req_id'] ?? 'id') ?: 'id',
            ],
            'fields' => [
                'title' => trim($_POST['api_field_title'] ?? 'title') ?: 'title',
                'artist' => trim($_POST['api_field_artist'] ?? 'author') ?: 'author',
                'url' => trim($_POST['api_field_url'] ?? 'url') ?: 'url',
                'pic' => trim($_POST['api_field_pic'] ?? 'pic') ?: 'pic',
                'lrc' => trim($_POST['api_field_lrc'] ?? 'lrc') ?: 'lrc',
            ],
        ];
        $json = json_encode($apiSave, JSON_UNESCAPED_UNICODE);
        $st = $db->prepare("REPLACE INTO mapi_config (config_key, config_value) VALUES ('mapi_api', ?)");
        $st->bind_param('s', $json);
        if ($st->execute()) flash_set('msg', '音乐 API 配置已保存');
        else flash_set('err', '保存失败');

        $cfgData = require $configFile;
        if (!isset($cfgData['api'])) $cfgData['api'] = [];
        $cfgData['api']['base_url']   = $apiSave['meting'];
        $cfgData['api']['qq_referer'] = $apiSave['qq_referer'];
        $cfgData['api']['qq_cover']   = $apiSave['qq_cover'];
        $newContent = "<?php\nreturn " . var_export($cfgData, true) . ";\n";
        file_put_contents($configFile, $newContent);
    }
    if (isset($_POST['smtp_host']) && ($_SESSION['admin_is_admin'] ?? 99) === 0) {
        $smtpSave = [
            'host' => trim($_POST['smtp_host'] ?? ''),
            'port' => (int)($_POST['smtp_port'] ?? 465),
            'user' => trim($_POST['smtp_user'] ?? ''),
            'pass' => trim($_POST['smtp_pass'] ?? ''),
            'encrypt' => in_array($_POST['smtp_encrypt'] ?? '', ['ssl','tls','none']) ? $_POST['smtp_encrypt'] : 'ssl',
            'from' => trim($_POST['smtp_from'] ?? ''),
            'name' => trim($_POST['smtp_name'] ?? ''),
        ];
        $json = json_encode($smtpSave, JSON_UNESCAPED_UNICODE);
        $st = $db->prepare("REPLACE INTO mapi_config (config_key, config_value) VALUES ('smtp', ?)");
        $st->bind_param('s', $json);
        if ($st->execute()) flash_set('msg', 'SMTP 配置已保存');
        else flash_set('err', '保存失败');
    }
    // 邮件模板 — 自动建表 + 迁移旧数据
    if (($_SESSION['admin_is_admin'] ?? 99) === 0) {
        $isMysql = !defined('DB_SQLITE');
        if ($isMysql) {
            $db->query("CREATE TABLE IF NOT EXISTS `mapi_mail_templates` (`id` INT NOT NULL AUTO_INCREMENT, `name` VARCHAR(100) NOT NULL DEFAULT '', `subject` VARCHAR(200) NOT NULL DEFAULT '顺雅音乐 - 验证码邮件', `body` TEXT, `is_html` TINYINT NOT NULL DEFAULT 0, `is_default` TINYINT NOT NULL DEFAULT 0, `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP, `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $db->query("CREATE TABLE IF NOT EXISTS mapi_mail_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL DEFAULT '', subject VARCHAR(200) NOT NULL DEFAULT '顺雅音乐 - 验证码邮件', body TEXT, is_html INTEGER NOT NULL DEFAULT 0, is_default INTEGER NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        }
        // 迁移旧 mail_template 配置到新表
        $cnt = $db->query("SELECT COUNT(*) as c FROM mapi_mail_templates");
        if ($cnt && ($crow = $cnt->fetch_assoc()) && (int)$crow['c'] === 0) {
            $rold = $db->query("SELECT config_value FROM mapi_config WHERE config_key='mail_template'");
            if ($rold && $oldrow = $rold->fetch_assoc()) {
                $tpl = json_decode($oldrow['config_value'], true);
                if (is_array($tpl)) {
                    $nm = trim($tpl['name'] ?? '') ?: '默认模板';
                    $sj = trim($tpl['subject'] ?? '') ?: '顺雅音乐 - 验证码邮件';
                    $bd = $tpl['body'] ?? '';
                    $ht = !empty($tpl['html']) ? 1 : 0;
                    $ins = $db->prepare("INSERT INTO mapi_mail_templates (name, subject, body, is_html, is_default) VALUES (?, ?, ?, ?, 1)");
                    if ($ins) { $ins->bind_param('sssi', $nm, $sj, $bd, $ht); $ins->execute(); }
                }
            }
        }
        // 保存/新增模板（AJAX）
        if (isset($_POST['_mail_tpl_save'])) {
            $tplId = (int)($_POST['_mail_tpl_id'] ?? 0);
            $nm = trim($_POST['mail_tpl_name'] ?? '');
            if ($nm === '') $nm = '未命名模板';
            $sj = trim($_POST['mail_tpl_subject'] ?? '') ?: '顺雅音乐 - 验证码邮件';
            $bd = trim($_POST['mail_tpl_body'] ?? '');
            $ht = !empty($_POST['mail_tpl_html']) ? 1 : 0;
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            if ($tplId > 0) {
                $st = $db->prepare("UPDATE mapi_mail_templates SET name=?, subject=?, body=?, is_html=? WHERE id=?");
                $st->bind_param('sssii', $nm, $sj, $bd, $ht, $tplId);
            } else {
                $st = $db->prepare("INSERT INTO mapi_mail_templates (name, subject, body, is_html) VALUES (?, ?, ?, ?)");
                $st->bind_param('sssi', $nm, $sj, $bd, $ht);
            }
            if ($st->execute()) {
                $newId = $tplId > 0 ? $tplId : $db->insert_id;
                if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'id' => $newId, 'name' => $nm, 'subject' => $sj, 'body' => $bd, 'is_html' => $ht]); exit; }
                flash_set('msg', '邮件模板已保存');
            } else {
                if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'error' => '保存失败']); exit; }
                flash_set('err', '保存失败');
            }
        }
        // 删除模板（AJAX）
        if (isset($_POST['_mail_tpl_delete'])) {
            $id = (int)($_POST['_mail_tpl_id'] ?? 0);
            $db->query("DELETE FROM mapi_mail_templates WHERE id=" . $id);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true]); exit;
        }
        // 设为默认（AJAX）
        if (isset($_POST['_mail_tpl_default'])) {
            $id = (int)($_POST['_mail_tpl_id'] ?? 0);
            $db->query("UPDATE mapi_mail_templates SET is_default=0");
            $st = $db->prepare("UPDATE mapi_mail_templates SET is_default=1 WHERE id=?");
            $st->bind_param('i', $id); $st->execute();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true]); exit;
        }
        // 非 AJAX 则重定向
        if (!(isset($_POST['_mail_tpl_save']) || isset($_POST['_mail_tpl_delete']) || isset($_POST['_mail_tpl_default']))) {
            header('Location: ?action=' . $action_key); exit;
        }
        exit;
    }
    header('Location: ?action=' . $action_key); exit;
}