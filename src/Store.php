<?php
/**
 * SQLite 存储（对应 Go 的 internal/cloud/store.go，原为 PostgreSQL）
 * 两张表：wechat_users、wechat_sessions
 */

class Store
{
    private $pdo;
    private $lockPath;

    public function __construct($dbPath)
    {
        $dir = dirname($dbPath);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException(
                    "无法创建数据库目录 $dir：请检查项目目录权限，或手动创建该目录并赋予 Web 用户写权限。"
                );
            }
            // 确保 Web 用户（www/nginx）能写入
            @chmod($dir, 0775);
        }
        if (is_dir($dir) && !is_writable($dir)) {
            throw new RuntimeException(
                "数据库目录不可写：$dir\n" .
                "请执行：chown -R www:www " . dirname($dir) . " && chmod -R 775 " . dirname($dir) . "\n" .
                "（www 换成你的 Web 运行用户，如 nginx/apache）"
            );
        }
        try {
            $this->pdo = new PDO('sqlite:' . $dbPath, null, null, array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ));
        } catch (PDOException $e) {
            throw new RuntimeException(
                "无法打开 SQLite 数据库 $dbPath：" . $e->getMessage() . "\n" .
                "可能原因：目录不可写，或 SQLite 扩展未启用。检查目录权限：ls -ld " . dirname($dbPath)
            );
        }
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->lockPath = $dbPath . '.lock';
        $this->migrate();
    }

    public function pdo()
    {
        return $this->pdo;
    }

    private function migrate()
    {
        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS wechat_users (
    user_id         TEXT PRIMARY KEY,
    password_hash   TEXT NOT NULL,
    bot_token       TEXT NOT NULL,
    base_url        TEXT NOT NULL DEFAULT 'https://ilinkai.weixin.qq.com',
    get_updates_buf TEXT NOT NULL DEFAULT '',
    current_peer    TEXT NOT NULL DEFAULT '',
    peers           TEXT NOT NULL DEFAULT '{}',
    ready           INTEGER NOT NULL DEFAULT 0,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS wechat_sessions (
    token      TEXT PRIMARY KEY,
    user_id    TEXT NOT NULL REFERENCES wechat_users(user_id) ON DELETE CASCADE,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_wechat_sessions_expires ON wechat_sessions(expires_at);
SQL
        );
    }

    public function ping()
    {
        $this->pdo->query('SELECT 1');
        return true;
    }

    // ---------------- 用户 ----------------

    public function getUser($userId)
    {
        $userId = trim((string)$userId);
        if ($userId === '') {
            return null;
        }
        $st = $this->pdo->prepare('SELECT user_id, password_hash, bot_token, base_url, get_updates_buf, current_peer, peers, ready FROM wechat_users WHERE user_id = ?');
        $st->execute(array($userId));
        $row = $st->fetch();
        if ($row === false) {
            return null;
        }
        $row['peers'] = $this->decodePeers($row['peers']);
        $row['ready'] = (int)$row['ready'] === 1;
        return $row;
    }

    /** 写入/覆盖一个微信绑定（不用 UPSERT，兼容 SQLite < 3.24） */
    public function upsertUserBinding($userId, $passwordHash, $botToken, $baseUrl)
    {
        $this->withLock(function () use ($userId, $passwordHash, $botToken, $baseUrl) {
            $st = $this->pdo->prepare('SELECT 1 FROM wechat_users WHERE user_id = ?');
            $st->execute(array($userId));
            $exists = $st->fetch() !== false;

            if ($exists) {
                $st = $this->pdo->prepare(
                    'UPDATE wechat_users
                     SET password_hash=?, bot_token=?, base_url=?,
                         get_updates_buf=\'\', current_peer=\'\', peers=\'{}\',
                         ready=0, updated_at=datetime(\'now\')
                     WHERE user_id=?'
                );
                $st->execute(array($passwordHash, $botToken, $baseUrl, $userId));
            } else {
                $st = $this->pdo->prepare(
                    'INSERT INTO wechat_users
                       (user_id, password_hash, bot_token, base_url,
                        get_updates_buf, current_peer, peers, ready, updated_at)
                     VALUES (?, ?, ?, ?, \'\', \'\', \'{}\', 0, datetime(\'now\'))'
                );
                $st->execute(array($userId, $passwordHash, $botToken, $baseUrl));
            }

            $st = $this->pdo->prepare('DELETE FROM wechat_sessions WHERE user_id = ?');
            $st->execute(array($userId));
        });
    }

    /** 在事务锁内运行回调（SQLite 用 BEGIN IMMEDIATE 模拟行锁） */
    public function withLockedState($userId, callable $fn)
    {
        $this->withLock(function () use ($userId, $fn) {
            $u = $this->getUser($userId);
            if ($u === null) {
                throw new RuntimeException('用户不存在: ' . $userId);
            }
            $state = $this->toState($u);
            $peers = new Peers($state);
            $fn($state, $peers);
            $peers->applyToState($state);

            $st = $this->pdo->prepare(
                'UPDATE wechat_users SET get_updates_buf=?, current_peer=?, peers=?, ready=?, updated_at=datetime(\'now\') WHERE user_id=?'
            );
            $st->execute(array(
                $state['get_updates_buf'],
                $state['current_peer'],
                json_encode($state['peers'], JSON_UNESCAPED_UNICODE),
                (count($state['peers']) > 0 || $u['ready']) ? 1 : 0,
                $userId,
            ));
        });
    }

    // ---------------- 会话 ----------------

    public function createSession($userId, $ttlSeconds)
    {
        $token = Auth::randomToken();
        $st = $this->pdo->prepare('INSERT INTO wechat_sessions (token, user_id, expires_at) VALUES (?, ?, ?)');
        $st->execute(array($token, $userId, date('c', time() + $ttlSeconds)));
        return $token;
    }

    public function getSessionUser($token)
    {
        $st = $this->pdo->prepare('SELECT user_id FROM wechat_sessions WHERE token = ? AND expires_at > ?');
        $st->execute(array($token, date('c')));
        $row = $st->fetch();
        return $row === false ? null : $row['user_id'];
    }

    public function deleteSession($token)
    {
        $st = $this->pdo->prepare('DELETE FROM wechat_sessions WHERE token = ?');
        $st->execute(array($token));
    }

    public function deleteUserSessions($userId)
    {
        $st = $this->pdo->prepare('DELETE FROM wechat_sessions WHERE user_id = ?');
        $st->execute(array($userId));
    }

    public function cleanupExpired()
    {
        $st = $this->pdo->prepare('DELETE FROM wechat_sessions WHERE expires_at <= ?');
        $st->execute(array(date('c')));
    }

    // ---------------- 工具 ----------------

    private function withLock(callable $fn)
    {
        $fh = fopen($this->lockPath, 'c');
        if ($fh === false) {
            throw new RuntimeException('无法创建锁文件');
        }
        try {
            flock($fh, LOCK_EX);
            $fn();
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }
    }

    private function decodePeers($raw)
    {
        if (!is_string($raw) || trim($raw) === '') {
            return array();
        }
        $d = json_decode($raw, true);
        if (!is_array($d)) {
            return array();
        }
        foreach ($d as $k => $v) {
            if (is_string($v)) {
                $d[$k] = array('context_token' => $v);
            }
        }
        return $d;
    }

    private function toState(array $u)
    {
        return array(
            'bot_token'       => $u['bot_token'],
            'user_id'         => $u['user_id'],
            'base_url'        => $u['base_url'],
            'get_updates_buf' => $u['get_updates_buf'],
            'current_peer'    => $u['current_peer'],
            'peers'           => $u['peers'],
        );
    }
}
