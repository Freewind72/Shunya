<?php

function db_connect() {
    static $instance = null;
    if ($instance !== null) return $instance;
    global $CFG;
    $c = $CFG['db'];
    $type = $c['type'] ?? 'mysql';
    if ($type === 'sqlite') {
        try {
            $path = $c['path'];
            if ($path && !preg_match('#^[A-Z]:#i', $path) && $path[0] !== '/') {
                $path = dirname(__DIR__, 2) . '/' . $path;
            }
            $instance = new DB_Sqlite($path);
        } catch (Throwable $e) {
            $instance = null;
        }
        return $instance;
    }
    try {
        $instance = new DB_Mysql($c['hosts'], $c['user'], $c['password'], $c['database'], $c['port']);
    } catch (Throwable $e) {
        $instance = null;
    }
    return $instance;
}

class DB_Mysql {
    private $mysqli;
    private $hosts;
    private $user;
    private $pass;
    private $dbname;
    private $port;
    private $lastHostIdx = 0;
    public $affected_rows;
    public $insert_id;
    public $error;
    public $connect_error;
    public $num_rows = 0;

    public function __construct($hosts, $user, $pass, $db, $port) {
        $this->hosts = is_array($hosts) ? $hosts : [$hosts];
        $this->user = $user;
        $this->pass = $pass;
        $this->dbname = $db;
        $this->port = (int)$port;
        $this->_connect();
    }

    private function _connect() {
        $this->mysqli = null;
        if (empty($this->hosts)) {
            $this->connect_error = 'No hosts configured';
            return false;
        }
        $total = count($this->hosts);
        for ($attempt = 0; $attempt < $total; $attempt++) {
            $hostIdx = ($this->lastHostIdx + $attempt) % $total;
            $host = 'p:' . $this->hosts[$hostIdx];
            try {
                $m = mysqli_init();
                if (!$m) { $this->connect_error = 'mysqli_init failed'; continue; }
                mysqli_options($m, MYSQLI_OPT_CONNECT_TIMEOUT, 3);
                mysqli_options($m, MYSQLI_OPT_READ_TIMEOUT, 10);
                mysqli_options($m, MYSQLI_OPT_LOCAL_INFILE, false);
                $ok = @mysqli_real_connect($m, $host, $this->user, $this->pass, $this->dbname, $this->port);
                if (!$ok) {
                    $this->connect_error = mysqli_connect_error() ?: 'connect failed';
                    @mysqli_close($m);
                    continue;
                }
                mysqli_set_charset($m, 'utf8mb4');
                @mysqli_query($m, "SET session wait_timeout=28800, interactive_timeout=28800, autocommit=1");
                $this->mysqli = $m;
                $this->connect_error = null;
                $this->lastHostIdx = $hostIdx;
                return true;
            } catch (Throwable $e) {
                $this->connect_error = $e->getMessage();
            }
        }
        error_log('MAPI DB connection failed: all hosts unreachable');
        return false;
    }

    private function _ensureAlive() {
        if ($this->mysqli === null) return false;
        if (@mysqli_ping($this->mysqli)) return true;
        @mysqli_close($this->mysqli);
        $this->mysqli = null;
        return $this->_connect();
    }

    private function _ok() {
        return $this->mysqli !== null && !$this->connect_error;
    }

    public function query($sql) {
        if (!$this->_ensureAlive()) { $this->error = $this->connect_error ?? 'DB not connected'; return false; }
        $r = @$this->mysqli->query($sql);
        if ($r === false) {
            $code = $this->mysqli->errno;
            if ($code === 2006 || $code === 2013) {
                if ($this->_connect()) {
                    $r = @$this->mysqli->query($sql);
                }
            }
            if ($r === false) {
                $this->error = $this->mysqli->error;
                return false;
            }
        }
        $this->affected_rows = $this->mysqli->affected_rows;
        $this->insert_id = $this->mysqli->insert_id;
        $this->error = '';
        if ($r === true) {
            $this->num_rows = $this->mysqli->affected_rows;
            return true;
        }
        $this->num_rows = $r->num_rows;
        return new DB_MysqlResult($r);
    }

    public function prepare($sql) {
        if (!$this->_ensureAlive()) { $this->error = $this->connect_error ?? 'DB not connected'; return false; }
        $stmt = @$this->mysqli->prepare($sql);
        if (!$stmt) {
            $code = $this->mysqli->errno;
            if ($code === 2006 || $code === 2013) {
                if ($this->_connect()) {
                    $stmt = @$this->mysqli->prepare($sql);
                }
            }
            if (!$stmt) {
                $this->error = $this->mysqli->error;
                return false;
            }
        }
        return new DB_MysqlStmt($stmt, $this);
    }

    public function real_escape_string($str) {
        if (!$this->_ensureAlive()) return $str;
        return $this->mysqli->real_escape_string($str);
    }

    public function set_charset($charset) {
        if (!$this->_ensureAlive()) return;
        $this->mysqli->set_charset($charset);
    }

    public function close() {
        if ($this->mysqli) {
            @mysqli_close($this->mysqli);
            $this->mysqli = null;
        }
    }

    public function __get($name) {
        if (!$this->_ok()) return null;
        if ($name === 'affected_rows') return $this->mysqli->affected_rows;
        if ($name === 'insert_id') return $this->mysqli->insert_id;
        if ($name === 'error') return $this->mysqli->error;
        if ($name === 'connect_error') return $this->mysqli->connect_error;
        return null;
    }
}

class DB_MysqlResult {
    private $result;
    public $num_rows;

    public function __construct($result) {
        $this->result = $result;
        $this->num_rows = $result->num_rows;
    }

    public function fetch_assoc() {
        return $this->result->fetch_assoc();
    }

    public function fetch_row() {
        return $this->result->fetch_row();
    }

    public function fetch_all($mode = MYSQLI_ASSOC) {
        return $this->result->fetch_all($mode);
    }
}

class DB_MysqlStmt {
    private $stmt;
    private $db;
    private $params = [];
    private $types = '';

    public function __construct($stmt, $db) {
        $this->stmt = $stmt;
        $this->db = $db;
    }

    public function bind_param($types, &...$vars) {
        $this->types = $types;
        $this->params = [];
        foreach ($vars as $v) $this->params[] = $v;
        return $this->stmt->bind_param($types, ...$vars);
    }

    public function execute() {
        $ok = $this->stmt->execute();
        if (!$ok) $this->db->error = $this->stmt->error;
        // 回写受影响行数与自增 id（与 DB_SqliteStmt::execute 保持一致）：
        // DB_Mysql::$insert_id 是已声明的公共属性，__get 不会触发，不回写则恒为 0
        $this->db->affected_rows = $this->stmt->affected_rows;
        $this->db->insert_id = $this->stmt->insert_id;
        return $ok;
    }

    public function get_result() {
        $r = $this->stmt->get_result();
        if (!$r) return false;
        return new DB_MysqlResult($r);
    }

    public function __get($name) {
        if ($name === 'affected_rows') return $this->stmt->affected_rows;
        if ($name === 'insert_id') return $this->stmt->insert_id;
        return null;
    }

    public function close() {
        return $this->stmt->close();
    }
}

class DB_Sqlite {
    public $sqlite;
    public $affected_rows = 0;
    public $insert_id = 0;
    public $error = '';
    public $connect_error = null;
    public $num_rows = 0;

    public function __construct($path) {
        $this->sqlite = null;
        try {
            $this->sqlite = new SQLite3($path);
            $this->sqlite->enableExceptions(true);
            $this->sqlite->exec('PRAGMA journal_mode=WAL');
            $this->sqlite->exec('PRAGMA foreign_keys=ON');
        } catch (Throwable $e) {
            $this->connect_error = $e->getMessage();
            error_log('MAPI SQLite connection failed: ' . $e->getMessage());
        }
    }

    private function _ok() {
        return $this->sqlite !== null && !$this->connect_error;
    }

    private function translate_sql($sql) {
        $s = trim($sql);
        $s = preg_replace('/DATE_ADD\(NOW\(\),\s*INTERVAL\s+(\d+)\s+DAY\)/i', "datetime('now', '+$1 days')", $s);
        $s = preg_replace('/DATE_ADD\((\w+(?:\.\w+)?),\s*INTERVAL\s+(\d+)\s+DAY\)/i', "datetime($1, '+$2 days')", $s);
        $s = preg_replace('/\bNOW\(\)/i', "datetime('now')", $s);
        $s = preg_replace('/\bCURDATE\(\)/i', "date('now')", $s);
        $s = $this->translate_if($s);
        $s = preg_replace('/\bREPLACE\s+INTO\b/i', 'INSERT OR REPLACE INTO', $s);
        $s = preg_replace('/\bAUTO_INCREMENT\b/i', 'AUTOINCREMENT', $s);
        $s = preg_replace('/\bON\s+UPDATE\s+CURRENT_TIMESTAMP\b/i', '', $s);
        $s = preg_replace('/ENGINE\s*=\s*InnoDB\s+DEFAULT\s+CHARSET\s*=\s*utf8mb4(\s+COLLATE\s*=\s*utf8mb4_unicode_ci)?\s*/i', ' ', $s);
        $s = preg_replace('/ON\s+DUPLICATE\s+KEY\s+UPDATE\s+(\w+)\s*=\s*\1\s*\+\s*(\d+),\s*(\w+)\s*=\s*\3\s*\+\s*VALUES\((\w+)\)/i', 'ON CONFLICT DO UPDATE SET $1 = $1 + $2, $3 = $3 + excluded.$4', $s);
        if (preg_match('/SHOW\s+COLUMNS\s+FROM\s+(\w+)\s+LIKE\s+\'([^\']+)\'/i', $s, $m)) {
            $s = "SELECT 1 FROM pragma_table_info('{$m[1]}') WHERE name='{$m[2]}'";
        }
        $s = preg_replace('/ALTER\s+TABLE\s+(\w+)\s+ADD\s+COLUMN\s+(\w+)\s+(.+?)\s+AFTER\s+\w+\s*/i', 'ALTER TABLE $1 ADD COLUMN $2 $3', $s);
        return $s;
    }

    private function translate_if($s) {
        $offset = 0;
        while (($pos = stripos($s, 'IF(', $offset)) !== false) {
            $start = $pos + 3;
            $depth = 1;
            $end = $start;
            while ($end < strlen($s) && $depth > 0) {
                if ($s[$end] === '(') $depth++;
                elseif ($s[$end] === ')') $depth--;
                $end++;
            }
            if ($depth !== 0) break;
            $inner = substr($s, $start, $end - $start - 1);
            $parts = [];
            $part = '';
            $d = 0;
            for ($i = 0; $i < strlen($inner); $i++) {
                $c = $inner[$i];
                if ($c === '(') $d++;
                elseif ($c === ')') $d--;
                elseif ($c === ',' && $d === 0) { $parts[] = trim($part); $part = ''; continue; }
                $part .= $c;
            }
            $parts[] = trim($part);
            if (count($parts) === 3) {
                $cond = $parts[0]; $t = $parts[1]; $f = $parts[2];
                if (stripos($cond, 'IS NULL') !== false || stripos($cond, 'IS NOT NULL') !== false) {
                    $replacement = "CASE WHEN $cond THEN $t ELSE $f END";
                } else {
                    $replacement = "IIF($cond, $t, $f)";
                }
                $s = substr_replace($s, $replacement, $pos, $end - $pos);
                $offset = $pos + strlen($replacement);
            } else {
                $offset = $end;
            }
        }
        return $s;
    }

    public function query($sql) {
        if (!$this->_ok()) { $this->error = $this->connect_error ?? 'DB not connected'; return false; }
        try {
            $sql = $this->translate_sql($sql);
            $result = $this->sqlite->query($sql);
            if ($result === false) {
                $this->error = $this->sqlite->lastErrorMsg();
                return false;
            }
            $this->affected_rows = $this->sqlite->changes();
            $this->insert_id = $this->sqlite->lastInsertRowID();
            $this->error = '';
            if ($result instanceof SQLite3Result) {
                $this->num_rows = 0;
                $rows = [];
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
                $this->num_rows = count($rows);
                return new DB_SqliteResult($rows);
            }
            $this->num_rows = $this->affected_rows;
            return true;
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function prepare($sql) {
        if (!$this->_ok()) { $this->error = $this->connect_error ?? 'DB not connected'; return false; }
        try {
            $sql = $this->translate_sql($sql);
            $stmt = $this->sqlite->prepare($sql);
            if (!$stmt) {
                $this->error = $this->sqlite->lastErrorMsg();
                return false;
            }
            return new DB_SqliteStmt($stmt, $this);
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            return false;
        }
    }

    public function real_escape_string($str) {
        return SQLite3::escapeString($str);
    }

    public function set_charset($charset) {
    }

    public function close() {
        if ($this->sqlite) $this->sqlite->close();
    }
}

class DB_SqliteResult {
    private $rows;
    private $pos = 0;
    public $num_rows;

    public function __construct($rows) {
        $this->rows = $rows;
        $this->num_rows = count($rows);
    }

    public function fetch_assoc() {
        if ($this->pos >= count($this->rows)) return null;
        return $this->rows[$this->pos++];
    }

    public function fetch_row() {
        $row = $this->fetch_assoc();
        return $row ? array_values($row) : null;
    }

    public function fetch_all() {
        return $this->rows;
    }
}

class DB_SqliteStmt {
    private $stmt;
    private $db;
    private $cachedResult = null;
    public $affected_rows = 0;
    public $insert_id = 0;

    public function __construct($stmt, $db) {
        $this->stmt = $stmt;
        $this->db = $db;
    }

    public function bind_param($types, &...$vars) {
        for ($i = 0; $i < count($vars); $i++) {
            $type = $types[$i] ?? 's';
            if ($type === 'i') {
                $this->stmt->bindValue($i + 1, (int)$vars[$i], SQLITE3_INTEGER);
            } elseif ($type === 'd') {
                $this->stmt->bindValue($i + 1, (float)$vars[$i], SQLITE3_FLOAT);
            } else {
                $this->stmt->bindValue($i + 1, $vars[$i], SQLITE3_TEXT);
            }
        }
        $this->cachedResult = null;
        return true;
    }

    public function execute() {
        try {
            $result = $this->stmt->execute();
            $this->affected_rows = $this->db->sqlite->changes();
            $this->insert_id = $this->db->sqlite->lastInsertRowID();
            $this->db->error = '';
            $this->db->affected_rows = $this->affected_rows;
            $this->db->insert_id = $this->insert_id;
            if ($result instanceof SQLite3Result) {
                $rows = [];
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
                $this->cachedResult = new DB_SqliteResult($rows);
                return $this->cachedResult;
            }
            $this->cachedResult = ($result !== false);
            return $this->cachedResult;
        } catch (Throwable $e) {
            $this->db->error = $e->getMessage();
            $this->cachedResult = false;
            return false;
        }
    }

    public function get_result() {
        return $this->cachedResult;
    }

    public function close() {
        return true;
    }
}