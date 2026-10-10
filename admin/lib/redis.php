<?php
/**
 * Redis 缓存层（纯 PHP RESP 实现，不依赖 phpredis 扩展）
 *
 * 用途：缓存播放器数据 —— ① 上游解析结果（播放地址/歌词/封面）② 歌单配置 JSON ③ 播放器状态（按 IP+设备校验）
 *
 * 设计红线（改这里之前先读）：
 *   1. **绝不因为缓存挂掉而影响播放器**：连接失败/超时/密码错 → 一律当作"未命中"，调用方继续走原路径。
 *      所以所有函数都不抛异常，失败返回 null/false。
 *   2. **超时必须短**：连接 ≤0.3s、读写 ≤2s（配置里的 timeout 可调，会被夹到这两个上限）。
 *      实测过：连接超时若给到 2s，Redis 一挂每个 worker 首次请求就卡 2s —— 装了 phpredis 反而更慢。
 *      缓存是加速手段，不能把请求拖慢。
 *   3. 配置存在数据库 mapi_config 表（config_key = **redis**），不新增表 —— 走项目既有的
 *      "配置落库 + install/lib/config_seeder.php 播种"那套，无需改 install/sql/*.sql。
 *      字段沿用数据库里已有的那套命名：enabled / host / port / password / database / prefix /
 *      default_ttl / timeout，本模块再补几个"按缓存类型分 TTL"和"IP 校验策略"的键（缺省值在下面）。
 */
defined('MAPI_ADMIN') or defined('MAPI_PLAYER_API') or die('禁止直接访问');

/** 默认配置（数据库里没有 redis 行时用它：enabled=0，即默认关闭；字段名与数据库既有那行保持一致） */
function redis_default_config(): array
{
    return [
        'enabled'     => 0,
        'host'        => '127.0.0.1',
        'port'        => 6379,
        'password'    => '',
        'database'    => 0,
        'prefix'      => 'mapi:',
        'default_ttl' => 600,       // 通用兜底 TTL（数据库既有字段）
        'timeout'     => 2,         // 连接/读写超时上限（秒；实际用得更短，见 redis_conn）
        // 以下为本模块扩展：按缓存类型分 TTL（不填就用 default_ttl）
        'ttl_url'     => 300,       // 播放地址：上游给的是短时效签名链接，只能短缓存
        'ttl_lrc'     => 604800,    // 歌词：基本不变，缓存 7 天
        'ttl_pic'     => 604800,    // 封面 URL：内容寻址，稳定
        'ttl_cfg'     => 60,        // 歌单配置 JSON：短 TTL + 管理端写操作主动失效
        'ttl_state'   => 2592000,   // 播放器状态：30 天
        'strict_ip'   => 1,         // 严格校验：IP 网段变了要重新绑定（见 device_state_*）
        'ip_grace'    => 86400,     // 宽限：IP 段变了但设备没变、且最近活跃在此时间内 → 允许继续用
    ];
}

/**
 * 各缓存类型的 TTL：扩展键没配就回落 default_ttl。
 * **返回 0 表示这类缓存不启用**（调用方据此完全跳过 Redis 往返）——
 * 实测：配置缓存对本机 MySQL 几乎零收益（那部分查询只要 2ms），
 * 如果你的库和 PHP 同机、又不需要省这点，就把 ttl_cfg 设成 0。
 */
function redis_ttl_for(string $kind): int
{
    $c = redis_conf();
    $key = 'ttl_' . $kind;
    $v = isset($c[$key]) && $c[$key] !== '' ? (int)$c[$key] : (int)$c['default_ttl'];
    return max(0, $v);
}

/** 读配置（进程内缓存一次；数据库不可用时回落默认值 = 关闭） */
function redis_conf(bool $reload = false): array
{
    static $conf = null;

    // 管理端「测试连接」：用刚提交、还没保存的值探一次（见 handlers/settings.php 的 _redis_test）。
    // 这条分支**不写** static 缓存，避免把临时值当成真配置留在进程里。
    if (!empty($GLOBALS['__redis_probe_cfg']) && is_array($GLOBALS['__redis_probe_cfg'])) {
        $probe = redis_default_config();
        foreach ($GLOBALS['__redis_probe_cfg'] as $k => $v) if ($v !== null && $v !== '') $probe[$k] = $v;
        $probe['enabled'] = true;
        return $probe;
    }

    if ($conf !== null && !$reload) return $conf;
    // 重新读配置 ⇒ 之前按老配置建立的连接（含 phpredis 实例）一律作废，
    // 否则改了 host/密码/端口后还在用旧连接（管理端"测试连接"就会测出假结果）。
    if ($reload || $conf !== null) {
        $GLOBALS['__redis_gen'] = (int)($GLOBALS['__redis_gen'] ?? 1) + 1;
        if (function_exists('redis_conn')) redis_conn(true);
    }
    $conf = redis_default_config();
    global $db, $db_log;
    $conn = $db ?: $db_log;
    if (!$conn || !function_exists('read_mapi_redis_config')) return $conf;
    $saved = read_mapi_redis_config($conn);
    foreach ($saved as $k => $v) if ($v !== null && $v !== '') $conf[$k] = $v;
    $conf['enabled'] = !empty($conf['enabled']);
    return $conf;
}

function redis_enabled(): bool
{
    $c = redis_conf();
    return !empty($c['enabled']) && $c['host'] !== '';
}

/** 本进程内的调试统计（不落库、不影响业务） */
function redis_stat_inc(string $k): void { $GLOBALS['__redis_stat'][$k] = ($GLOBALS['__redis_stat'][$k] ?? 0) + 1; }
function redis_stat_all(): array { return $GLOBALS['__redis_stat'] ?? []; }

/** 最近一次失败的原因（给后台卡片显示，避免只说"PING 失败"让人猜） */
function redis_set_last_error(string $msg): void { $GLOBALS['__redis_last_error'] = $msg; }
function redis_get_last_error(): string { return (string)($GLOBALS['__redis_last_error'] ?? ''); }

/**
 * 运行时的"要不要用缓存"结论（**不写库、无副作用**）。
 *
 * 规则（用户定的，别改）：
 *   ① **手动开关优先级最高** —— `enabled=false` 表示用户手动关了，那即使服务端连得上也一律不用；
 *   ② 手动开着时**自动按连通性**：连得上就用、连不上就自动停用（fail-open），且每 5 秒会自动重试，
 *      服务端恢复后无需人工干预即继续生效。
 * 注意：本函数只**读取结论**，绝不修改配置 —— 自动状态一旦写库，就会覆盖用户的手动意图（"回弹"的根源）。
 */
function redis_state_report(): array
{
    $c = redis_conf();
    $manual = !empty($c['enabled']);
    $out = ['manual' => $manual, 'reachable' => false, 'effective' => false, 'note' => ''];
    if (!$manual) {
        $out['note'] = '手动关闭中（手动开关优先，即使服务端能连也不使用缓存）';
        return $out;
    }
    // 这里不调 redis_enabled()（避免自我递归）；直接用连接单例探一次，结论带冷却与 DNS 缓存
    $reachable = redis_native() ? (redis_native_conn() !== null) : (redis_conn() !== null);
    $out['reachable']  = $reachable;
    $out['effective']  = $reachable;
    if ($reachable) {
        $out['note'] = '缓存生效中（自动检测到服务端可连）';
    } else {
        $why = redis_get_last_error();
        $out['note'] = '已自动停用：连不上' . ($why !== '' ? '（' . $why . '）' : '')
                     . '。手动开关仍是开，服务端恢复后会自动继续（每 5 秒重试一次）';
    }
    return $out;
}

/**
 * 主机名能否解析 —— 结论**用文件缓存**（默认 5 分钟）。
 * 为什么必须缓存：解析不了的主机名（Docker 容器名 / 写错的域名）会让每次连接都白等 1 秒多；
 * 而且 php-cgi 有多个常驻 worker，进程内缓存只能救自己那一个 —— 文件缓存让 5 个 worker 只付一次代价。
 */
function redis_host_resolvable(string $host): bool
{
    if (filter_var($host, FILTER_VALIDATE_IP)) return true;         // 本来就是 IP，不用解析
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mapi-dns-' . substr(sha1($host), 0, 12);
    $st = @file_get_contents($file);
    if ($st !== false && strpos($st, '|') !== false) {
        [$verdict, $ts] = explode('|', $st, 2);
        if (time() - (int)$ts < 300) return $verdict === 'ok';
    }
    $ip = @gethostbyname($host);
    $ok = ($ip !== $host);                                          // 解析失败时 gethostbyname 原样返回
    @file_put_contents($file, ($ok ? 'ok' : 'bad') . '|' . time(), LOCK_EX);
    return $ok;
}

/* ------------------------------ 连接与协议 ------------------------------ */

/** 单例连接；连不上返回 null（调用方一律按"无缓存"处理） */
function redis_conn(bool $fresh = false)
{
    static $sock = null;
    static $tried = false;
    static $failAt = 0;
    // $fresh：**只丢弃当前连接，不立刻重连**。这点很关键 —— 配置重载过程中调用它时，
    // 进程内的配置还是旧值；若在这里立刻建连，就会用旧密码建成一条"新"连接并被缓存下来，
    // 之后即使配置已经改了，也一直在复用那条旧连接（曾真踩过：把密码改错后 PING 依然通）。
    // 真正的重连留给下一条命令惰性完成（那时配置已经是新的）。
    if ($fresh) { if (is_resource($sock)) @fclose($sock); $sock = null; $tried = false; $failAt = 0; return null; }
    if ($tried) {
        // 连上了就一直用；失败过则等 5 秒再试（Redis 重启后能自愈，又不至于每个命令都去连一次）
        if ($sock !== null || time() - $failAt < 5) return $sock;
        $tried = false;
    }
    $tried = true;

    if (!redis_enabled()) return $sock = null;
    $c = redis_conf();
    // 超时：连接 ≤0.3s、读写 ≤2s（配置里的 timeout 可调，夹在这两个上限内）。
    // 缓存只是加速，绝不能把请求拖住 —— Redis 挂掉时必须**立刻**当未命中继续走。
    // 注意：这里曾是 min($tmo,1) 而原生分支是 min($tmo,2)，导致"装了 phpredis 反而慢一倍"。
    $tmo     = max(0.1, (float)($c['timeout'] ?? 2));
    $connect = min($tmo, 0.3);
    $readUs  = (int)(min($tmo, 2) * 1000000);
    // 主机名解析不了就别白等：fsockopen 同样会卡在 DNS 上（与原生分支同一套判断，结论文件缓存 5 分钟）
    if (!redis_host_resolvable((string)$c['host'])) {
        redis_stat_inc('dns_fail');
        redis_set_last_error('主机名解析失败：' . (string)$c['host'] . ' 在本机解析不出来');
        $failAt = time();
        return $sock = null;
    }
    $errno = 0; $errstr = '';
    $s = @fsockopen($c['host'], (int)$c['port'], $errno, $errstr, $connect);
    // 注意：这条纯 PHP 兜底路径**刻意不做持久化**（不用 pfsockopen）——
    // 它只在没装 phpredis 扩展时才会走到，属于兼容路径，宁可简单可预测；
    // 生产上装了扩展走的是上面 redis_native_conn() 的 pconnect（持久）那条。
    if (!$s) { redis_stat_inc('connect_fail'); $failAt = time(); return $sock = null; }
    stream_set_timeout($s, 0, $readUs);
    $sock = $s;
    if ((string)$c['password'] !== '') {
        $r = redis_raw('AUTH', [(string)$c['password']]);
        if (!$r || $r['t'] === 'error') {
            $msg = (string)($r['v'] ?? '');
            // 服务端没设密码时 AUTH 会报 "without any password configured" / "no password is set"：
            // 那是"密码多余"，不是错密码 —— 照常使用这条连接（与 redis_native_conn 同一套容错）。
            if (stripos($msg, 'without any password configured') !== false || stripos($msg, 'no password is set') !== false) {
                redis_stat_inc('auth_skipped');
            } else {
                redis_stat_inc('auth_fail');
                @fclose($sock);
                $failAt = time();
                return $sock = null;
            }
        }
    }
    if ((int)$c['database'] > 0) redis_raw('SELECT', [(string)(int)$c['database']]);
    return $sock;
}

/** 读一条 RESP 回复 */
function redis_read_reply($s)
{
    $line = @fgets($s, 8192);
    if ($line === false || $line === '') return null;
    $line = rtrim($line, "\r\n");
    if ($line === '') return null;
    $tag = $line[0];
    $rest = substr($line, 1);
    switch ($tag) {
        case '+': return ['t' => 'status', 'v' => $rest];
        case '-': return ['t' => 'error',  'v' => $rest];
        case ':': return ['t' => 'int',    'v' => (int)$rest];
        case '$':
            $len = (int)$rest;
            if ($len < 0) return ['t' => 'nil', 'v' => null];
            $buf = '';
            while (strlen($buf) < $len + 2) {
                $chunk = @fread($s, $len + 2 - strlen($buf));
                if ($chunk === false || $chunk === '') {
                    // 还没读满就读到空 → 读超时/被截断。绝不能把截断值当完整值返回：
                    // Redis 值与后续回复之间没有帧同步标记，一旦错位后面全乱（可能读到半截版本号）。
                    // 直接返回 null：上层 fail-open 当未命中，并且会关掉这条连接重连。
                    redis_stat_inc('read_short');
                    return null;
                }
                $buf .= $chunk;
            }
            return ['t' => 'bulk', 'v' => substr($buf, 0, $len)];
        case '*':
            $n = (int)$rest;
            if ($n < 0) return ['t' => 'nil', 'v' => null];
            $arr = [];
            for ($i = 0; $i < $n; $i++) {
                $sub = redis_read_reply($s);
                if ($sub === null) return null;
                $arr[] = $sub['v'];
            }
            return ['t' => 'array', 'v' => $arr];
    }
    return null;
}

/** 发一条命令，返回 ['t'=>类型,'v'=>值]；连接/写入失败返回 null */
function redis_raw(string $cmd, array $args = [])
{
    // 装了 phpredis 就走原生，否则走下面的纯 PHP RESP
    if (redis_native()) {
        $o = redis_native_conn();
        if (!$o) return null;
        return redis_native_call($o, strtoupper($cmd), $args);
    }
    $s = redis_conn();
    if (!$s) return null;
    $out = '*' . (count($args) + 1) . "\r\n";
    foreach (array_merge([$cmd], $args) as $a) {
        $a = (string)$a;
        $out .= '$' . strlen($a) . "\r\n" . $a . "\r\n";
    }
    if (@fwrite($s, $out) === false) { @fclose($s); redis_conn(true); redis_stat_inc('write_fail'); return null; }
    $r = redis_read_reply($s);
    if ($r === null) { @fclose($s); redis_conn(true); redis_stat_inc('read_fail'); return null; }
    if ($r['t'] === 'error') redis_stat_inc('cmd_error');
    return $r;
}

/* ------------------------------ 原生扩展优先（phpredis） ------------------------------ */

/**
 * 装了 phpredis 扩展就用它（持久连接、无 RESP 解析开销），没装就退回下面的纯 PHP RESP 实现。
 * 两条路的**对外契约完全一样**（都经 redis_raw() 返回 ['t'=>类型,'v'=>值]），
 * 所以上层 helper（get/set/ttl/scan/设备状态…）一行都不用改。
 * 想强制走纯 PHP 实现（比如排查扩展问题）时：define('MAPI_REDIS_NO_EXT', true)。
 */
function redis_native(): bool
{
    return !defined('MAPI_REDIS_NO_EXT') && class_exists('Redis');
}

/** 连接代数：测试连接换 host/密码、或读写失败要重连时 +1，原生实例按它失效重连 */
function redis_generation(): int { return (int)($GLOBALS['__redis_gen'] ?? 1); }

/** 重置连接（两种实现一起重置） */
function redis_reset(): void
{
    $GLOBALS['__redis_gen'] = redis_generation() + 1;
    redis_conn(true);                       // 纯 PHP：关掉 socket
    // 顺手清掉"这个主机名解析不了"的缓存结论：用户可能刚把主机改成能用的地址，
    // 不清的话接下来 5 分钟还会被判失败（"保存并测试"就看不到真实结果）。
    $host = (string)(redis_conf()['host'] ?? '');
    if ($host !== '') @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mapi-dns-' . substr(sha1($host), 0, 12));
}

/** 原生连接（单例，按代数失效）；连不上返回 null —— 调用方一律当作"未命中" */
function redis_native_conn()
{
    static $obj = null, $gen = -1, $failAt = 0;
    $g = redis_generation();
    if ($gen === $g) {
        if ($obj !== null) return $obj;                 // 连上了就一直用
        if (time() - $failAt < 5) return null;          // 失败后 5 秒内不重试（避免每个命令都去连），但**会自愈**
        // 超过冷却期：落到下面重试（Redis 重启/网络抖动后无需人工干预）
    }
    $gen = $g; $obj = null;

    $c = redis_conf();
    if (empty($c['enabled'])) return null;
    $tmo = max(0.1, (float)($c['timeout'] ?? 2));

    // ① **主机名先过一遍解析关**：解析不了的主机名（Docker 容器名、写错的域名）会让 phpredis 在 DNS 上
    //    白等 1 秒多，而且会**抛 PHP Warning** —— display_errors/xdebug 打开时这段 Warning 会被塞进
    //    JSON 响应体，前台直接解析失败（实测：每个接口 1.3s + 前台弹"服务器返回异常"）。
    //    结论用文件缓存 5 分钟：多 worker 只付一次代价，且不产生任何输出。
    if (!redis_host_resolvable((string)$c['host'])) {
        redis_stat_inc('dns_fail');
        redis_set_last_error('主机名解析失败：' . (string)$c['host']
            . ' 在本机解析不出来（Docker 容器名只在容器网络内有效，这里要填能访问到的 IP 或域名）');
        $failAt = time();
        return null;
    }

    $o = null;
    // ② 连接期间的任何 PHP Warning 一律吞掉：绝不能泄漏进响应体（那会污染 JSON）
    $prevHandler = set_error_handler(function () { return true; });
    try {
        $o = new Redis();
        // **持久连接**：php-cgi 的 worker 是常驻进程，pconnect 能让同一条连接跨请求复用 ——
        // 实测新建 connect+AUTH 中位 2.15ms，复用只要 0.78ms；热路径（action=song 命中）总耗时约 7ms，
        // 其中两成多就是这个握手。
        // persistent_id 带上 host:port:db：换配置/换库时不会误用到别的服务器那条连接。
        $pid = 'msapi:' . $c['host'] . ':' . (int)$c['port'] . ':' . (int)$c['database'];
        // 连接 0.3s / 读写 2s：与纯 PHP 实现保持同一套上限（曾经这里夹 2s，比纯 PHP 慢一倍）
        if (!$o->pconnect((string)$c['host'], (int)$c['port'], (float)min($tmo, 0.3), $pid, 0, (float)min($tmo, 2))) {
            redis_stat_inc('connect_fail');
            $failAt = time();
            try { $o->close(); } catch (Throwable $e2) {}   // 丢掉池里这条坏的
            return null;
        }
        if ((string)$c['password'] !== '') {
            try {
                if (!$o->auth((string)$c['password'])) {
                    redis_stat_inc('auth_fail');
                    $failAt = time();
                    try { $o->close(); } catch (Throwable $e2) {}
                    return null;
                }
            } catch (Throwable $ea) {
                // 服务端**没设密码**时，Redis 会对 AUTH 直接报错：
                //   "ERR AUTH <password> called without any password configured for the default user"
                // 这时候密码是"多余"的，不是错密码 —— 必须照常使用这条连接。
                // （踩过：FlyEnv 重启会重写 redis 配置、把 requirepass 抹掉，模块却仍发 AUTH，
                //   于是整个缓存哑掉、后台卡片显示"未连通"，看起来像"保存设置没生效"。）
                if (stripos($ea->getMessage(), 'without any password configured') !== false
                    || stripos($ea->getMessage(), 'no password is set') !== false) {
                    redis_stat_inc('auth_skipped');
                } else {
                    redis_stat_inc('auth_fail');
                    $failAt = time();
                    try { $o->close(); } catch (Throwable $e2) {}
                    return null;
                }
            }
        }
        if ((int)$c['database'] > 0) $o->select((int)$c['database']);
        $o->setOption(Redis::OPT_READ_TIMEOUT, (float)min($tmo, 2));
        $failAt = 0;
        return $obj = $o;
    } catch (Throwable $e) {
        redis_stat_inc('connect_fail');
        $failAt = time();
        redis_set_last_error('连接失败：' . $e->getMessage());
        // 关键：把这条连接**关掉**，别让坏连接留在 phpredis 的持久连接池里
        try { if ($o instanceof Redis) $o->close(); } catch (Throwable $e2) {}
        return null;
    } finally {
        // 无论成败都恢复原来的错误处理：这层"吞警告"只覆盖连接这几行
        restore_error_handler();
    }
}

/** 把原生返回的 PHP 值翻译成与 redis_read_reply() 相同的 ['t'=>…,'v'=>…] 形状 */
function redis_native_call($o, string $cmd, array $args)
{
    try {
        $v = $o->rawCommand($cmd, ...$args);
    } catch (Throwable $e) {
        // 实测：phpredis 只在协议/认证类问题上抛 RedisException（如 AUTH 密码错、连接已断），
        // 而服务端回 -ERR（WRONGTYPE / 未知命令 / 只读副本拒绝写）时返回 **false**。
        // 两种情况都当作"这次缓存没成功"，调用方照常 fail-open。
        redis_stat_inc('cmd_error');
        // 持久连接出了这类错，说明池里这条已经不干净了（服务端重启 / 被 CLIENT KILL / 认证失效）：
        // 主动丢掉并让代数 +1，下一条命令重新建连 —— 否则这个 worker 会一直粘着坏连接，
        // 表现为"缓存静默失效"直到进程回收。（phpredis 自己也会重连，这里是双保险。）
        redis_native_drop($o);
        return ['t' => 'error', 'v' => $e->getMessage()];
    }
    if ($v === true)  return ['t' => 'status', 'v' => strtoupper($cmd) === 'PING' ? 'PONG' : 'OK'];
    if ($v === false) { redis_stat_inc('native_false'); return ['t' => 'nil', 'v' => null]; }   // 未命中 / -ERR / 未知命令
    if ($v === null)  return ['t' => 'nil', 'v' => null];
    if (is_int($v))   return ['t' => 'int', 'v' => $v];
    if (is_array($v)) return ['t' => 'array', 'v' => $v];
    return ['t' => 'bulk', 'v' => (string)$v];
}

/** 连接是否真的可用（两种实现通用）—— 需要"明确知道通不通"的地方用它 */
function redis_ready(): bool
{
    if (!redis_enabled()) return false;
    return redis_native() ? (bool)redis_native_conn() : (bool)redis_conn();
}

/**
 * 丢掉原生持久连接（自愈）。实测 `close()` 对 pconnect 建出来的连接**确实有效**
 * （丢完再 pconnect 会拿到新的 CLIENT ID），这版 phpredis 没有 pclose()。
 * 同时让代数 +1，保证下一条命令一定重新建连。
 */
function redis_native_drop($o): void
{
    try { if ($o instanceof Redis) $o->close(); } catch (Throwable $e) { /* 忽略 */ }
    redis_reset();
}

/* ------------------------------ 基础命令封装 ------------------------------ */

function redis_ping(): bool
{
    $r = redis_raw('PING');
    return $r && $r['t'] === 'status' && strtoupper((string)$r['v']) === 'PONG';
}

function redis_get(string $key): ?string
{
    $r = redis_raw('GET', [redis_key($key)]);
    if (!$r || $r['t'] === 'nil' || $r['t'] === 'error') { redis_stat_inc('miss'); return null; }
    redis_stat_inc('hit');
    return (string)$r['v'];
}

/** ttl>0 → SETEX；ttl=0 → SET（永久） */
function redis_set(string $key, string $val, int $ttl = 0): bool
{
    $r = $ttl > 0 ? redis_raw('SETEX', [redis_key($key), (string)$ttl, $val])
                  : redis_raw('SET',   [redis_key($key), $val]);
    return $r && $r['t'] === 'status' && strtoupper((string)$r['v']) === 'OK';
}

function redis_del(string $key): bool
{
    $r = redis_raw('DEL', [redis_key($key)]);
    return $r && $r['t'] === 'int' && (int)$r['v'] >= 0;
}

/**
 * 前缀删除（按模式扫）：目前只有测试脚本在用（生产失效走 cfgver 版本号，不删键）。
 * 用 SCAN 不阻塞；`$maxRounds` 是防止超大 keyspace 下无限扫的护栏 ——
 * 达到上限会**提前返回**，此时返回值小于实际键数，调用方不要把它当作"已删干净"。
 */
function redis_del_prefix(string $prefix, int $maxRounds = 200): int
{
    $cursor = '0'; $n = 0; $guard = 0;
    do {
        $r = redis_raw('SCAN', [$cursor, 'MATCH', redis_key($prefix) . '*', 'COUNT', '200']);
        if (!$r || $r['t'] !== 'array') break;
        $cursor = (string)$r['v'][0];
        foreach ((array)($r['v'][1] ?? []) as $k) {
            $d = redis_raw('DEL', [$k]);
            $n += ($d && $d['t'] === 'int') ? (int)$d['v'] : 0;   // 按实际删除数计数（不再"发一条算一个"）
        }
    } while ($cursor !== '0' && ++$guard < $maxRounds);
    return $n;
}

function redis_incr(string $key): ?int
{
    $r = redis_raw('INCR', [redis_key($key)]);
    return ($r && $r['t'] === 'int') ? (int)$r['v'] : null;
}

function redis_ttl(string $key): ?int
{
    $r = redis_raw('TTL', [redis_key($key)]);
    return ($r && $r['t'] === 'int') ? (int)$r['v'] : null;
}

/** 拼键：统一前缀；各段用冒号分隔（避免调用方各写各的） */
function redis_key(string $key): string
{
    $p = (string)redis_conf()['prefix'];
    return $p . ltrim($key, ':');
}

/* ------------------------------ JSON 缓存封装 ------------------------------ */

/** 读 JSON 缓存。命中返回数组；未命中/失效/Redis 不可用一律返回 null（调用方回源） */
function cache_json_get(string $key, string $bucket = '')
{
    if (!redis_enabled()) return null;
    $raw = redis_get($key);
    if ($raw === null || $raw === '') return null;
    $val = json_decode($raw, true);
    if (!is_array($val)) { redis_stat_inc('bad_json'); return null; }
    if ($bucket !== '') redis_stat_inc('hit_' . $bucket);
    return $val;
}

function cache_json_set(string $key, $val, int $ttl, string $bucket = ''): void
{
    if (!redis_enabled() || $ttl <= 0) return;
    // JSON_INVALID_UTF8_SUBSTITUTE：上游歌词/歌名里混进非 UTF-8 字节时，
    // 不加这个 flag 会让 json_encode 直接返回 false → 整次缓存写被静默丢掉（且无人察觉）。
    $json = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || $json === '') { redis_stat_inc('bad_json_set'); return; }
    if (redis_set($key, $json, $ttl) && $bucket !== '') redis_stat_inc('set_' . $bucket);
}

/* ------------------------------ 设备标识与状态（IP + cookie 校验） ------------------------------ */

/** IP 网段哈希：IPv4 取 /24、IPv6 取 /64 —— 同网段换基站/公司出口不误伤，换城市才判定为不同 */
function device_ip_hash(string $ip): string
{
    $ip = trim($ip);
    if ($ip === '') return 'none';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $p = explode('.', $ip);
        return substr(hash('sha256', $p[0] . '.' . $p[1] . '.' . $p[2]), 0, 16);
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $bin = @inet_pton($ip);
        if ($bin === false) return 'v6bad';
        return substr(hash('sha256', substr($bin, 0, 8)), 0, 16);
    }
    return substr(hash('sha256', $ip), 0, 16);
}

/** 设备 id 合法性：只接受 32~64 位十六进制（自己发的格式），避免被塞任意键名 */
function device_id_valid(string $dev): bool
{
    return (bool)preg_match('/^[a-f0-9]{32,64}$/i', $dev);
}

function device_new_id(): string { return bin2hex(random_bytes(16)); }

/** 设备绑定：dv:<设备id> → {"iph":网段哈希,"first":ts,"last":ts} */
function device_binding_get(string $dev): ?array
{
    if (!device_id_valid($dev)) return null;
    return cache_json_get('dv:' . $dev, 'device');
}

/**
 * 校验（IP + 设备 cookie 双重）并取出状态。
 * 返回 ['ok'=>bool,'dev'=>设备id,'state'=>array,'reason'=>...]
 *   · 设备未登记             → ok=false, reason=new（调用方发新 cookie）
 *   · 网段一致               → ok=true
 *   · 网段不一致但在宽限期内  → ok=true，并把绑定更新到新网段（手机换网/出差）
 *   · 网段不一致且已过期      → ok=false, reason=ip_mismatch（当作新设备，不发旧状态）
 */
function device_state_get(string $dev, string $ip): array
{
    $c = redis_conf();
    // 校验没跑起来时**原样回传调用方的设备号**（不要回空串、更不要凭空发新号）：
    // 回空串会让上层"顺手补发一个设备号"，Redis 一抖客户端就换了号，恢复后旧状态再也对不上。
    if (!redis_enabled()) return ['ok' => false, 'dev' => $dev, 'state' => [], 'reason' => 'disabled'];
    // 配置是开的但连不上（密码错/端口不通）：明确报 unavailable，别假装"新设备"让调用方误判
    if (!redis_ready()) return ['ok' => false, 'dev' => $dev, 'state' => [], 'reason' => 'unavailable'];
    if (!device_id_valid($dev)) return ['ok' => false, 'dev' => device_new_id(), 'state' => [], 'reason' => 'new'];

    $iph  = device_ip_hash($ip);
    $bind = device_binding_get($dev);
    if (!$bind) return ['ok' => false, 'dev' => $dev, 'state' => [], 'reason' => 'new'];

    $sameNet = hash_equals((string)($bind['iph'] ?? ''), $iph);
    $recent  = (time() - (int)($bind['last'] ?? 0)) <= max(0, (int)$c['ip_grace']);
    if (!$sameNet && !empty($c['strict_ip']) && !$recent) {
        redis_stat_inc('state_ip_mismatch');
        return ['ok' => false, 'dev' => device_new_id(), 'state' => [], 'reason' => 'ip_mismatch'];
    }
    if (!$sameNet) {                                  // 宽限内换网：更新绑定，继续用同一份状态
        $bind['iph'] = $iph;
        redis_stat_inc('state_ip_rebind');
    }
    $bind['last'] = time();
    cache_json_set('dv:' . $dev, $bind, (int)$c['ttl_state'], 'device');

    $state = cache_json_get('st:' . $dev, 'state') ?: [];
    return ['ok' => true, 'dev' => $dev, 'state' => $state, 'reason' => $sameNet ? 'ok' : 'ip_rebound'];
}

/** 写状态（同样要过 IP+设备校验）；返回 ['ok'=>bool,'reason'=>...] */
function device_state_set(string $dev, string $ip, array $state): array
{
    $c = redis_conf();
    if (!redis_enabled()) return ['ok' => false, 'reason' => 'disabled'];
    // 连不上就必须明说"没写进去"，不能让前端以为云端已保存（它还会继续依赖 cookie，但要能知道真相）
    if (!redis_ready()) return ['ok' => false, 'reason' => 'unavailable'];
    if (!device_id_valid($dev)) return ['ok' => false, 'reason' => 'bad_device'];

    $iph  = device_ip_hash($ip);
    $bind = device_binding_get($dev);
    if ($bind) {
        $sameNet = hash_equals((string)($bind['iph'] ?? ''), $iph);
        $recent  = (time() - (int)($bind['last'] ?? 0)) <= max(0, (int)$c['ip_grace']);
        if (!$sameNet && !empty($c['strict_ip']) && !$recent) {
            redis_stat_inc('state_write_reject');
            return ['ok' => false, 'reason' => 'ip_mismatch'];
        }
        $bind['iph'] = $iph;
    } else {
        $bind = ['iph' => $iph, 'first' => time()];
    }
    // 先做体量校验再动绑定：被拒的写不该刷新绑定时间（否则会白白延长 IP 宽限期）。
    // 状态体量很小（歌单/曲目/进度/音量/模式）；超 4KB 直接拒绝，避免有人往里塞垃圾
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false || strlen($json) > 4096) return ['ok' => false, 'reason' => 'too_large'];

    $bind['last'] = time();
    cache_json_set('dv:' . $dev, $bind, (int)$c['ttl_state'], 'device');
    cache_json_set('st:' . $dev, $state, (int)$c['ttl_state'], 'state');
    return ['ok' => true, 'reason' => 'ok'];
}

/* ------------------------------ 歌单配置缓存：版本号与失效 ------------------------------ */

/**
 * 歌单配置 JSON 的缓存版本号。客户端缓存键里带这个版本号，所以
 * 管理端一改配置/歌单，只要把版本号 +1，旧键立刻"不再是当前版本"，无需遍历删除。
 * Redis 不可用时返回 0（调用方会退化成"每请求都重建"）。
 */
function cfg_cache_version(): int
{
    if (!redis_ready()) return 0;
    $v = redis_get('cfgver');
    if ($v === null) { redis_set('cfgver', '1', 0); return 1; }
    return max(1, (int)$v);
}

/** 管理端写操作后调用：让所有歌单配置缓存立即失效（下次请求重建） */
function cfg_cache_invalidate(): void
{
    if (!redis_ready()) return;
    redis_incr('cfgver');
}

/** 管理端用的连通性诊断（结构化返回，不抛异常） */
function redis_diagnose(): array
{
    $c = redis_conf(true);
    redis_reset();                          // 用当前配置重新建连（测试连接时可能刚换了 host/密码）
    $out = ['enabled' => !empty($c['enabled']), 'target' => $c['host'] . ':' . $c['port'] . ' db' . (int)$c['database'],
            'prefix' => (string)$c['prefix'], 'ok' => false, 'msg' => '', 'info' => [],
            'impl' => redis_native() ? 'phpredis ' . phpversion('redis') . ' 扩展' : '纯 PHP RESP'];
    if (empty($c['enabled'])) { $out['msg'] = '未启用'; return $out; }
    if (!redis_ping()) {
        // 有具体原因就说具体原因（主机名解析不了 / 连接异常），别让人对着"PING 失败"猜
        $why = redis_get_last_error();
        $out['msg'] = $why !== '' ? $why : 'PING 失败（进程没起来 / 端口不通 / 密码不对）';
        $out['stats'] = redis_stat_all();
        return $out;
    }
    $out['ok'] = true;
    $out['msg'] = 'PONG';
    $r = redis_raw('INFO', ['keyspace']);
    if ($r && $r['t'] === 'bulk') $out['info']['keyspace'] = trim((string)$r['v']);
    if ($r && $r['t'] === 'array') {                     // 个别构建把 INFO 当多行批量返回
        $ks = '';
        foreach ((array)$r['v'] as $i => $vv) if (is_string($vv) && str_starts_with($vv, 'db')) $ks = trim($vv);
        if ($ks !== '') $out['info']['keyspace'] = $ks;
    }
    $r = redis_raw('DBSIZE');
    if ($r && $r['t'] === 'int') $out['info']['dbsize'] = (int)$r['v'];
    $r = redis_raw('CONFIG', ['GET', 'maxmemory']);
    if ($r && $r['t'] === 'array' && count($r['v']) === 2) $out['info']['maxmemory'] = (string)$r['v'][1];
    $r = redis_raw('SCAN', ['0', 'MATCH', redis_key('') . '*', 'COUNT', '500']);
    if ($r && $r['t'] === 'array') $out['info']['our_keys_sample'] = count((array)($r['v'][1] ?? []));
    return $out;
}
