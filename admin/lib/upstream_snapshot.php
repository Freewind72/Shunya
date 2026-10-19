<?php defined('MAPI_ADMIN') or defined('MAPI_PLAYER_API') or die('禁止直接访问');
// MAPI_ADMIN：后台页面 / handler 入口；MAPI_PLAYER_API：播放器网关 api.php
// （嵌入播放器加载时也要触发同步，所以这个库必须能被网关 include —— 只放 MAPI_ADMIN 会让网关直接 die）

/**
 * 远程歌单「从上游同步」的冷却秒数（每个歌单各自计时）。
 * 为什么要它：后台每次登录、以及播放器每次被嵌入加载都会触发同步，不设闸就是打上游。
 */
const PLAYLIST_SYNC_COOLDOWN = 600;   // 10 分钟

/**
 * 同步**失败**后的静默期（秒）。
 *
 * 为什么需要它：同步失败时我们**故意不记 synced_at**（"同步失败不该消耗 10 分钟冷却额度"）。
 * 但上游真挂掉时这条设计会反噬 —— 冷却永远没被消耗，于是**每次开页都重试全部歌单**，
 * 而每个失败请求都要等满超时（拉清单 12s / 封面 8s）。7 个歌单就是每次开页白等几十秒。
 *
 * 这道短静默把"上游挂了"退化成**每 90 秒最多试一次**，而不是每次开页都试。
 * 记在 mapi_config 的 `_pl_sync_fail_<歌单id>`（与自建歌单的断点游标同一处，都是一次性内部键）。
 */
const PLAYLIST_SYNC_FAIL_BACKOFF = 90;

/** 读某歌单上次同步失败的时刻（unix ts；0 = 没有失败记录）。只读，不写库。 */
function playlist_sync_fail_get($db, int $plId): int
{
    if ($plId <= 0) return 0;
    $r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='_pl_sync_fail_" . $plId . "' LIMIT 1");
    if (!$r) return 0;
    $row = $r->fetch_row();
    return $row ? max(0, (int)$row[0]) : 0;
}

/** 记下这次同步失败（用于上面的静默期） */
function playlist_sync_fail_mark($db, int $plId): void
{
    if ($plId <= 0) return;
    $key = '_pl_sync_fail_' . $plId;
    $val = (string)time();
    $st = $db->prepare("REPLACE INTO mapi_config (config_key, config_value) VALUES (?, ?)");
    if ($st) { $st->bind_param('ss', $key, $val); $st->execute(); }
}

/** 同步成功了就把失败记录清掉，恢复正常节奏 */
function playlist_sync_fail_clear($db, int $plId): void
{
    if ($plId <= 0) return;
    $db->query("DELETE FROM mapi_config WHERE config_key='_pl_sync_fail_" . $plId . "'");
}

/**
 * 这个歌单现在是否可以同步。条件（全部满足才行）：
 *   ① 不在上次**失败**的静默期内（PLAYLIST_SYNC_FAIL_BACKOFF）
 *   ② 离上次成功同步满 $cooldown（10 分钟），或从未同步过
 * 只读，不写库。
 */
function playlist_sync_allowed($db, array $pl, int $cooldown = PLAYLIST_SYNC_COOLDOWN): bool
{
    $plId = (int)($pl['id'] ?? 0);
    if (!$plId) return false;

    // ① 失败静默期：挡住"上游挂了却每次开页都重试"
    $failAt = playlist_sync_fail_get($db, $plId);
    if ($failAt > 0 && (time() - $failAt) < PLAYLIST_SYNC_FAIL_BACKOFF) return false;

    // ② 正常冷却
    $last = (string)($pl['synced_at'] ?? '');
    // 老库/未同步过：synced_at 为 NULL → 直接放行
    if ($last === '' || $last === '0000-00-00 00:00:00') return true;
    $ts = strtotime($last . ' UTC');   // 列里一律存 UTC（与 cover_store 的 orphaned_at 同规矩）
    if ($ts === false) return true;
    return (time() - $ts) >= max(0, $cooldown);
}

/** 记下本次同步时间（UTC）。同步真正跑过之后调用 */
function playlist_sync_mark($db, int $plId): void
{
    if ($plId <= 0) return;
    $now = gmdate('Y-m-d H:i:s');
    $st = $db->prepare("UPDATE mapi_playlists SET synced_at=? WHERE id=?");
    if ($st) { $st->bind_param('si', $now, $plId); $st->execute(); }
}

// 把远程歌单歌曲写入快照
// $force=true 绕过 10 分钟冷却（用户手动点「从上游同步」时用）。
// 冷却判定收口在这里，是为了让**所有**调用方（后台 handler、播放器网关）共用同一道闸，
// 不会因为新增调用点就漏掉防滥用。
function playlist_snapshot_songs($db, array $cfg, array $pl, bool $force = false): array {
    $ret = ['added' => 0, 'updated' => 0, 'missing' => 0, 'total' => 0, 'skipped' => false];

    $plId     = (int)($pl['id'] ?? 0);
    $kid      = (int)($pl['key_id'] ?? 0);
    $server   = ($pl['server'] ?? '') ?: 'netease';
    $remoteId = trim((string)($pl['remote_id'] ?? ''));
    if (!$plId || $remoteId === '') return $ret;

    // 冷却闸：10 分钟内已经同步过就直接返回，不打上游
    if (!$force && !playlist_sync_allowed($db, $pl)) { $ret['skipped'] = true; return $ret; }

    $songs = upstream_fetch_playlist_songs($cfg, $server, $remoteId);
    $ret['total'] = count($songs);
    // 上游没取到（网络抖动/接口挂）→ **不记成功时间**（免得白等 10 分钟），
    // 但要记下**失败时刻**：否则上游一挂就退化成"每次开页都重试全部歌单"，
    // 而每个失败请求都要等满超时。静默期见 PLAYLIST_SYNC_FAIL_BACKOFF。
    if (!$songs) { playlist_sync_fail_mark($db, $plId); return $ret; }
    // 真的拿到清单了才记时间：同步失败不该消耗冷却额度；同时清掉失败记录，恢复正常节奏
    playlist_sync_mark($db, $plId);
    playlist_sync_fail_clear($db, $plId);

    $exist = [];
    $r = $db->query("SELECT id, song_id, name, artist, server, missing FROM mapi_songs WHERE playlist_id=$plId");
    if ($r) while ($row = $r->fetch_assoc()) $exist[(string)$row['song_id']] = $row;

    /* 两轮删除机制（见下方删除段的说明）：只有「**进这轮同步之前就已经 missing=1**」的歌
       才有资格被真删 —— 即"上一轮同步已确认缺失、这一轮上游仍没有"。刚在本轮被标 missing 的
       要留到下一轮，避免上游一次抽风就误删真歌。这里在标 missing **之前**先快照。 */
    $staleBefore = [];
    foreach ($exist as $sid => $row) {
        if ((int)$row['missing'] === 1) $staleBefore[$sid] = $row;
    }

    $maxOrder = 0;
    $r = $db->query("SELECT IFNULL(MAX(sort_order),0) FROM mapi_songs WHERE playlist_id=$plId");
    if ($r) $maxOrder = (int)$r->fetch_row()[0];

    $seen = [];
    foreach ($songs as $s) {
        $sid = (string)$s['song_id'];
        $seen[$sid] = true;

        if (isset($exist[$sid])) {
            $cur = $exist[$sid];
            if ((string)$cur['name'] !== $s['name'] || (string)$cur['artist'] !== $s['artist'] || (int)$cur['missing'] === 1) {
                $up = $db->prepare("UPDATE mapi_songs SET name=?, artist=?, missing=0 WHERE id=?");
                $up->bind_param('ssi', $s['name'], $s['artist'], $cur['id']);
                $up->execute();
                $ret['updated']++;
            }
            continue;
        }

        $maxOrder += 100;
        $ins = $db->prepare("INSERT INTO mapi_songs (key_id, playlist_id, song_id, name, artist, server, sort_order, missing) VALUES (?,?,?,?,?,?,?,0)");
        $ins->bind_param('iissssi', $kid, $plId, $sid, $s['name'], $s['artist'], $server, $maxOrder);
        $ins->execute();
        $ret['added']++;
    }

    foreach ($exist as $sid => $cur) {
        if (isset($seen[$sid]) || (int)$cur['missing'] === 1) continue;
        $up = $db->prepare("UPDATE mapi_songs SET missing=1 WHERE id=?");
        $up->bind_param('i', $cur['id']);
        $up->execute();
        $ret['missing']++;
    }

    /* ── 上游已不存在的歌：**真删除**（2026-10-20，产品要求）─────────────
       原行为：只标 missing=1（"宁可标记也不删"）。问题：用户在 QQ/网易平台侧删歌后，
       本地永远留着尸体 —— 播放器不显示（missing 过滤），但**导出 JSON 全都能看见**，
       用户据此发现"同步没有正确删除"。

       新行为：连续两个同步周期都确认缺失才删。为什么要等一轮：
         · 上游偶发抽风（部分分页/临时 502）可能短暂少给几首 ⇒ 直接删会误删真歌；
         · 第一轮缺失 = 标 missing（播放器立即不显示，效果与删除对用户一致）；
         · 第二轮同步时上游清单里仍没有 ⇒ 确认真删，并按 playlist-delete 的同一套
           惯例清理歌曲封面（无其它歌单引用时 cover_song_unset）。
       这样误删概率 ≈ 0（需要上游连续 10 分钟+ 都少给同一批歌），而尸体最多滞留一个冷却周期。
       ⚠ 只对**远程**歌单做；自建歌单（playlist_refresh_custom_songs）是用户手工挑的，
         那边的 missing 永远只是"平台下架"，不该删。 */
    $delRows = [];
    foreach ($staleBefore as $sid => $d) {
        // 只删"进本轮前就已 missing"且这一轮上游仍然没有的 —— 两轮都缺失才删
        if (isset($seen[$sid])) continue;
        $delRows[] = $d;
    }
    if ($delRows) {
        $delSt = $db->prepare("DELETE FROM mapi_songs WHERE id=? AND playlist_id=$plId AND missing=1");
        foreach ($delRows as $d) {
            $did = (int)$d['id'];
            $delSt->bind_param('i', $did);
            if (!$delSt->execute()) continue;
            if ($delSt->affected_rows <= 0) continue;
            $ret['removed'] = ($ret['removed'] ?? 0) + 1;
            // 歌曲封面若无其它歌单引用则一并清理（与 playlist-delete 的惯例一致）
            $refServer = (string)($d['server'] ?? '') ?: $server;
            $songEsc = $db->real_escape_string((string)$d['song_id']);
            $srvEsc  = $db->real_escape_string($refServer);
            $still = $db->query("SELECT COUNT(*) n FROM mapi_songs WHERE song_id='$songEsc' AND server='$srvEsc'");
            if ($still && $n = $still->fetch_assoc()) {
                if ((int)$n['n'] === 0 && function_exists('cover_song_unset')) {
                    cover_song_unset($db, $refServer, (string)$d['song_id']);
                }
            }
        }
    }

    /* ── 按上游顺序重排：远程歌单是上游的**镜像**，顺序也该镜像 ──
       为什么必须做：原来新歌走 `$maxOrder += 100` **一律追加到末尾**。而上游（QQ / 网易）
       通常是"最新在前"，于是上游排第一的新歌，在我们库里排最后 —— 播放器里看到的就是
       "**新加的歌在最下面**"（用户实际报过）。而且这个错位会**永久留在库里**：
       实测用户的两个歌单已经积下来了（上游 2 首/库里 4 首、上游 5 首/库里 7 首，
       上游的第一首在库里排到了最后）。

       规则：
         · 上游这次返回的歌，**严格按上游顺序**排（100,200,300…）；
         · 上游已经没有的（missing / 历史遗留）**排在后面**，并保持它们原有的相对顺序
           —— 播放器本来就按 missing=0 过滤，排哪里都不显示。
       只在顺序真的变了时才 UPDATE。
       ⚠ 只对**远程**歌单做：自建歌单走 playlist_refresh_custom_songs()，
         那边的顺序是用户手工排的，绝不能动。 */
    $upIds = [];
    foreach ($songs as $s) $upIds[] = (string)$s['song_id'];
    $upSet = array_flip($upIds);

    $rowById = [];
    $seq     = [];
    $r = $db->query("SELECT id, song_id, sort_order FROM mapi_songs WHERE playlist_id=$plId ORDER BY sort_order ASC, id ASC");
    if ($r) while ($x = $r->fetch_assoc()) {
        $sid = (string)$x['song_id'];
        $rowById[$sid] = $x;
        $seq[] = $sid;                                   // 先按当前顺序收集
    }

    if ($rowById) {
        $ordered = [];
        foreach ($upIds as $sid) {                        // ① 上游有的，按上游顺序
            if (isset($rowById[$sid])) $ordered[] = $sid;
        }
        foreach ($seq as $sid) {                          // ② 上游没有的，保持原相对顺序，排后面
            if (!isset($upSet[$sid])) $ordered[] = $sid;
        }
        $o = 100;
        foreach ($ordered as $sid) {
            $want = $o; $o += 100;
            if ((int)$rowById[$sid]['sort_order'] !== $want) {
                $upOrd = $db->prepare("UPDATE mapi_songs SET sort_order=? WHERE id=?");
                if ($upOrd) { $upOrd->bind_param('ii', $want, $rowById[$sid]['id']); $upOrd->execute(); }
            }
        }
    }
    return $ret;
}

/* ═══════════════════════════════════════════════════════════════════════════
   自建歌单的「同步」

   自建歌单**没有上游歌单可拉**（remote_id 为空），所以它的"同步"是**逐首回源**：
     · 歌名/歌手：以 `?type=song&id=<song_id>` 的上游返回为准。实测返回**单元素数组**
       `[{title,author,pic,url,lrc}]`，与网关 admin/api/api.php:371 的 `$song[0] ?? $song` 同款形状；
       且**不带 UA/Referer 也能取到**，所以直接复用 upstream_get_json 即可。
     · 封面：交给 cover_song_refresh()，它**命中缓存就早退** ⇒ 只补缺的，不会重复下载
     · 下架：只有"上游确实应答了、但这一首查不到"才标记 missing=1；
             整趟请求失败（upstream_get_json 返回 null）时**不动任何数据** ——
             否则一次网络抖动就会把整张歌单误标成"歌没了"。

   ⚠ 为什么不能一次刷完：逐首回源是 N 次串行 HTTP，而 admin/includes/bootstrap.php:3 是
     `set_time_limit(30)` —— 几百首的歌单必然被砍。所以这里按**墙钟预算**处理一批，
     并把**断点存进 mapi_config**（键 `_pl_sync_pos_<歌单id>`），下次点击从断点接着刷；
     这与项目既有的"处理一批 → 回报进度"（admin/handlers/covers.php:12）是同一套路。
   ═══════════════════════════════════════════════════════════════════════════ */

/** 单次请求用于逐首回源的墙钟预算（秒）。
 *  为什么是 18 而不是更大：循环是"进入本次迭代前先看预算"，
 *  所以最坏情况 = 预算 + **单首超时**（见下面的 upstream_get_json 第 5 参）。
 *  18 + 6 = 24s < bootstrap.php:3 的 set_time_limit(30) —— 留 6 秒给收尾与响应。 */
const CUSTOM_SYNC_BUDGET_SEC = 18.0;

/** 逐首回源的**单首** HTTP 超时（秒）。实测上游单首约 0.23s，6s 已是 25 倍余量 */
const CUSTOM_SYNC_SONG_TIMEOUT = 6;

/** 读自建歌单的同步断点（下一个待刷的下标） */
function playlist_sync_cursor_get($db, int $plId): int {
    if ($plId <= 0) return 0;
    $r = $db->query("SELECT config_value FROM mapi_config WHERE config_key='_pl_sync_pos_" . $plId . "' LIMIT 1");
    if (!$r) return 0;
    $row = $r->fetch_row();
    return $row ? max(0, (int)$row[0]) : 0;
}

/** 写自建歌单的同步断点（REPLACE INTO 在 SQLite 侧由 translate_sql 翻成 INSERT OR REPLACE） */
function playlist_sync_cursor_set($db, int $plId, int $pos): void {
    if ($plId <= 0) return;
    $key = '_pl_sync_pos_' . $plId;
    $val = (string)max(0, $pos);
    $st = $db->prepare("REPLACE INTO mapi_config (config_key, config_value) VALUES (?, ?)");
    if ($st) { $st->bind_param('ss', $key, $val); $st->execute(); }
}

/**
 * 自建歌单：逐首回源刷新歌名/歌手/下架状态并补封面。
 * 返回形状与 playlist_snapshot_songs() 对齐，另加 done(本次刷了几首)/remaining/cursor。
 */
function playlist_refresh_custom_songs($db, array $cfg, array $pl, bool $force = false, float $budgetSec = CUSTOM_SYNC_BUDGET_SEC): array {
    $ret = ['added' => 0, 'updated' => 0, 'missing' => 0, 'total' => 0, 'skipped' => false,
            'covers' => 0, 'done' => 0, 'remaining' => 0, 'cursor' => 0];

    $plId     = (int)($pl['id'] ?? 0);
    $plServer = ($pl['server'] ?? '') ?: 'netease';
    if (!$plId) return $ret;

    // 与远程同步共用同一道冷却闸
    if (!$force && !playlist_sync_allowed($db, $pl)) { $ret['skipped'] = true; return $ret; }

    $rows = [];
    $r = $db->query("SELECT id, song_id, name, artist, server, missing FROM mapi_songs
                     WHERE playlist_id=$plId ORDER BY sort_order ASC, id ASC");
    if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
    $total = count($rows);
    $ret['total'] = $total;
    if ($total === 0) return $ret;                       // 空歌单：没东西可刷，也不消耗冷却

    $start = playlist_sync_cursor_get($db, $plId);
    if ($start >= $total) $start = 0;                    // 上一轮已刷完一整遍 → 从头再来

    $deadline = microtime(true) + max(1.0, $budgetSec);
    $answered = 0;
    $i = $start;
    while ($i < $total) {
        if (microtime(true) >= $deadline) break;         // 预算用完：本轮到此为止，剩下的下次接着刷
        $row = $rows[$i];
        $i++;
        $ret['done']++;
        $sid = trim((string)$row['song_id']);
        if ($sid === '') continue;
        // 自建歌单允许混源，优先用歌曲自己的 server
        $sv = (string)($row['server'] ?? '') ?: $plServer;

        // 单首超时收紧到 CUSTOM_SYNC_SONG_TIMEOUT，配合上面的预算，
        // 保证最坏总耗时 18+6=24s 不会撞上 set_time_limit(30)
        $data = upstream_get_json($cfg, 'song', $sid, $sv, CUSTOM_SYNC_SONG_TIMEOUT);
        if ($data === null) continue;                    // 请求/解析失败：不动数据，也不算"应答"
        $answered++;
        $item = (isset($data[0]) && is_array($data[0])) ? $data[0] : (is_array($data) ? $data : []);

        if (!$item) {                                    // 应答了但查不到这一首 ⇒ 真的下架了
            if ((int)$row['missing'] !== 1) {
                $up = $db->prepare("UPDATE mapi_songs SET missing=1 WHERE id=?");
                if ($up) { $up->bind_param('i', $row['id']); $up->execute(); $ret['missing']++; }
            }
            continue;
        }

        $norm = upstream_song_item_normalize($cfg, $item);
        $name = $norm['name'];
        if ($name === '' || $name === '未知') $name = (string)$row['name'];   // 上游没给名字就别覆盖已有的
        $artist = $norm['artist'];
        if ($name !== (string)$row['name'] || $artist !== (string)$row['artist'] || (int)$row['missing'] === 1) {
            $up = $db->prepare("UPDATE mapi_songs SET name=?, artist=?, missing=0 WHERE id=?");
            if ($up) { $up->bind_param('ssi', $name, $artist, $row['id']); $up->execute(); $ret['updated']++; }
        }

        // 封面：只统计**这一趟真正补上**的。
        // ⚠ 不能拿 cover_song_refresh() 的返回值判断 —— 它**命中缓存时也返回缓存值**（非空），
        //   那样每次同步都会把"已有封面"重复计进 stats['covers']，前端就会一直谎报"补封面 N"。
        //   先查一次缓存：本来就有 → 不算；本来没有并且真的取到了 → 才算。
        if (function_exists('cover_song_get') && function_exists('cover_song_refresh')) {
            $hadCover = cover_song_get($db, $sv, $sid);
            if ($hadCover === '' && cover_song_refresh($db, $cfg, $sv, $sid, (int)($pl['key_id'] ?? 0)) !== '') {
                $ret['covers']++;
            }
        }
    }

    $ret['cursor']    = ($i >= $total) ? 0 : $i;          // 刷完整遍归零，否则记断点
    $ret['remaining'] = $total - $ret['done'];
    playlist_sync_cursor_set($db, $plId, $ret['cursor']);
    // 有应答过才记同步时间：整趟都失败不该消耗 10 分钟冷却额度。
    // 但"整趟一个都没应答"= 上游挂了，这时要记**失败时刻**走 90 秒短静默，
    // 否则每次开页都会把整张歌单重新刷一遍（每首歌还要等满超时）。
    if ($answered > 0) {
        playlist_sync_mark($db, $plId);
        playlist_sync_fail_clear($db, $plId);
    } else {
        playlist_sync_fail_mark($db, $plId);
    }
    return $ret;
}