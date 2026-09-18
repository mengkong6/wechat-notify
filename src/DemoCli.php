<?php
/**
 * 交互式 CLI（对应 Go 的 cmd/demo/main.go）
 * 扫码登录 + 长轮询聊天 + 终端回复。
 *
 * 用法：
 *   php notify.php login [-state session.json] [-qr login-qr.png]
 *   php notify.php chat  [-state session.json]
 *   php notify.php       [-state session.json]   # 自动判断登录或聊天
 */

require_once __DIR__ . '/bootstrap.php';

class DemoCli
{
    const DEFAULT_STATE = 'session.json';
    const DEFAULT_QR    = 'login-qr.png';

    private $statePath;
    private $session;
    private $client;
    private $peers;

    public function run($argv)
    {
        list($opts, $rest) = wn_parse_args($argv);
        $cmd = isset($rest[0]) ? $rest[0] : '';

        $this->statePath = wn_opt($opts, 'state', self::DEFAULT_STATE);
        if ($this->statePath !== self::DEFAULT_STATE && strpos($this->statePath, '/') === false && strpos($this->statePath, '\\') === false) {
            // 相对路径，保持原样
        }
        $this->session = new Session($this->statePath);

        switch ($cmd) {
            case 'login':
                return $this->login($opts);
            case 'chat':
                return $this->chat();
            case 'help':
            case '-h':
            case '--help':
            case '':
                // 空命令：自动判断
                break;
            default:
                // 兼容直接给文本？demo 里无此用法，打印帮助
                $this->usage();
                return 1;
        }

        // 自动模式
        if ($this->session->hasUsableToken()) {
            wn_log('检测到可用 session，进入聊天模式', $this->statePath);
            return $this->chat();
        }
        wn_log('未检测到可用 session，进入登录模式', $this->statePath);
        return $this->login($opts);
    }

    // ---------------- 登录 ----------------

    private function login($opts)
    {
        $baseUrl = wn_opt($opts, 'base-url', IlClient::DEFAULT_BASE_URL);
        $qrPath  = wn_opt($opts, 'qr', self::DEFAULT_QR);

        $client = new IlClient($baseUrl, '');
        $resp = $client->fetchLoginQRCode();
        if (!isset($resp['qrcode']) || !isset($resp['qrcode_img_content'])) {
            wn_log('获取二维码失败');
            return 1;
        }
        $qrcode = $resp['qrcode'];
        $content = $resp['qrcode_img_content'];

        $this->saveQRImage($qrPath, $content);
        wn_log('二维码已生成，请用微信扫码确认', $qrPath);
        // 终端兜底展示
        if (function_exists('stream_isatty') && stream_isatty(STDOUT)) {
            fwrite(STDOUT, QR::ansi($content) . "\n");
        }

        $status = $this->waitForLogin($client, $qrcode);
        if ($status === null) {
            return 1;
        }

        $this->session->set('bot_token', $status['bot_token']);
        $this->session->set('bot_id', isset($status['ilink_bot_id']) ? $status['ilink_bot_id'] : '');
        $this->session->set('user_id', isset($status['ilink_user_id']) ? $status['ilink_user_id'] : '');
        $this->session->set('base_url', $this->normalizeBaseURL(isset($status['baseurl']) ? $status['baseurl'] : ''));
        $this->session->save();

        wn_log('登录成功', 'bot_id=' . $this->session->get('bot_id', '') . ' user_id=' . $this->session->get('user_id', ''));
        wn_log('session 已保存', $this->statePath);
        return $this->chat();
    }

    private function waitForLogin($client, $qrcode)
    {
        $deadline = time() + 8 * 60;
        wn_log('等待扫码确认…（8 分钟内有效）');
        while (time() < $deadline) {
            try {
                $status = $client->pollLoginStatus($qrcode, 40);
            } catch (IlTimeoutException $e) {
                continue;
            }
            $s = isset($status['status']) ? $status['status'] : '';
            switch ($s) {
                case 'scaned':
                    wn_log('二维码已扫码，请在手机上确认登录');
                    break;
                case 'confirmed':
                    if (empty($status['bot_token'])) {
                        wn_log('登录确认但缺少 token');
                        return null;
                    }
                    return $status;
                case 'expired':
                    wn_log('二维码已过期，请重新运行登录');
                    return null;
                case 'wait':
                default:
                    break;
            }
            sleep(1);
        }
        wn_log('登录超时');
        return null;
    }

    private function saveQRImage($path, $content)
    {
        $abs = $this->absolutePath($path);
        $dir = dirname($abs);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // 内容可能是 base64 也可能是二维码原始串
        $png = QR::pngDataUrl($content);
        $pos = strpos($png, ',');
        $bin = $pos !== false ? base64_decode(substr($png, $pos + 1)) : '';
        if ($bin === '') {
            // 兜底：直接写字符串
            file_put_contents($abs, $content);
            return;
        }
        file_put_contents($abs, $bin);
    }

    // ---------------- 聊天 ----------------

    private function chat()
    {
        if (!$this->session->hasUsableToken()) {
            wn_log('缺少 bot_token，请先运行 login');
            return 1;
        }

        $this->client = new IlClient($this->session->get('base_url'), $this->session->get('bot_token'));
        $this->peers  = new Peers($this->session->all());

        wn_log('聊天模式已启动', 'bot_id=' . $this->session->get('bot_id', ''));
        $list = $this->peers->listAll();
        if (count($list) > 0) {
            wn_log('已从 session 恢复用户', (string)count($list));
        }
        $this->chatHelp();

        $buf = $this->session->get('get_updates_buf', '');
        $timeout = IlClient::DEFAULT_LONG_POLL_TIMEOUT;
        $running = true;

        // 长轮询在后台，主循环用 stream_select 同时监听 stdin 与轮询间隔
        $this->printPrompt();
        $nextPoll = microtime(true);

        while ($running) {
            // 非阻塞读 stdin
            $read = array(STDIN);
            $write = null;
            $except = null;
            $sec = 0;
            $usec = 200000; // 200ms 粒度
            $n = @stream_select($read, $write, $except, $sec, $usec);

            // 是否该做一次长轮询
            if (microtime(true) >= $nextPoll) {
                $nextPoll = $this->pollOnce($buf, $timeout);
            }

            if ($n === false) {
                break;
            }
            if ($n > 0) {
                $line = fgets(STDIN);
                if ($line === false) {
                    break; // EOF
                }
                $line = trim($line);
                if ($line === '') {
                    $this->printPrompt();
                    continue;
                }
                $r = $this->handleLine($line);
                if ($r === false) {
                    $running = false;
                }
                $this->printPrompt();
            }
        }
        return 0;
    }

    /**
     * 执行一次长轮询，返回下次轮询时间。
     */
    private function pollOnce(&$buf, &$timeout)
    {
        $resp = $this->client->getUpdates($buf, $timeout);
        if (isset($resp['longpolling_timeout_ms']) && (int)$resp['longpolling_timeout_ms'] > 0) {
            $timeout = (int)$resp['longpolling_timeout_ms'] / 1000;
        }
        if ((isset($resp['ret']) && (int)$resp['ret'] !== 0) || (isset($resp['errcode']) && (int)$resp['errcode'] !== 0)) {
            $ret  = isset($resp['ret']) ? $resp['ret'] : 0;
            $code = isset($resp['errcode']) ? $resp['errcode'] : 0;
            $msg  = isset($resp['errmsg']) ? $resp['errmsg'] : '';
            wn_log('getupdates 错误', "ret=$ret errcode=$code errmsg=$msg");
            if ((int)$code === -14) {
                wn_log('bot_token 已过期，请重新扫码登录');
            }
            return microtime(true) + 2;
        }

        $changed = false;
        if (isset($resp['get_updates_buf']) && (string)$resp['get_updates_buf'] !== '' && (string)$resp['get_updates_buf'] !== (string)$buf) {
            $buf = (string)$resp['get_updates_buf'];
            $this->session->set('get_updates_buf', $buf);
            $changed = true;
        }

        foreach ((isset($resp['msgs']) && is_array($resp['msgs']) ? $resp['msgs'] : array()) as $m) {
            $from = trim(isset($m['from_user_id']) ? (string)$m['from_user_id'] : '');
            if ($from === '') {
                continue;
            }
            $seenAt = isset($m['create_time_ms']) ? (int)$m['create_time_ms'] / 1000 : time();
            $before = $this->peers->token($from);
            $beforeCur = $this->peers->current();
            $this->peers->upsert($from, isset($m['context_token']) ? $m['context_token'] : '', $seenAt);
            $after = $this->peers->token($from);
            $afterCur = $this->peers->current();
            if ($before !== $after || $beforeCur !== $afterCur) {
                $changed = true;
            }
            $text = IlClient::extractText($m);
            if ($text === '') {
                $text = IlClient::summarize($m);
            }
            wn_log('收到消息', "from=$from text=$text");
            $this->printPrompt();
        }

        if ($changed) {
            $this->persist();
        }
        return microtime(true); // 长轮询已阻塞了 timeout 秒，立即下一轮
    }

    private function handleLine($line)
    {
        if ($line[0] === '/') {
            return $this->handleCommand($line);
        }
        // 普通文本发给当前 peer
        $cur = $this->peers->current();
        if ($cur === null) {
            wn_log('当前没有选中用户，等待对方先发消息或用 /users 查看');
            return true;
        }
        list($peer, $token) = $cur;
        if ($token === '') {
            wn_log('当前用户还没有 context_token，无法回复');
            return true;
        }
        try {
            $this->client->sendText($peer, $line, $token);
            wn_log('已发送', "to=$peer");
        } catch (Exception $e) {
            wn_log('发送失败', $e->getMessage());
        }
        return true;
    }

    private function handleCommand($line)
    {
        $parts = preg_split('/\s+/', $line);
        $cmd = $parts[0];
        switch ($cmd) {
            case '/help':
                $this->chatHelp();
                break;
            case '/users':
                $list = $this->peers->listAll();
                if (count($list) === 0) {
                    wn_log('当前还没有活跃用户');
                } else {
                    wn_log('已知用户列表', (string)count($list));
                    foreach ($list as $p) {
                        wn_log(' 用户', $p . '  最近来信=' . $this->peers->lastSeenAt($p));
                    }
                }
                break;
            case '/who':
                $cur = $this->peers->current();
                if ($cur !== null) {
                    wn_log('当前用户', $cur[0]);
                } else {
                    wn_log('当前还没有选中用户');
                }
                break;
            case '/use':
                if (!isset($parts[1])) {
                    wn_log('用法: /use <peer>');
                    break;
                }
                try {
                    $this->peers->setCurrent($parts[1]);
                    $this->persist();
                    wn_log('已切换当前用户', $parts[1]);
                } catch (Exception $e) {
                    wn_log($e->getMessage());
                }
                break;
            case '/send':
                if (!isset($parts[2])) {
                    wn_log('用法: /send <peer> <message>');
                    break;
                }
                $peer = $parts[1];
                $msg = preg_replace('/^\/send\s+' . preg_quote($peer, '/') . '\s+/', '', $line);
                $this->sendToPeer($peer, $msg);
                break;
            case '/quit':
            case '/exit':
                return false;
            default:
                wn_log('未知命令', $cmd . '（/help 查看帮助）');
        }
        return true;
    }

    private function sendToPeer($peer, $text)
    {
        $token = $this->peers->token($peer);
        if ($token === null || trim($token) === '') {
            wn_log('该用户还没有缓存 context_token');
            return;
        }
        try {
            $this->peers->setCurrent($peer);
            $this->persist();
            $this->client->sendText($peer, $text, $token);
            wn_log('已发送', "to=$peer");
        } catch (Exception $e) {
            wn_log('发送失败', $e->getMessage());
        }
    }

    private function persist()
    {
        $this->session->applyPeers($this->peers);
        $this->session->save();
    }

    private function printPrompt()
    {
        $cur = $this->peers ? $this->peers->current() : null;
        if ($cur !== null) {
            fwrite(STDOUT, '> [' . $cur[0] . '] ');
        } else {
            fwrite(STDOUT, '> ');
        }
    }

    private function chatHelp()
    {
        wn_log('聊天命令');
        wn_log('  /help            显示帮助');
        wn_log('  /users           列出已知联系人');
        wn_log('  /who             显示当前联系人');
        wn_log('  /use <peer>      切换当前联系人');
        wn_log('  /send <peer> <m> 向指定联系人发消息');
        wn_log('  /quit            退出');
        wn_log('提示：直接输入文本会发给当前选中的 peer');
    }

    private function usage()
    {
        wn_log('用法');
        wn_log('  php notify.php login [-state session.json] [-qr login-qr.png]');
        wn_log('  php notify.php chat  [-state session.json]');
        wn_log('  php notify.php       [-state session.json]');
    }

    private function normalizeBaseURL($baseUrl)
    {
        $baseUrl = trim((string)$baseUrl);
        if ($baseUrl === '') {
            return IlClient::DEFAULT_BASE_URL;
        }
        return rtrim($baseUrl, '/');
    }

    private function absolutePath($path)
    {
        if ($path[0] === '/' || (isset($path[1]) && $path[1] === ':')) {
            return $path;
        }
        return getcwd() . DIRECTORY_SEPARATOR . $path;
    }
}
