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

    // 「指定密钥」：0 = 不限（该账号所有密钥共用这一行），> 0 = 只对那条密钥生效。
    // 普通管理员 / 用户只能选自己名下的密钥；超管可以选任何人的，用来把某一行指派给别人的密钥。
    $keyId    = max(0, (int)($_POST['key_id'] ?? 0));
    $keyOwner = 0;
    if ($keyId > 0) {
        $keyRow = domains_key_get($db, $keyId);
        if (!$keyRow) {
            echo json_encode(['ok' => false, 'id' => 0, 'domain' => '', 'message' => '指定的密钥不存在', 'error' => 'key_not_found'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $keyOwner = (int)$keyRow['user_id'];
        if (!$__domIsSuper && $keyOwner !== $__domUserId) {
            echo json_encode(['ok' => false, 'id' => 0, 'domain' => '', 'message' => '不能指定别人的密钥', 'error' => 'key_forbidden'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    // 归属用户：选了密钥就是密钥的主人；新增且不限密钥时归当前操作者；编辑时留 0 表示「保持原主人」。
    $ownerId = $keyOwner > 0 ? $keyOwner : (($id > 0) ? 0 : $__domUserId);

    // 分端配置：表单里 PC 与移动端各一套（各自的「自动」开关 + 歌词修正 + 播放器修正）。
    // 字段是 array_key_exists 判断而不是 !empty —— 老页面（缓存里的旧表单）不带这些字段时，
    // 传 null 表示「这一端没设置」，让数据层回落到旧字段，避免把老配置悄悄改成手动模式。
    $hasPcAuto = array_key_exists('pc_auto', $_POST);
    $hasMoAuto = array_key_exists('mo_auto', $_POST);
    $pcAuto    = $hasPcAuto ? (!empty($_POST['pc_auto']) ? 1 : 0) : null;
    $moAuto    = $hasMoAuto ? (!empty($_POST['mo_auto']) ? 1 : 0) : null;
    $pcLyrics  = array_key_exists('pc_lyrics', $_POST) ? (int)$_POST['pc_lyrics'] : null;
    $moLyrics  = array_key_exists('mo_lyrics', $_POST) ? (int)$_POST['mo_lyrics'] : null;
    // 播放器修正留空 = 跟随同端歌词修正（存 NULL）
    $pcPlayer  = trim((string)($_POST['pc_player'] ?? ''));
    $moPlayer  = trim((string)($_POST['mo_player'] ?? ''));
    $pcPlayerV = ($pcPlayer === '') ? null : (int)$pcPlayer;
    $moPlayerV = ($moPlayer === '') ? null : (int)$moPlayer;

    $res = domains_save($db, $__domScopeUid, $domain, [
        'id'            => $id,
        'key_id'        => $keyId,
        'owner_id'      => $ownerId,
        'authorized'    => !empty($_POST['authorized']),
        // 旧字段：跟随 PC 端，给 1.6.x 及更早的客户端兜底
        'auto'          => $pcAuto === null ? 1 : $pcAuto,
        'lyrics_bottom' => $pcLyrics === null ? 0 : $pcLyrics,
        'player_bottom' => $pcPlayerV,
        // 分端字段
        'pc_auto'       => $pcAuto,
        'pc_lyrics'     => $pcLyrics,
        'pc_player'     => $pcPlayerV,
        'mo_auto'       => $moAuto,
        'mo_lyrics'     => $moLyrics,
        'mo_player'     => $moPlayerV,
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