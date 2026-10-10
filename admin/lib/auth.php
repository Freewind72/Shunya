<?php defined('MAPI_ADMIN') or die('禁止直接访问');
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf_token'];
}
function csrf_require() {
    $t = $_POST['_csrf'] ?? '';
    if ($t && hash_equals($_SESSION['csrf_token'] ?? '', $t)) return;

    // 失败时：接口/JSON 调用照旧 403（前端能拿到 JSON 处理）；
    // 但**普通表单**改成"带提示跳回原页" —— 用户看到一页光秃秃的「CSRF 验证失败」
    // 只会以为"保存坏了/页面过期就是不让存"，不知道该怎么办（也解释不了"保存没生效"）。
    $json = stripos((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''), 'xmlhttprequest') !== false
         || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
         || stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false;
    if ($json) {
        http_response_code(403);
        die(json_encode(['ok' => false, 'msg' => 'CSRF 验证失败']));
    }
    if (function_exists('flash_set')) {
        flash_set('err', '页面已过期（CSRF 校验未通过）：你这次的改动**没有**写入，请刷新页面后重新保存。');
    }
    http_response_code(303);
    header('Location: ?action=' . urlencode((string)($_GET['action'] ?? '')));
    exit;
}

function login_rl(): void {
    require_once __DIR__ . '/../../assets/lib/helpers.php';
    login_rate_limit();
}

function require_login() {
    if (empty($_SESSION['admin_id'])) { header('Location: ?'); exit; }
}

function require_admin() {
    if ((($_SESSION['admin_is_admin'] ?? 99) > 1)) { header('Location: ?action=dashboard'); exit; }
}
function require_super_admin() {
    if ((($_SESSION['admin_is_admin'] ?? 99) > 0)) { header('Location: ?action=dashboard'); exit; }
}

// 探测 mapi_users 表实际存在的列 (结果在单次请求内缓存)
function mapi_users_columns($db, bool $refresh = false): array {
    static $cols = null;
    if (is_array($cols) && !$refresh) return $cols;
    $cols = [];
    if (!$db) return $cols;
    try {
        $isSqlite = stripos(get_class($db), 'sqlite') !== false;
        $r = $db->query($isSqlite
            ? "SELECT name FROM pragma_table_info('mapi_users')"
            : "SHOW COLUMNS FROM `mapi_users`");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                if (!is_array($row)) break;
                $name = $isSqlite ? ($row['name'] ?? '') : ($row['Field'] ?? '');
                if ($name !== '') $cols[] = (string)$name;
            }
        }
    } catch (Throwable $e) {
        error_log('MAPI: mapi_users 列探测失败: ' . $e->getMessage());
    }
    return $cols;
}

// 返回可直接拼进 SELECT 的列清单: 期望列 ∩ mapi_users 实际存在的列
function mapi_users_select_cols($db): string {
    $base = ['id', 'username', 'password', 'qq', 'email', 'is_admin'];
    $want = array_merge($base, [
        'auto_theme', 'theme_mode', 'lyrics_default', 'autoplay_default',
        'player_pos', 'player_skin', 'player_skin_cfg', 'background', 'background_url', 'admin_theme',
    ]);
    $have = mapi_users_columns($db);
    if (!$have) return implode(',', $base);
    $out = [];
    foreach ($want as $c) {
        if (in_array($c, $have, true) && !in_array($c, $out, true)) $out[] = $c;
    }
    return $out ? implode(',', $out) : implode(',', $base);
}

// 幂等补列: 老库缺列时 ALTER 补上 (同一请求内失败过就不再重试) .
function mapi_users_ensure_column($db, string $col, string $ddl): bool {
    if (!$db || !preg_match('/^[a-z_][a-z0-9_]*$/i', $col)) return false;
    if (in_array($col, mapi_users_columns($db), true)) return true;
    if (!empty($GLOBALS['__mapi_col_failed'][$col])) return false;
    try {
        $db->query('ALTER TABLE `mapi_users` ADD COLUMN `' . $col . '` ' . $ddl);
        mapi_users_columns($db, true); // 刷新单请求缓存
        return true;
    } catch (Throwable $e) {
        error_log('MAPI: 补列失败 ' . $col . ': ' . $e->getMessage());
        $GLOBALS['__mapi_col_failed'][$col] = true;
        return false;
    }
}