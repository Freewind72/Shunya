<?php defined('MAPI_ADMIN') or defined('MAPI_PLAYER_API') or die('禁止直接访问');
// MAPI_ADMIN：后台页面 / handler 入口；MAPI_PLAYER_API：播放器 api.php（与后台共用这份数据层）
// domains.php — 宿主域名授权 / 底部偏移数据层
//
// 表结构只在 install/sql/mysql.sql 里定义，这里【绝不】自动建表：
// 表不存在时所有函数都安全降级（返回空数组 / false，并把原因放进 last_error），
// 由页面提示用户去跑 /install/upgrade.php 或 mysql.sql。
//
// 兼容 MySQL 与 SQLite 两种后端：
//   - player_bottom 允许为 NULL（NULL = 跟随 lyrics_bottom），但两种后端绑定 NULL 的方式不同，
//     统一用「哨兵值 + SQL 内 IF/CASE」转成 NULL；
//   - upsert 用「先 SELECT 判断 → UPDATE / INSERT」，避开 ON DUPLICATE KEY UPDATE 的语法差异。

if (!defined('DOMAIN_BOTTOM_NULL')) define('DOMAIN_BOTTOM_NULL', -999999); // player_bottom 的「未单独设置」哨兵值

$GLOBALS['__domains_last_error'] = '';

function domains_last_error() {
    return (string)($GLOBALS['__domains_last_error'] ?? '');
}

function domains_set_error($msg) {
    $GLOBALS['__domains_last_error'] = (string)$msg;
}

function domains_is_sqlite($db) {
    return stripos(get_class($db), 'sqlite') !== false;
}

// 当前连接的数据库类型（页面据此提示该执行哪份 SQL）
function domains_db_type($db) {
    return domains_is_sqlite($db) ? 'sqlite' : 'mysql';
}

// 域名归一化：小写、去端口、去首尾点。www 不做合并——每个主机名各自独立授权，
// 填主域名不会顺带授权它的子域名。非法（空 / 超长 / 不符合标签规则）返回空串
// 注意：全部用字符串函数处理，不用正则，避免定界符/转义踩坑
function domain_normalize($domain) {
    $d = strtolower(trim((string)$domain));
    if ($d === '') return '';

    // 允许直接粘贴 https://a.b/c 这种，先把主机名抠出来
    $pos = strpos($d, '//');
    if ($pos !== false) $d = substr($d, $pos + 2);
    // 去掉 user:pass@ 前缀
    $at = strrpos($d, '@');
    if ($at !== false) $d = substr($d, $at + 1);
    // 去掉路径 / 查询串 / 锚点
    foreach (['/', '?', '#'] as $sep) {
        $i = strpos($d, $sep);
        if ($i !== false) $d = substr($d, 0, $i);
    }
    // 去掉端口（IPv6 的 [..]:port 会一并被去掉，域名场景用不到）
    $colon = strpos($d, ':');
    if ($colon !== false) $d = substr($d, 0, $colon);

    $d = trim($d, ". \t\n\r\0\x0B");
    if ($d === '' || strlen($d) > 190 || strpos($d, '..') !== false) return '';

    // 逐标签校验：字母数字开头结尾，中间允许连字符
    foreach (explode('.', $d) as $label) {
        if ($label === '' || strlen($label) > 63) return '';
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label)) return '';
    }
    return $d;
}
// mapi_domains 是否存在（单请求内缓存；表不存在不报致命错误）
function domains_table_exists($db) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = false;
    if (!$db) return $cache;
    try {
        $r = $db->query("SELECT 1 FROM mapi_domains LIMIT 1");
        $cache = ($r !== false);
    } catch (Throwable $e) {
        $cache = false;
    }
    if (!$cache) domains_set_error('表 mapi_domains 不存在');
    return $cache;
}

// 域名列表；$userId = 0 表示不限用户（超级管理员视角）
// 排序：自动登记（auto_added=1）的行优先置顶，其余按域名升序
// 返回 ['rows' => [...], 'ok' => bool, 'error' => string]
function domains_list($db, $userId) {
    $out = ['rows' => [], 'ok' => false, 'error' => ''];
    if (!$db) { $out['error'] = '数据库不可用'; return $out; }
    if (!domains_table_exists($db)) { $out['error'] = '表 mapi_domains 不存在'; return $out; }
    try {
        if ((int)$userId > 0) {
            $st = $db->prepare("SELECT * FROM mapi_domains WHERE user_id=? ORDER BY auto_added DESC, domain ASC");
            if (!$st) { $out['error'] = '查询失败：' . (string)$db->error; return $out; }
            $uid = (int)$userId;
            $st->bind_param('i', $uid);
        } else {
            $st = $db->prepare("SELECT * FROM mapi_domains ORDER BY user_id ASC, auto_added DESC, domain ASC");
            if (!$st) { $out['error'] = '查询失败：' . (string)$db->error; return $out; }
        }
        $st->execute();
        $res = $st->get_result();
        if ($res) { while ($row = $res->fetch_assoc()) $out['rows'][] = $row; }
        $out['ok'] = true;
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
        domains_set_error($out['error']);
    }
    return $out;
}

// 取单行；$userId = 0 表示不限用户
function domains_get($db, $userId, $id) {
    if (!$db || !domains_table_exists($db)) return null;
    $id = (int)$id;
    if ($id <= 0) return null;
    try {
        if ((int)$userId > 0) {
            $st = $db->prepare("SELECT * FROM mapi_domains WHERE id=? AND user_id=? LIMIT 1");
            if (!$st) return null;
            $uid = (int)$userId;
            $st->bind_param('ii', $id, $uid);
        } else {
            $st = $db->prepare("SELECT * FROM mapi_domains WHERE id=? LIMIT 1");
            if (!$st) return null;
            $st->bind_param('i', $id);
        }
        $st->execute();
        $res = $st->get_result();
        return $res ? $res->fetch_assoc() : null;
    } catch (Throwable $e) {
        domains_set_error($e->getMessage());
        return null;
    }
}

// 新增 / 更新一行（按 (user_id, domain) upsert）
// $fields: id, authorized, auto, lyrics_bottom, player_bottom(null = 跟随歌词), note
// 返回 ['ok' => bool, 'error' => string, 'id' => int]
function domains_save($db, $userId, $domain, array $fields) {
    $ret = ['ok' => false, 'error' => '', 'id' => 0];
    if (!$db) { $ret['error'] = '数据库不可用'; return $ret; }
    if (!domains_table_exists($db)) { $ret['error'] = '表 mapi_domains 不存在，请先访问 /install/upgrade.php'; return $ret; }

    $domain = domain_normalize($domain);
    if ($domain === '') { $ret['error'] = '域名格式不正确'; return $ret; }

    $userId = (int)$userId;
    $id     = (int)($fields['id'] ?? 0);
    $auth   = !empty($fields['authorized']) ? 1 : 0;
    $auto   = !empty($fields['auto']) ? 1 : 0;
    $lyrics = (int)($fields['lyrics_bottom'] ?? 0);
    $hasPb  = array_key_exists('player_bottom', $fields) && $fields['player_bottom'] !== null && $fields['player_bottom'] !== '';
    $player = $hasPb ? (int)$fields['player_bottom'] : DOMAIN_BOTTOM_NULL;
    $note   = mb_substr(trim((string)($fields['note'] ?? '')), 0, 255);

    // player_bottom：哨兵值在 SQL 里转成 NULL。用 CASE 而不是 MySQL 的 IF()，
    // 这样 MySQL / SQLite 共用同一段 SQL。注意 CASE 里的第一个占位符也要绑一个值，
    // 所以下面两条语句都把 $player 传了两次（第一次只用于和哨兵值比较）。
    $pbExpr = "CASE WHEN ?=" . DOMAIN_BOTTOM_NULL . " THEN NULL ELSE ? END";

    try {
        // 目标行：给了 id 就按 id（限定同一用户），否则按 (user_id, domain) 找
        $existId = 0;
        if ($id > 0) {
            $st = $db->prepare("SELECT id FROM mapi_domains WHERE id=? AND user_id=? LIMIT 1");
            if ($st) {
                $st->bind_param('ii', $id, $userId);
                $st->execute();
                $res = $st->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                if ($row) $existId = (int)$row['id'];
            }
        }
        if ($existId <= 0) {
            $st = $db->prepare("SELECT id FROM mapi_domains WHERE user_id=? AND domain=? LIMIT 1");
            if ($st) {
                $st->bind_param('is', $userId, $domain);
                $st->execute();
                $res = $st->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                if ($row) $existId = (int)$row['id'];
            }
        }

        if ($existId > 0) {
            $sql = "UPDATE mapi_domains SET domain=?, authorized=?, auto=?, lyrics_bottom=?, player_bottom={$pbExpr}, note=? WHERE id=? AND user_id=?";
            $st = $db->prepare($sql);
            if (!$st) { $ret['error'] = '更新失败：' . (string)$db->error; return $ret; }
            // 参数顺序必须与 SQL 占位符一致：
            // domain(s) authorized(i) auto(i) lyrics(i) player(i)=ELSE 值 player(i)=CASE 比较值 note(s) id(i) user_id(i)
            $st->bind_param('siiiisiii', $domain, $auth, $auto, $lyrics, $player, $player, $note, $existId, $userId);
        } else {
            $sql = "INSERT INTO mapi_domains (user_id, domain, authorized, auto, lyrics_bottom, player_bottom, note) VALUES (?, ?, ?, ?, ?, {$pbExpr}, ?)";
            $st = $db->prepare($sql);
            if (!$st) { $ret['error'] = '新增失败：' . (string)$db->error; return $ret; }
            // 参数顺序必须与 SQL 占位符一致：
            // user_id(i) domain(s) authorized(i) auto(i) lyrics(i) player(i)=ELSE 值 player(i)=CASE 比较值 note(s)
            $st->bind_param('isiiiiis', $userId, $domain, $auth, $auto, $lyrics, $player, $player, $note);
        }
        if (!$st->execute()) {
            $ret['error'] = '保存失败：' . (string)$db->error;
            return $ret;
        }
        $ret['id'] = $existId > 0 ? $existId : (int)$db->insert_id;
        $ret['ok'] = true;
    } catch (Throwable $e) {
        $ret['error'] = $e->getMessage();
        domains_set_error($ret['error']);
    }
    return $ret;
}

// 删除一行；$userId = 0 表示不限用户（超级管理员）
function domains_delete($db, $userId, $id) {
    if (!$db || !domains_table_exists($db)) return false;
    $id = (int)$id;
    if ($id <= 0) return false;
    try {
        if ((int)$userId > 0) {
            $st = $db->prepare("DELETE FROM mapi_domains WHERE id=? AND user_id=?");
            if (!$st) return false;
            $uid = (int)$userId;
            $st->bind_param('ii', $id, $uid);
        } else {
            $st = $db->prepare("DELETE FROM mapi_domains WHERE id=?");
            if (!$st) return false;
            $st->bind_param('i', $id);
        }
        $st->execute();
        return ((int)$st->affected_rows) > 0;
    } catch (Throwable $e) {
        domains_set_error($e->getMessage());
        return false;
    }
}

// 全局开关：mapi_config.domain_authorize，值为字符串 '1' 时启用；缺失 / '0' 一律放行
function domain_authorize_on($db) {
    if (!$db) return false;
    try {
        $r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='domain_authorize'");
        if ($r && is_object($r)) {
            $row = $r->fetch_assoc();
            if ($row && (string)($row['config_value'] ?? '0') === '1') return true;
        }
    } catch (Throwable $e) {
        return false;
    }
    return false;
}

// 写全局开关（0 / 1）
function domain_authorize_set($db, $on) {
    if (!$db) return false;
    $v = $on ? '1' : '0';
    try {
        $st = $db->prepare("REPLACE INTO mapi_config (config_key, config_value) VALUES ('domain_authorize', ?)");
        if (!$st) return false;
        $st->bind_param('s', $v);
        return (bool)$st->execute();
    } catch (Throwable $e) {
        domains_set_error($e->getMessage());
        return false;
    }
}

// 单个密钥最多允许自动登记多少行（防止被脚本刷库）
defined('DOMAINS_AUTO_MAX') or define('DOMAINS_AUTO_MAX', 100);

// 自动登记开关：mapi_config.domain_auto_add
// 与授权开关相反 —— 缺失 / 非 '0' 都算「开」（新装出来就能用），只有显式写入 '0' 才关闭。
function domain_auto_add_on($db) {
    if (!$db) return false;
    try {
        $r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='domain_auto_add'");
        if ($r && is_object($r)) {
            $row = $r->fetch_assoc();
            if ($row && (string)($row['config_value'] ?? '') === '0') return false;
        }
    } catch (Throwable $e) {
        return false;   // 读不到配置（表缺失等）时不自动登记：宁可挡住，也不乱写库
    }
    return true;
}

// 写自动登记开关（0 / 1）
function domain_auto_add_set($db, $on) {
    if (!$db) return false;
    $v = $on ? '1' : '0';
    try {
        $st = $db->prepare("REPLACE INTO mapi_config (config_key, config_value) VALUES ('domain_auto_add', ?)");
        if (!$st) return false;
        $st->bind_param('s', $v);
        return (bool)$st->execute();
    } catch (Throwable $e) {
        domains_set_error($e->getMessage());
        return false;
    }
}

// 自动登记一个主机名（调用方需先确认本表里没有这一行）。
// 语义：只补新行 —— 管理员手动停用（authorized=0）的行不会被复活。
// 成功返回登记后的行（字段名与 domains_list 一致），失败 / 超上限返回 null。
function domain_auto_register($db, $userId, $host) {
    if (!$db) return null;
    $host = domain_normalize($host);
    if ($host === '') return null;
    $userId = (int)$userId;
    if ($userId <= 0) return null;
    if (!domains_table_exists($db)) return null;
    try {
        $cnt = 0;
        $cst = $db->prepare("SELECT COUNT(*) AS c FROM mapi_domains WHERE user_id=? AND auto_added=1");
        if ($cst) {
            $cst->bind_param('i', $userId);
            $cst->execute();
            $cres = $cst->get_result();
            $crow = $cres ? $cres->fetch_assoc() : null;
            $cnt  = $crow ? (int)$crow['c'] : 0;
        }
        if ($cnt >= DOMAINS_AUTO_MAX) {
            domains_set_error('自动登记已达上限 ' . DOMAINS_AUTO_MAX . ' 个域名');
            return null;
        }
        $ist = $db->prepare("INSERT INTO mapi_domains (user_id, domain, authorized, auto, lyrics_bottom, player_bottom, note, auto_added) VALUES (?, ?, 1, 1, 0, NULL, '', 1)");
        if (!$ist) return null;
        $ist->bind_param('is', $userId, $host);
        if (!$ist->execute()) return null;
        return [
            'id'            => (int)$db->insert_id,
            'user_id'       => $userId,
            'domain'        => $host,
            'authorized'    => 1,
            'auto'          => 1,
            'lyrics_bottom' => 0,
            'player_bottom' => null,
            'note'          => '',
            'auto_added'    => 1,
        ];
    } catch (Throwable $e) {
        domains_set_error($e->getMessage());
        return null;
    }
}