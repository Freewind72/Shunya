<?php

require_once __DIR__ . '/../lib/domains.php';   // 数据层（本 handler 不内联 SQL）

// $action_key 由路由层传入，值为实际的 action 名
// 权限沿用本项目既有写法：is_admin > 1 只能看/操作自己的域名；0 / 1 可管理全部。

$__domIsSuper  = (($_SESSION['admin_is_admin'] ?? 99) <= 1);
$__domUserId   = (int)($_SESSION['admin_id'] ?? 0);
$__domScopeUid = $__domIsSuper ? 0 : $__domUserId;   // 0 = 不限用户
$__domBack     = '?action=domains';

// ── 列表（后台 AJAX 预检用）─────────────────────────────────────────
if ($action_key === 'domains-list') {
    header('Content-Type: application/json; charset=utf-8');
    $res = domains_list($db, $__domScopeUid);
    echo json_encode([
        'ok'    => $res['ok'],
        'error' => $res['error'],
        'rows'  => $res['rows'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 保存（新增 / 编辑）─────────────────────────────────────────────
if ($action_key === 'domains-save') {
    csrf_require();
    header('Content-Type: application/json; charset=utf-8');

    $id     = (int)($_POST['id'] ?? 0);
    $domain = trim((string)($_POST['domain'] ?? ''));

    // player_bottom 留空 = 跟随 lyrics_bottom（存 NULL）
    $pbRaw = trim((string)($_POST['player_bottom'] ?? ''));
    $res = domains_save($db, $__domUserId, $domain, [
        'id'            => $id,
        'authorized'    => !empty($_POST['authorized']),
        'auto'          => !empty($_POST['auto']),
        'lyrics_bottom' => (int)($_POST['lyrics_bottom'] ?? 0),
        'player_bottom' => ($pbRaw === '' ? null : (int)$pbRaw),
        'note'          => (string)($_POST['note'] ?? ''),
    ]);

    echo json_encode([
        'ok'      => $res['ok'],
        'id'      => (int)$res['id'],
        'domain'  => domain_normalize($domain),
        'message' => $res['ok'] ? ($id > 0 ? '域名配置已更新' : '域名已添加') : $res['error'],
        'error'   => $res['error'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 删除 ───────────────────────────────────────────────────────────
if ($action_key === 'domains-delete') {
    csrf_require();
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0 && domains_delete($db, $__domScopeUid, $id)) {
        flash_set('msg', '域名已删除');
    } else {
        flash_set('err', '删除失败，或没有权限');
    }
    header('Location: ' . $__domBack); exit;
}

// ── 全局「启用域名授权」开关 ────────────────────────────────────────
if ($action_key === 'domains-authorize') {
    if (!$__domIsSuper) {
        flash_set('err', '仅管理员可修改全局开关');
        header('Location: ' . $__domBack); exit;
    }
    csrf_require();
    $on = !empty($_POST['domain_authorize']);
    if (domain_authorize_set($db, $on)) {
        flash_set('msg', $on ? '域名授权已启用：未授权域名将无法启动播放器' : '域名授权已关闭：任何站都能使用播放器');
    } else {
        flash_set('err', '开关保存失败');
    }
    header('Location: ' . $__domBack); exit;
}

// ── 「自动添加检测到的域名」开关 ────────────────────────────────────
if ($action_key === 'domains-auto-add') {
    if (!$__domIsSuper) {
        flash_set('err', '仅管理员可修改全局开关');
        header('Location: ' . $__domBack); exit;
    }
    csrf_require();
    $on = !empty($_POST['domain_auto_add']);
    if (domain_auto_add_set($db, $on)) {
        flash_set('msg', $on
            ? '自动添加已开启：主机名第一次加载播放器就会自动登记并放行'
            : '自动添加已关闭：未登记的域名会被拒绝，需要在这里手动添加');
    } else {
        flash_set('err', '开关保存失败');
    }
    header('Location: ' . $__domBack); exit;
}