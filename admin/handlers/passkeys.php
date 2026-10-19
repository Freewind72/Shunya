<?php

// $action_key 由路由层传入，值为实际的action名

if ($action_key === 'pk-begin') {
    $rpId = webauthn_get_rp_id();
    if (!webauthn_is_valid_rp_id($rpId)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'WebAuthn不支持IP地址访问，请使用域名或localhost访问']);
        exit;
    }
    $challenge = random_bytes(32);
    $_SESSION['pk_challenge'] = base64_encode($challenge);
    $uid = (int)$_SESSION['admin_id'];
    $stmt = $db->prepare("SELECT credential_id FROM mapi_passkeys WHERE user_id=?");
    $stmt->bind_param('i', $uid); $stmt->execute();
    $excluded = [];
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $excluded[] = base64url_encode(base64_decode($row['credential_id']));
    $_SESSION['pk_rp_id'] = $rpId;
    $_SESSION['pk_origin'] = webauthn_get_origin();
    header('Content-Type: application/json');
    echo json_encode(['challenge' => base64url_encode($challenge), 'rpId' => $rpId, 'rpName' => '顺雅音乐', 'userId' => $uid, 'userName' => $_SESSION['admin_user'], 'excludeCredentials' => $excluded, 'timeout' => 300000, '_csrf' => csrf_token()]);
    exit;
}
if ($action_key === 'pk-complete') {
    csrf_require();
    $ao = $_POST['attestationObject'] ?? ''; $cjd = $_POST['clientDataJSON'] ?? '';
    $info = webauthn_verify_attestation($ao, $cjd);
    if (!is_array($info)) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'err'=>$info ?: '验证失败']); exit; }
    $cid = $info['credential_id']; $pem = $info['public_key_pem']; $cnt = $info['counter'];
    $st = $db->prepare("SELECT id FROM mapi_passkeys WHERE credential_id=?");
    $st->bind_param('s', $cid); $st->execute();
    if ($st->get_result()->fetch_assoc()) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'err'=>'通行密钥已存在']); exit; }
    $st2 = $db->prepare("INSERT INTO mapi_passkeys (user_id, credential_id, public_key_pem, counter) VALUES (?,?,?,?)");
    $st2->bind_param('issi', $_SESSION['admin_id'], $cid, $pem, $cnt);
    if ($st2->execute()) {
        unset($_SESSION['pk_challenge'], $_SESSION['pk_rp_id'], $_SESSION['pk_origin']);
        header('Content-Type: application/json'); echo json_encode(['ok'=>true]);
    } else { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'err'=>'存储失败']); }
    exit;
}
if ($action_key === 'pk-delete') {
    csrf_require();
    $pid = (int)($_POST['id'] ?? 0);
    $st = $db->prepare("DELETE FROM mapi_passkeys WHERE id=? AND user_id=?");
    $st->bind_param('ii', $pid, $_SESSION['admin_id']);
    $st->execute();
    if ($st->affected_rows > 0) flash_set('msg','通行密钥已删除');
    else flash_set('err','无权限');
    header('Location: ' . admin_url('profile')); exit;
}

// ═══ 服务设置 ═══
