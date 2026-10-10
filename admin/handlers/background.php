<?php

// $action_key 由路由层传入，值为实际的action名

if ($action_key === 'bg-presign') {
    header('Content-Type: application/json; charset=utf-8');
    if (!s3_available()) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'S3 未配置']); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    $_POST['_csrf'] = $input['_csrf'] ?? ''; csrf_require();
    $mime = $input['mime'] ?? '';
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($allowed[$mime])) { http_response_code(400); echo json_encode(['ok' => false, 'error' => '不支持的图片格式']); exit; }
    $ext = $allowed[$mime];
    // key 落在自己的命名空间里（backgrounds/u<id>_…），并带随机串：换图时 URL 一定变，
    // 不会出现「同名同后缀 → 存储 URL 不变 → 浏览器/CDN 继续拿旧图」
    $uid = (int)$_SESSION['admin_id'];
    $key = upload_new_key('backgrounds', $uid, $ext);
    $result = s3_presigned_put_url($key, $mime, 300);
    if ($result['ok']) {
        $_SESSION['bg_pending'] = $key;   // 只认本次发出去的那一个，confirm 时核对
        echo json_encode(['ok' => true, 'url' => $result['url'], 'key' => $key]);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $result['error'] ?? '生成签名失败']);
    }
    exit;
}

// ═══ 背景图上传确认（客户端直传完成后调用） ═══
if ($action_key === 'bg-confirm') {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true);
    $_POST['_csrf'] = $input['_csrf'] ?? ''; csrf_require();
    $key = (string)($input['key'] ?? '');
    // key 必须落在当前账号的命名空间里（光看形状的话，可以把别人的对象登记到自己名下，
    // 之后一换背景/删背景就把别人的图删了）
    if ($key === '' || !preg_match('#^backgrounds/[A-Za-z0-9_\x{4e00}-\x{9fa5}.\-]+$#u', $key)) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => '缺少或非法的 key']); exit;
    }
    $uid = (int)$_SESSION['admin_id'];
    if (!upload_key_is_own($key, 'backgrounds', $uid)) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => '这个文件不属于当前账号']); exit;
    }
    $pending = (string)($_SESSION['bg_pending'] ?? '');
    if ($pending !== '' && !hash_equals($pending, $key)) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => '上传凭证已失效，请重新上传']); exit;
    }
    unset($_SESSION['bg_pending']);
    // 删除旧背景图（只删自己的对象）
    $oldBg = (string)($_SESSION['admin_background'] ?? '');
    if ($oldBg !== '' && $oldBg !== $key && upload_key_is_own($oldBg, 'backgrounds', $uid)) s3_delete($oldBg);
    $stmt = $db->prepare("UPDATE mapi_users SET background=? WHERE id=?");
    $stmt->bind_param('si', $key, $uid);
    $stmt->execute();
    $_SESSION['admin_background'] = $key;
    echo json_encode(['ok' => true, 'url' => s3_get_url($key)]);
    exit;
}

// ═══ 动态壁纸 URL 保存 ═══
if ($action_key === 'bg-url-save') {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true);
    $_POST['_csrf'] = $input['_csrf'] ?? ''; csrf_require();
    $url = trim($input['url'] ?? '');
    $uid = (int)$_SESSION['admin_id'];
    if ($url && !filter_var($url, FILTER_VALIDATE_URL)) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'URL 格式不正确']); exit;
    }
    if (mb_strlen($url) > 500) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => 'URL 过长']); exit;
    }
    $colCheck = $db->query("SHOW COLUMNS FROM mapi_users LIKE 'background_url'");
    if (!$colCheck || $colCheck->num_rows === 0) {
        $db->query("ALTER TABLE mapi_users ADD COLUMN background_url VARCHAR(500) DEFAULT '' AFTER background");
    }
    $stmt = $db->prepare("UPDATE mapi_users SET background_url=? WHERE id=?");
    $stmt->bind_param('si', $url, $uid);
    $stmt->execute();
    $_SESSION['admin_background_url'] = $url;
    setcookie('mapi_bg', $url, time()+31536000, '/');
    echo json_encode(['ok' => true, 'url' => $url]);
    exit;
}

// ═══ 个人资料 ═══
