<?php

// $action_key 由路由层传入，值为实际的action名

if ($action_key === 'user-delete') {
    if ((($_SESSION['admin_is_admin'] ?? 99) > 1)) { flash_set('err','无权限'); header('Location: ' . admin_url('users')); exit; }
    $uid = (int)($_POST['id'] ?? 0);
    $curLevel = (int)($_SESSION['admin_is_admin'] ?? 99);
    if ($uid === (int)$_SESSION['admin_id']) { flash_set('err','不能删除自己'); header('Location: ' . admin_url('users')); exit; }
    $tr = $db->query("SELECT is_admin FROM mapi_users WHERE id=$uid");
    $tgtLevel = $tr ? (int)$tr->fetch_assoc()['is_admin'] : 99;
    if ($curLevel >= $tgtLevel) { flash_set('err','无权操作同级或更高权限的用户'); header('Location: ' . admin_url('users')); exit; }
    $stmt = $db->prepare("DELETE FROM mapi_users WHERE id=?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    flash_set('msg','用户已删除');
    header('Location: ' . admin_url('users')); exit;
}
if ($action_key === 'user-admin') {
    if ((($_SESSION['admin_is_admin'] ?? 99) > 1)) { flash_set('err','无权限'); header('Location: ' . admin_url('users')); exit; }
    $uid = (int)($_POST['id'] ?? 0);
    $is = (int)($_POST['is_admin'] ?? 2);
    $curLevel = (int)($_SESSION['admin_is_admin'] ?? 99);
    if ($uid === (int)$_SESSION['admin_id']) { flash_set('err','不能修改自己'); header('Location: ' . admin_url('users')); exit; }
    // 只有超级管理员(is_admin=0)才能创建超级管理员
    if ($is < 1 && $curLevel > 0) { flash_set('err','无权限'); header('Location: ' . admin_url('users')); exit; }
    // 同级不能修改同级，只能上级修改下级
    $tr = $db->query("SELECT is_admin FROM mapi_users WHERE id=$uid");
    $tgtLevel = $tr ? (int)$tr->fetch_assoc()['is_admin'] : 99;
    if ($curLevel >= $tgtLevel) { flash_set('err','无权操作同级或更高权限的用户'); header('Location: ' . admin_url('users')); exit; }
    $stmt = $db->prepare("UPDATE mapi_users SET is_admin=? WHERE id=?");
    $stmt->bind_param('ii', $is, $uid);
    $stmt->execute();
    flash_set('msg','角色已更新');
    header('Location: ' . admin_url('users')); exit;
}

// ═══ 背景图预签名上传 URL（直传 S3，不经过服务器中转） ═══
