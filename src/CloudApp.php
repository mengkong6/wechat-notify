<?php
/**
 * wechat-notify — 多用户通知/发件系统（对应 Go 的 internal/cloud/* + cmd/cloud/main.go）
 * 单文件内实现：命令行入口、路由、API 处理器、静态文件服务。
 *
 * 接口与原版保持一致：
 *  GET  /health
 *  POST /api/register/qr
 *  GET  /api/register/status
 *  POST /api/register/finish
 *  POST /api/login
 *  POST /api/logout
 *  GET  /api/me
 *  GET  /api/account/ready
 *  POST /api/send
 */

class CloudApp
{
    const COOKIE_NAME   = 'sid';
    const VERIFY_TIMEOUT = 12;

    private $store;
    private $pollTimeout;
    private $sendTimeout;
    private $webDir;
    private $dbPath;
    private $basePath;
    private $injectApiBase;

    public function __construct(Store $store, $pollTimeout, $sendTimeout, $webDir, $dbPath = null, $basePath = '')
    {
        $this->store       = $store;
        $this->pollTimeout = $pollTimeout > 0 ? (float)$pollTimeout : 60.0;
        $this->sendTimeout = $sendTimeout >= 0 ? (float)$sendTimeout : 0.5;
        $this->webDir      = rtrim($webDir, '/');
        $this->dbPath      = $dbPath !== null ? $dbPath : wn_config()['db_path'];
        $this->basePath    = self::normalizeBase($basePath);
        $this->injectApiBase = null;
    }

    /** 显式指定要注入首页的 API 基路径（root index.php 部署时用） */
    public function setInjectApiBase($base)
    {
        $this->injectApiBase = $base;
    }

    /** 归一化基路径：'/wechat-notify' -> '/wechat-notify'，'/wechat-notify/' -> '/wechat-notify'，空或'/' -> '' */
    private static function normalizeBase($base)
    {
        $base = trim((string)$base);
        if ($base === '' || $base === '/') {
            return '';
        }
        return '/' . trim($base, '/');
    }

    /**
     * 从当前请求推导应用基路径，覆盖多种部署形态：
     *  - Apache/Nginx 指向项目根，URL 形如 /wechat-notify/xxx   -> base=/wechat-notify
     *  - 内置服务器路由脚本 /wechat-notify/src/CloudApp.php       -> base=/wechat-notify
     *  - 根部署（URL /xxx）                                   -> base=''
     */
    private static function detectBaseFromScript()
    {
        // 优先：入口脚本所在的「目录」，去掉 /src 这种后端自身目录
        $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '';
        if ($script === '') {
            $script = isset($_SERVER['SCRIPT_FILENAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME']) : '';
        }
        $dir = rtrim(dirname($script), '/');
        if ($dir === '' || $dir === '/' || $dir === '.') {
            return '';
        }
        return $dir;
    }

    /** 命令行入口 */
    public static function cliMain($argv)
    {
        list($opts, $rest) = wn_parse_args($argv);
        $root = defined('WN_ROOT') ? WN_ROOT : dirname(__DIR__);

        $port = wn_opt($opts, 'port', getenv('WN_PORT') !== false && getenv('WN_PORT') !== ''
            ? getenv('WN_PORT')
            : (getenv('PORT') !== false && getenv('PORT') !== '' ? getenv('PORT') : 7860));
        $port = (int)$port;
        if ($port <= 0) {
            $port = 7860;
        }

        $web = wn_opt($opts, 'web', $root . '/public');
        $poll = (float)wn_opt($opts, 'poll', wn_config()['poll_timeout']);
        $send = (float)wn_opt($opts, 'send-timeout', wn_config()['send_timeout']);

        $db = wn_opt($opts, 'db', wn_config()['db_path']);
        $base = wn_opt($opts, 'base', getenv('WN_BASE') !== false ? getenv('WN_BASE') : '');

        $store = new Store($db);
        $app   = new self($store, $poll, $send, $web, $db, $base);
        $app->cleanupLoopStart();
        $app->listen($port);
        return 0;
    }

    /** 每 5 分钟清理过期会话 */
    private function cleanupLoopStart()
    {
        // PHP 请求内无法常驻后台，改为在每次请求时惰性清理
        $this->store->cleanupExpired();
    }

    public function listen($port)
    {
        $host = '0.0.0.0:' . $port;
        $url  = 'http://localhost:' . $port;
        wn_log('wechat-notify 通知系统已启动', $url);
        wn_log('前端目录', $this->webDir);

        // 把配置通过环境变量传给 php -S 的子进程（每个请求独立加载路由脚本）
        putenv('WN_DB=' . $this->dbPath);
        putenv('WN_POLL_TIMEOUT=' . $this->pollTimeout);
        putenv('WN_SEND_TIMEOUT=' . $this->sendTimeout);
        putenv('WN_WEB=' . $this->webDir);
        putenv('WN_BASE=' . $this->basePath);

        $cmd = sprintf(
            'php -S %s -t %s %s',
            escapeshellarg($host),
            escapeshellarg($this->webDir),
            escapeshellarg(__FILE__)
        );
        passthru($cmd, $status);
    }

    /**
     * PHP 内置服务器路由器。内置服务器把所有请求交给此脚本，
     * /api/* 与 /health 走我们的处理器，静态文件直接输出。
     */
    public function handle($uri)
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if ($path === false || $path === null) {
            $path = '/';
        }

        // 1) 去掉子目录前缀，得到应用内相对路径
        $rel = $path;
        if ($this->basePath !== '') {
            if ($path === $this->basePath) {
                $rel = '/';
            } elseif ($path === $this->basePath . '/index.php' || strpos($path, $this->basePath . '/index.php/') === 0) {
                // 入口脚本直接命中：/wechat-notify/index.php 或 /wechat-notify/index.php/xxx
                $rel = substr($path, strlen($this->basePath . '/index.php'));
                if ($rel === false || $rel === '') {
                    $rel = '/';
                }
            } elseif (strpos($path, $this->basePath . '/') === 0) {
                $rel = substr($path, strlen($this->basePath));
            } elseif ($path === $this->basePath . '/index.php') {
                $rel = '/';
            } else {
                header('Location: ' . $this->basePath . '/', true, 302);
                return true;
            }
        }

        // 2) 去掉入口脚本名（/index.php 或 /index.php/api/me -> / 或 /api/me）
        $rel = $this->stripEntryScript($rel);

        // 3) 无 rewrite 部署时，PATH_INFO 承载真实路径（如 /index.php/api/me -> /api/me）
        $pi = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '';
        if ($pi !== '' && $pi !== '/') {
            $rel = '/' . ltrim($pi, '/');
        }

        // 4) 显式 _path 参数兜底（手动调用 /index.php?_path=api/me）
        if (isset($_GET['_path']) && trim($_GET['_path']) !== '') {
            $rel = '/' . ltrim(trim($_GET['_path']), '/');
        }

        // 5) 静态资源 / API 分发
        if (strpos($rel, '/api/') !== 0 && $rel !== '/health') {
            $file = $this->webDir . ($rel === '/' ? '/index.html' : $rel);
            $real = realpath($file);
            if ($real !== false && strpos($real, realpath($this->webDir)) === 0 && is_file($real)) {
                header('Content-Type: ' . $this->mime($real));
                readfile($real);
                return true;
            }
            // SPA 回退：注入 API 基路径后返回首页
            $index = $this->webDir . '/index.html';
            if (is_file($index)) {
                $html = file_get_contents($index);
                header('Content-Type: text/html; charset=utf-8');
                echo $this->injectApiBase($html);
                return true;
            }
        }

        $method = $_SERVER['REQUEST_METHOD'];
        if ($method === 'OPTIONS') {
            $this->cors();
            http_response_code(204);
            return true;
        }

        $this->cors();
        try {
            $this->dispatch($method, $rel);
        } catch (Exception $e) {
            $this->json(array('error' => $e->getMessage()), 500);
        }
        return true;
    }

    /**
     * 从相对路径里去掉入口脚本名（如 /index.php、/src/CloudApp.php）。
     */
    private function stripEntryScript($rel)
    {
        // 优先用当前实际执行的脚本名
        $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '';
        $base = basename($script);
        if ($base !== '' && strpos($base, '.php') !== false) {
            if ($rel === '/' . $base) {
                return '/';
            }
            if (strpos($rel, '/' . $base . '/') === 0) {
                return substr($rel, strlen('/' . $base));
            }
        }
        // 兜底：匹配任意顶层的 *.php 段
        if (preg_match('#^/[^/]+\.php(/|$)#', $rel, $m)) {
            $rel = preg_replace('#^/[^/]+\.php#', '', $rel, 1);
            if ($rel === '') {
                return '/';
            }
        }
        return $rel;
    }

    private function mime($file)
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $map = array(
            'html' => 'text/html; charset=utf-8',
            'js'   => 'application/javascript; charset=utf-8',
            'css'  => 'text/css; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
            'ico'  => 'image/x-icon',
            'txt'  => 'text/plain; charset=utf-8',
            'woff' => 'font/woff',
            'woff2'=> 'font/woff2',
        );
        return isset($map[$ext]) ? $map[$ext] : 'application/octet-stream';
    }

    /**
     * 把 API 入口注入首页 HTML：在 <head> 里加一段
     * `window.WN_API_ENTRY = '/wechat-notify/index.php'`。
     * 前端据此用「?_path=」方式调用 API，从而无需 rewrite。
     */
    private function injectApiBase($html)
    {
        $entry = $this->apiBaseForFrontend();
        $script = '<script>window.WN_API_ENTRY=' . json_encode($entry) . ';</script>';
        if (stripos($html, '<head>') !== false) {
            return preg_replace('#<head>#i', '<head>' . $script, $html, 1);
        }
        return $script . $html;
    }

    /**
     * 前端要用的 API 入口：无 rewrite 部署时返回「/wechat-notify/index.php」，
     * 让前端用 ?_path= 方式调用；否则返回空字符串（前端走干净路径）。
     */
    private function apiBaseForFrontend()
    {
        if ($this->injectApiBase !== null && $this->injectApiBase !== '') {
            return $this->injectApiBase;
        }
        $entry = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '';
        if ($entry !== '' && strpos($entry, '.php') !== false) {
            // 去掉 PATH_INFO 尾巴，只保留入口脚本本身
            $entry = preg_replace('#\.php/.*$#', '.php', $entry);
            return $entry;
        }
        return '';
    }

    private function dispatch($method, $path)
    {
        switch (true) {
            case $method === 'GET' && $path === '/health':
                $this->json(array('ok' => $this->store->ping()));
                return;
            case $method === 'POST' && $path === '/api/register/qr':
                $this->apiRegisterQR();
                return;
            case $method === 'GET' && $path === '/api/register/status':
                $this->apiRegisterStatus();
                return;
            case $method === 'POST' && $path === '/api/register/finish':
                $this->apiRegisterFinish();
                return;
            case $method === 'POST' && $path === '/api/login':
                $this->apiLogin();
                return;
            case $method === 'POST' && $path === '/api/logout':
                $this->apiLogout();
                return;
            case $method === 'GET' && $path === '/api/me':
                $this->apiMe();
                return;
            case $method === 'GET' && $path === '/api/account/ready':
                $this->apiReady();
                return;
            case $method === 'POST' && $path === '/api/send':
                $this->apiSend();
                return;
            default:
                $this->json(array('error' => 'not found'), 404);
        }
    }

    // ---------------- 处理器 ----------------

    private function apiRegisterQR()
    {
        $body = $this->body();
        $password = isset($body['password']) ? (string)$body['password'] : '';
        if (strlen($password) < Auth::MIN_PASS_LEN) {
            $this->json(array('error' => 'password too short, at least 6 characters'), 400);
            return;
        }
        $client = new IlClient(IlClient::DEFAULT_BASE_URL, '');
        $resp = $client->fetchLoginQRCode();
        if (!isset($resp['qrcode']) || !isset($resp['qrcode_img_content'])) {
            $this->json(array('error' => 'fetch qrcode: 返回数据缺少 qrcode'), 502);
            return;
        }
        $qrcode = $resp['qrcode'];
        $content = $resp['qrcode_img_content'];
        // 优先用服务端返回的图片内容；若它本身就是 base64，则直接透传
        $pngDataUrl = QR::pngDataUrl($content);
        $this->json(array(
            'qrcode'     => $qrcode,
            'qr_png'     => $this->dataUrlBase64($pngDataUrl),
            'expires_in' => 480,
        ));
    }

    private function apiRegisterStatus()
    {
        $qrcode = isset($_GET['qrcode']) ? trim((string)$_GET['qrcode']) : '';
        if ($qrcode === '') {
            $this->json(array('error' => 'missing qrcode'), 400);
            return;
        }
        $client = new IlClient(IlClient::DEFAULT_BASE_URL, '');
        try {
            $status = $client->pollLoginStatus($qrcode, 15);
        } catch (IlTimeoutException $e) {
            $this->json(array('status' => 'wait'));
            return;
        }
        $s = isset($status['status']) ? $status['status'] : '';
        switch ($s) {
            case 'confirmed':
                $uid = isset($status['ilink_user_id']) ? trim((string)$status['ilink_user_id']) : '';
                if ($uid === '' || empty($status['bot_token'])) {
                    $this->json(array('error' => 'login confirmed but token or user id missing, please rescan'), 502);
                    return;
                }
                $this->json(array(
                    'status'    => 'confirmed',
                    'user_id'   => $uid,
                    'base_url'  => $this->normalizeBaseURL(isset($status['baseurl']) ? $status['baseurl'] : ''),
                    'bot_token' => $status['bot_token'],
                ));
                return;
            default:
                $this->json(array('status' => $s === '' ? 'wait' : $s));
        }
    }

    private function apiRegisterFinish()
    {
        $body = $this->body();
        $password = isset($body['password']) ? (string)$body['password'] : '';
        $botToken = trim(isset($body['bot_token']) ? (string)$body['bot_token'] : '');
        $userId   = trim(isset($body['user_id']) ? (string)$body['user_id'] : '');
        $baseUrl  = $this->normalizeBaseURL(isset($body['base_url']) ? $body['base_url'] : '');

        if (strlen($password) < Auth::MIN_PASS_LEN) {
            $this->json(array('error' => 'password too short, at least 6 characters'), 400);
            return;
        }
        if ($userId === '' || $botToken === '') {
            $this->json(array('error' => 'missing user_id or bot_token, scan the QR code first'), 400);
            return;
        }

        // 落库前先验证 token 有效
        $client = new IlClient($baseUrl, $botToken);
        try {
            $resp = $client->getUpdates('', 10);
        } catch (Exception $e) {
            $this->json(array('error' => 'token verify failed: ' . $e->getMessage()), 400);
            return;
        }
        if ((isset($resp['ret']) && (int)$resp['ret'] !== 0) || (isset($resp['errcode']) && (int)$resp['errcode'] !== 0)) {
            $this->json(array('error' => 'token verify failed: server rejected the token'), 400);
            return;
        }

        $hash = Auth::hashPassword($password);
        $this->store->upsertUserBinding($userId, $hash, $botToken, $baseUrl);
        $token = $this->store->createSession($userId, Auth::SESSION_TTL);
        $this->setCookie($token);

        $this->json(array('user_id' => $userId, 'overwritten' => false));
    }

    private function apiLogin()
    {
        $body = $this->body();
        $userId   = trim(isset($body['user_id']) ? (string)$body['user_id'] : '');
        $password = isset($body['password']) ? (string)$body['password'] : '';

        $u = $this->store->getUser($userId);
        if ($u === null || !Auth::checkPassword($u['password_hash'], $password)) {
            $this->json(array('error' => '账号或密码错误'), 401);
            return;
        }
        // 重新登录刷新令牌：先清掉旧会话
        $this->store->deleteUserSessions($u['user_id']);
        $token = $this->store->createSession($u['user_id'], Auth::SESSION_TTL);
        $this->setCookie($token);
        $this->json(array('user_id' => $u['user_id']));
    }

    private function apiLogout()
    {
        $token = $this->cookieToken();
        if ($token !== null) {
            $this->store->deleteSession($token);
        }
        $this->clearCookie();
        $this->json(array('ok' => true));
    }

    private function apiMe()
    {
        $userId = $this->authUserID();
        if ($userId === null) {
            $this->json(array('error' => 'unauthorized'), 401);
            return;
        }
        $u = $this->store->getUser($userId);
        if ($u === null) {
            $this->json(array('error' => 'load user'), 500);
            return;
        }
        $peer = '';
        try {
            $peer = $this->resolveTarget($u)[0];
        } catch (Exception $e) {
            $peer = '';
        }
        $this->json(array(
            'user_id'      => $userId,
            'ready'        => $u['ready'],
            'peer'         => $peer,
            'poll_timeout' => (int)$this->pollTimeout,
        ));
    }

    private function apiReady()
    {
        $userId = $this->authUserID();
        if ($userId === null) {
            $this->json(array('error' => 'unauthorized'), 401);
            return;
        }
        // 单次请求最多等待时长：避免长时间占用 PHP 内置服务器的单 worker。
        // 前端会多次调用直到总时长耗尽，接口语义仍等价于「长等一次」。
        $wait = isset($_GET['wait']) && is_numeric($_GET['wait']) ? (float)$_GET['wait'] : 0;
        if ($wait <= 0 || $wait > 30) {
            $wait = min($this->pollTimeout, 12);
        }
        set_time_limit((int)$wait + 20);
        $deadline = microtime(true) + $wait;
        while (microtime(true) < $deadline) {
            $left = max(1, $deadline - microtime(true));
            try {
                $this->shortPoll($userId, $left);
            } catch (IlTimeoutException $e) {
                // 继续等
            } catch (Exception $e) {
                $this->json(array('error' => $e->getMessage()), 502);
                return;
            }
            $u = $this->store->getUser($userId);
            if ($u !== null) {
                try {
                    $to = $this->resolveTarget($u)[0];
                    $this->json(array('ready' => true, 'peer' => $to));
                    return;
                } catch (Exception $e) {
                    // 还没拿到第一条消息
                }
            }
        }
        $this->json(array('ready' => false));
    }

    private function apiSend()
    {
        $body = $this->body();
        $text = trim(isset($body['text']) ? (string)$body['text'] : '');
        $userId = $this->authUserID();

        if ($userId === null) {
            // 免登录直调：body 里带 user_id + password
            $uid = trim(isset($body['user_id']) ? (string)$body['user_id'] : '');
            $password = isset($body['password']) ? (string)$body['password'] : '';
            if ($uid === '' || $password === '') {
                $this->json(array('error' => 'unauthorized'), 401);
                return;
            }
            $u = $this->store->getUser($uid);
            if ($u === null || !Auth::checkPassword($u['password_hash'], $password)) {
                $this->json(array('error' => '账号或密码错误'), 401);
                return;
            }
            $userId = $u['user_id'];
        }

        if ($text === '') {
            // 仅读取
            $msgs = array();
            $pollErr = '';
            if ($this->sendTimeout > 0) {
                try {
                    $msgs = $this->shortPoll($userId, $this->sendTimeout);
                } catch (Exception $e) {
                    $pollErr = $e->getMessage();
                }
            }
            $to = '';
            $u = $this->store->getUser($userId);
            if ($u !== null) {
                try {
                    $to = $this->resolveTarget($u)[0];
                } catch (Exception $e) {
                    $to = '';
                }
            }
            $out = array('to' => $to, 'msgs' => $msgs, 'sent' => false, 'read_only' => true);
            if ($pollErr !== '') {
                $out['error'] = $pollErr;
            }
            $this->json($out);
            return;
        }

        $to = '';
        $msgs = array();
        $sent = false;
        $err = '';

        if ($this->sendTimeout > 0) {
            try {
                $msgs = $this->shortPoll($userId, $this->sendTimeout);
            } catch (Exception $e) {
                $this->json(array('to' => '', 'msgs' => array(), 'sent' => false, 'error' => $e->getMessage()));
                return;
            }
        }

        $u = $this->store->getUser($userId);
        if ($u === null) {
            $this->json(array('to' => '', 'msgs' => $msgs, 'sent' => false, 'error' => 'load user'));
            return;
        }
        try {
            list($to, $token) = $this->resolveTarget($u);
        } catch (Exception $e) {
            $this->json(array('to' => '', 'msgs' => $msgs, 'sent' => false, 'error' => $e->getMessage()));
            return;
        }

        $client = new IlClient($u['base_url'], $u['bot_token']);
        try {
            $client->sendText($to, $text, $token);
            $sent = true;
        } catch (Exception $e) {
            $err = 'send: ' . $e->getMessage();
        }

        $out = array('to' => $to, 'msgs' => $msgs, 'sent' => $sent);
        if (!$sent) {
            $out['error'] = $err;
        }
        $this->json($out);
    }

    // ---------------- 业务逻辑 ----------------

    /** 一次有界 getupdates，持久化 buf/token，返回积压消息 */
    private function shortPoll($userId, $timeout)
    {
        $msgs = array();
        $this->store->withLockedState($userId, function (&$state, $peers) use (&$msgs, $timeout) {
            $client = new IlClient($state['base_url'], $state['bot_token']);
            $resp = $client->getUpdates($state['get_updates_buf'], $timeout);
            if ((isset($resp['ret']) && (int)$resp['ret'] !== 0) || (isset($resp['errcode']) && (int)$resp['errcode'] !== 0)) {
                $ret  = isset($resp['ret']) ? $resp['ret'] : 0;
                $code = isset($resp['errcode']) ? $resp['errcode'] : 0;
                $msg  = isset($resp['errmsg']) ? $resp['errmsg'] : '';
                throw new RuntimeException("poll: getupdates ret=$ret errcode=$code errmsg=$msg");
            }
            if (isset($resp['get_updates_buf']) && (string)$resp['get_updates_buf'] !== '') {
                $state['get_updates_buf'] = (string)$resp['get_updates_buf'];
            }
            foreach ((isset($resp['msgs']) && is_array($resp['msgs']) ? $resp['msgs'] : array()) as $m) {
                $from = trim(isset($m['from_user_id']) ? (string)$m['from_user_id'] : '');
                if ($from === '') {
                    continue;
                }
                $seenAt = isset($m['create_time_ms']) ? (int)$m['create_time_ms'] / 1000 : time();
                $peers->upsert($from, isset($m['context_token']) ? $m['context_token'] : '', $seenAt);
                $text = IlClient::extractText($m);
                if ($text === '') {
                    $text = IlClient::summarize($m);
                }
                $out = array('from' => $from, 'text' => $text);
                if (isset($m['create_time_ms'])) {
                    $out['time_ms'] = (int)$m['create_time_ms'];
                }
                $msgs[] = $out;
            }
        });
        return $msgs;
    }

    private function resolveTarget(array $u)
    {
        $peers = new Peers($this->toState($u));
        $list = $peers->listAll();
        $to = '';
        if (count($list) === 1) {
            $to = $list[0];
        } else {
            $cur = $peers->current();
            if ($cur !== null) {
                $to = $cur[0];
            }
        }
        if ($to === '') {
            throw new RuntimeException('no peer yet, send a WeChat message first');
        }
        $token = $peers->token($to);
        if ($token === null || trim($token) === '') {
            throw new RuntimeException('peer has no context token yet');
        }
        return array($to, $token);
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

    // ---------------- HTTP 工具 ----------------

    private function body()
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return array();
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : array();
    }

    private function json($data, $code = 200)
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function cors()
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
    }

    private function setCookie($token)
    {
        setcookie(self::COOKIE_NAME, $token, time() + Auth::SESSION_TTL, '/', '', false, true);
    }

    private function clearCookie()
    {
        setcookie(self::COOKIE_NAME, '', time() - 3600, '/', '', false, true);
    }

    private function cookieToken()
    {
        if (isset($_COOKIE[self::COOKIE_NAME]) && $_COOKIE[self::COOKIE_NAME] !== '') {
            return $_COOKIE[self::COOKIE_NAME];
        }
        return null;
    }

    private function authUserID()
    {
        $token = $this->cookieToken();
        if ($token === null) {
            return null;
        }
        $uid = $this->store->getSessionUser($token);
        return $uid;
    }

    private function normalizeBaseURL($baseUrl)
    {
        $baseUrl = trim((string)$baseUrl);
        if ($baseUrl === '') {
            return IlClient::DEFAULT_BASE_URL;
        }
        return rtrim($baseUrl, '/');
    }

    private function dataUrlBase64($dataUrl)
    {
        // QR::pngDataUrl 已经返回 "data:image/png;base64,xxx"
        $pos = strpos($dataUrl, ',');
        if ($pos !== false) {
            return substr($dataUrl, $pos + 1);
        }
        return $dataUrl;
    }
}

// ---------- 入口 ----------
// 仅当本文件被 php -S 直接作为路由脚本执行时才分发请求。
// （root index.php 或 Apache/nginx 走 index.php 时不会触发这里，避免重复 handle）
if (PHP_SAPI === 'cli-server') {
    $self = isset($_SERVER['SCRIPT_FILENAME']) ? realpath($_SERVER['SCRIPT_FILENAME']) : '';
    if ($self !== false && $self === __FILE__) {
        require_once __DIR__ . '/bootstrap.php';
        $cfg   = wn_config();
        $web   = getenv('WN_WEB') !== false ? getenv('WN_WEB') : dirname(__DIR__) . '/public';
        $base  = getenv('WN_BASE') !== false ? getenv('WN_BASE') : '';
        $store = new Store($cfg['db_path']);
        $app   = new CloudApp($store, $cfg['poll_timeout'], $cfg['send_timeout'], $web, $cfg['db_path'], $base);
        $app->handle($_SERVER['REQUEST_URI']);
    }
}
