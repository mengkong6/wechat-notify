<?php
/**
 * 单次发件（对应 Go 的 cmd/send/main.go）
 * 发前短轮询刷新 buf/token，然后给默认单 peer 发一条文本，stdout 只打一行 JSON。
 *
 * 用法：
 *   php send.php [-state session.json] [-poll 2s] <text>
 */

require_once __DIR__ . '/bootstrap.php';

class SendCli
{
    public function run($argv)
    {
        list($opts, $rest) = wn_parse_args($argv);

        $statePath = wn_opt($opts, 'state', 'session.json');
        $pollStr   = wn_opt($opts, 'poll', '2s');
        $poll = $this->parseDuration($pollStr);

        $text = trim(implode(' ', $rest));
        if ($text === '') {
            $this->emit(array('msgs' => array(), 'sent' => false, 'error' => 'usage: send [-state session.json] [-poll 2s] <text>'));
            return 0;
        }

        $this->emit($this->doSend($statePath, $poll, $text));
        return 0;
    }

    private function doSend($statePath, $poll, $text)
    {
        $session = new Session($statePath);
        if (!$session->hasUsableToken()) {
            return array('msgs' => array(), 'sent' => false, 'error' => 'missing bot token, run login first');
        }

        $client = new IlClient($session->get('base_url'), $session->get('bot_token'));
        $peers  = new Peers($session->all());

        $buf = $session->get('get_updates_buf', '');
        $msgs = array();

        try {
            $resp = $client->getUpdates($buf, $poll);
        } catch (Exception $e) {
            return array('msgs' => array(), 'sent' => false, 'error' => 'poll: ' . $e->getMessage());
        }

        if ((isset($resp['ret']) && (int)$resp['ret'] !== 0) || (isset($resp['errcode']) && (int)$resp['errcode'] !== 0)) {
            $ret  = isset($resp['ret']) ? $resp['ret'] : 0;
            $code = isset($resp['errcode']) ? $resp['errcode'] : 0;
            $msg  = isset($resp['errmsg']) ? $resp['errmsg'] : '';
            return array('msgs' => array(), 'sent' => false, 'error' => "poll: getupdates ret=$ret errcode=$code errmsg=$msg");
        }

        foreach ((isset($resp['msgs']) && is_array($resp['msgs']) ? $resp['msgs'] : array()) as $m) {
            $from = trim(isset($m['from_user_id']) ? (string)$m['from_user_id'] : '');
            if ($from === '') {
                continue;
            }
            $seenAt = isset($m['create_time_ms']) ? (int)$m['create_time_ms'] / 1000 : time();
            $peers->upsert($from, isset($m['context_token']) ? $m['context_token'] : '', $seenAt);
            $textOut = IlClient::extractText($m);
            if ($textOut === '') {
                $textOut = IlClient::summarize($m);
            }
            $out = array('from' => $from, 'text' => $textOut);
            if (isset($m['create_time_ms'])) {
                $out['time_ms'] = (int)$m['create_time_ms'];
            }
            $msgs[] = $out;
        }

        if (isset($resp['get_updates_buf']) && (string)$resp['get_updates_buf'] !== '' && (string)$resp['get_updates_buf'] !== (string)$buf) {
            $session->set('get_updates_buf', (string)$resp['get_updates_buf']);
        }

        try {
            $session->applyPeers($peers);
            $session->save();
        } catch (Exception $e) {
            return array('msgs' => $msgs, 'sent' => false, 'error' => 'persist: ' . $e->getMessage());
        }

        // 单 peer 默认：只有一个已知用户就用它，否则用当前选中的 peer。
        $to = '';
        $list = $peers->listAll();
        if (count($list) === 1) {
            $to = $list[0];
        } else {
            $cur = $peers->current();
            if ($cur !== null) {
                $to = $cur[0];
            }
        }
        if ($to === '') {
            return array('msgs' => $msgs, 'sent' => false, 'error' => 'no peer yet, wait for an inbound message');
        }

        $token = $peers->token($to);
        if ($token === null || trim($token) === '') {
            return array('msgs' => $msgs, 'to' => $to, 'sent' => false, 'error' => 'peer has no context token yet');
        }

        try {
            $client->sendText($to, $text, $token);
        } catch (Exception $e) {
            return array('msgs' => $msgs, 'to' => $to, 'sent' => false, 'error' => 'send: ' . $e->getMessage());
        }
        return array('msgs' => $msgs, 'to' => $to, 'sent' => true);
    }

    private function emit($r)
    {
        if (!isset($r['msgs']) || !is_array($r['msgs'])) {
            $r['msgs'] = array();
        }
        echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    }

    private function parseDuration($s)
    {
        $s = trim((string)$s);
        if ($s === '' || $s === '0') {
            return 0;
        }
        if (is_numeric($s)) {
            return max(0, (float)$s);
        }
        if (preg_match('/^(\d+(?:\.\d+)?)(ms|s|m)?$/', $s, $m)) {
            $v = (float)$m[1];
            $unit = isset($m[2]) ? $m[2] : 's';
            switch ($unit) {
                case 'ms': return $v / 1000;
                case 'm':  return $v * 60;
                default:   return $v;
            }
        }
        return 2;
    }
}
