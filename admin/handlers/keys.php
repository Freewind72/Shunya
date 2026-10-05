<?php

if ($action === 'keys-create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $isAdmin = $_SESSION['admin_is_admin'] ?? 99;
    if ($isAdmin > 1) {
        $limit = 1;
        $r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='key_limit'");
        if ($r && $row = $r->fetch_assoc()) {
            $limitCfg = json_decode($row['config_value'], true);
            if (is_array($limitCfg) && isset($limitCfg['limit'])) $limit = max(1, (int)$limitCfg['limit']);
        }
        $cntR = $db->query("SELECT COUNT(*) as cnt FROM mapi_keys WHERE user_id=" . (int)$_SESSION['admin_id']);
        $cnt = $cntR ? (int)$cntR->fetch_assoc()['cnt'] : 0;
        if ($cnt >= $limit) {
            flash_set('err', '已达到密钥创建上限（' . $limit . ' 个），请联系管理员');
            header('Location: ?action=keys'); exit;
        }
    }
    // 名称：创建弹窗里必填，用来区分「这条密钥是给哪个站/哪个用途的」
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        flash_set('err', '请先填写密钥名称');
        header('Location: ?action=keys&create=1'); exit;
    }
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 32) : substr($name, 0, 32);

    // 授权域名：可留空（留空=不绑定域名）；填了则校验格式，并同时写进 mapi_domains 授权
    require_once __DIR__ . '/../lib/domains.php';
    $domain = '';
    $domainRaw = trim((string)($_POST['domain'] ?? ''));
    if ($domainRaw !== '') {
        $domain = domain_normalize($domainRaw);
        if ($domain === '' || strlen($domain) > 190) {
            flash_set('err', '域名格式不正确：只填主机名，例如 blog.example.com');
            header('Location: ?action=keys&create=1'); exit;
        }
    }

    $k = bin2hex(random_bytes(16));
    $stmt = $db->prepare("INSERT INTO mapi_keys (user_id, api_key, name, domain, status) VALUES (?, ?, ?, ?, 1)");
    $stmt->bind_param('isss', $_SESSION['admin_id'], $k, $name, $domain);
    if (!$stmt->execute()) {
        flash_set('err', '创建失败');
        header('Location: ?action=keys&create=1'); exit;
    }

    $domainMsg = '';
    if ($domain !== '') {
        $uid = (int)$_SESSION['admin_id'];
        if (!domains_table_exists($db)) {
            $domainMsg = '；但域名表不存在，请先执行 install/sql/mysql.sql 补表';
        } else {
            $exRow = null;
            $ex = $db->prepare("SELECT id, authorized FROM mapi_domains WHERE user_id=? AND domain=? LIMIT 1");
            if ($ex) {
                $ex->bind_param('is', $uid, $domain);
                $ex->execute();
                $exRes = $ex->get_result();
                $exRow = $exRes ? $exRes->fetch_assoc() : null;
            }
            if ($exRow) {
                // 已有这条域名：只把「已授权」打开，其余设置（偏移/备注/自动探测）不动
                if ((int)$exRow['authorized'] !== 1) {
                    $up = $db->prepare("UPDATE mapi_domains SET authorized=1 WHERE id=?");
                    if ($up) { $up->bind_param('i', $exRow['id']); $up->execute(); }
                }
                $domainMsg = '，并把域名 ' . $domain . ' 设为已授权';
            } else {
                $note = '密钥「' . $name . '」创建时授权';
                $ins = $db->prepare("INSERT INTO mapi_domains (user_id, domain, authorized, auto, lyrics_bottom, player_bottom, note, auto_added) VALUES (?, ?, 1, 1, 0, NULL, ?, 0)");
                if ($ins) {
                    $ins->bind_param('iss', $uid, $domain, $note);
                    if ($ins->execute()) $domainMsg = '，并授权域名 ' . $domain;
                    else $domainMsg = '；但域名授权失败，请到「域名」页手动添加';
                } else {
                    $domainMsg = '；但域名授权失败，请到「域名」页手动添加';
                }
            }
        }
    }
    flash_set('msg', '密钥「' . $name . '」已创建' . $domainMsg);
    header('Location: ?action=keys'); exit;
}

if ($action === 'keys-delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $kid = (int)($_POST['id'] ?? 0);
    if (($_SESSION['admin_is_admin'] ?? 99) === 0) {
        $stmt = $db->prepare("DELETE FROM mapi_keys WHERE id=?");
        $stmt->bind_param('i', $kid);
    } else {
        $stmt = $db->prepare("DELETE FROM mapi_keys WHERE id=? AND user_id=?");
        $stmt->bind_param('ii', $kid, $_SESSION['admin_id']);
    }
    $stmt->execute();
    if ($stmt->affected_rows > 0) flash_set('msg','密钥已删除');
    else flash_set('err','无权限');
    header('Location: ?action=keys'); exit;
}