<?php

// $action_key 由路由层传入，值为实际的action名
//
// 歌词条字体的「上传 / 替换 / 删除」只发生在这三个入口（另见 handlers/config_user.php：
// 表单里换成外部 URL 时会删掉上传的文件）：
//   lrc-font-presign → 只生成直传签名，不写库
//   lrc-font-confirm → 客户端直传完成后登记：换新文件时删掉旧文件，并清空外部 URL（上传优先）
//   lrc-font-clear   → 删掉上传的文件 + 清空四项设置（回到默认字体）
//
// 三个入口共同遵守一条：对象 key 必须属于当前账号（functions 见 admin/lib/helpers.php 的
// lrc_font_key_prefix / lrc_font_new_key / lrc_font_key_is_own），删除只落在自己的对象上。

if ($action_key === 'lrc-font-presign') {
    header('Content-Type: application/json; charset=utf-8');
    if (!s3_available()) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => '未配置存储，只能填字体 URL']); exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
    $_POST['_csrf'] = $input['_csrf'] ?? ''; csrf_require();
    $types = lrc_font_types();
    // 以文件后缀为准（不同系统给出的 mime 不一定可靠），后缀不认再拿 mime 兜一次
    $ext = strtolower(trim((string)($input['ext'] ?? '')));
    if (!isset($types[$ext])) {
        $mime = strtolower(trim((string)($input['mime'] ?? '')));
        foreach ($types as $e => $m) { if ($m === $mime) { $ext = $e; break; } }
    }
    if (!isset($types[$ext])) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => '只支持 .woff2 / .woff / .ttf / .otf 字体文件']); exit;
    }
    // key 落在自己的命名空间里（fonts/u<id>_…），并带随机串：换字体时 URL 一定变，
    // 不会出现「同名同后缀 → 存储 URL 不变 → 浏览器/CDN 继续拿旧字体」
    $uid = (int)$_SESSION['admin_id'];
    $key = lrc_font_new_key($uid, $ext);
    $result = s3_presigned_put_url($key, $types[$ext], 300);
    if (!empty($result['ok'])) {
        $_SESSION['lrc_font_pending'] = $key;   // 只认本次发出去的那一个，confirm 时核对
        echo json_encode(['ok' => true, 'url' => $result['url'], 'key' => $key]);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $result['error'] ?? '生成签名失败']);
    }
    exit;
}

if ($action_key === 'lrc-font-confirm') {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
    $_POST['_csrf'] = $input['_csrf'] ?? ''; csrf_require();
    $key = trim((string)($input['key'] ?? ''));
    // key 只允许本站自己生成的那种，且必须落在当前账号的命名空间里：
    // 光校验形状的话，别人可以把我的对象 key 登记到自己名下，之后一换/一清就会把我的字体删掉
    if ($key === '' || !preg_match('#^fonts/[A-Za-z0-9_\x{4e00}-\x{9fa5}.\-]+$#u', $key)) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => '缺少或非法的 key']); exit;
    }
    $types = lrc_font_types();
    if (!isset($types[strtolower(pathinfo($key, PATHINFO_EXTENSION))])) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => '不支持的字体格式']); exit;
    }
    $uid = (int)$_SESSION['admin_id'];
    if (!lrc_font_key_is_own($key, $uid)) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => '这个文件不属于当前账号']); exit;
    }
    $pending = (string)($_SESSION['lrc_font_pending'] ?? '');
    if ($pending !== '' && !hash_equals($pending, $key)) {
        http_response_code(400); echo json_encode(['ok' => false, 'error' => '上传凭证已失效，请重新上传']); exit;
    }

    // 换字体文件：把上一个上传的删掉，别在存储里留孤儿（只删自己的对象）
    $cur = lrc_font_get($db, $uid);
    if ($cur['key'] !== '' && $cur['key'] !== $key && lrc_font_key_is_own($cur['key'], $uid)) s3_delete($cur['key']);
    unset($_SESSION['lrc_font_pending']);

    // 上传优先：登记新文件的同时清空外部 URL，避免两个来源打架
    $stmt = $db->prepare("UPDATE mapi_users SET lrc_font=?, lrc_font_url='' WHERE id=?");
    $stmt->bind_param('si', $key, $uid);
    $stmt->execute();
    echo json_encode(['ok' => true, 'key' => $key, 'url' => s3_get_url($key)]);
    exit;
}

if ($action_key === 'lrc-font-clear') {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
    $_POST['_csrf'] = $input['_csrf'] ?? ''; csrf_require();
    $uid = (int)$_SESSION['admin_id'];

    $cur = lrc_font_get($db, $uid);
    if ($cur['key'] !== '' && lrc_font_key_is_own($cur['key'], $uid)) s3_delete($cur['key']);

    $stmt = $db->prepare("UPDATE mapi_users SET lrc_font='', lrc_font_url='', lrc_font_name='', lrc_font_size=0 WHERE id=?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    echo json_encode(['ok' => true]);
    exit;
}
