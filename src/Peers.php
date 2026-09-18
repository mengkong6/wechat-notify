<?php
/**
 * 联系人 / 回信上下文注册表（对应 Go 的 ChatRegistry）
 * 记录每个 peer 的 context_token 与最近来信时间。
 */

class Peers
{
    private $current = '';
    private $peers = array(); // peer => array('context_token'=>.., 'last_seen_at'=>..)

    public function __construct(array $state = array())
    {
        if (isset($state['peers']) && is_array($state['peers'])) {
            foreach ($state['peers'] as $peer => $v) {
                $peer = trim((string)$peer);
                if ($peer === '') {
                    continue;
                }
                if (is_array($v)) {
                    $token = trim(isset($v['context_token']) ? (string)$v['context_token'] : '');
                    $seen  = isset($v['last_seen_at']) ? (string)$v['last_seen_at'] : '';
                } else {
                    $token = trim((string)$v);
                    $seen  = '';
                }
                if ($token === '') {
                    continue;
                }
                $this->peers[$peer] = array('context_token' => $token, 'last_seen_at' => $seen);
            }
        }
        if (isset($state['current_peer'])) {
            $c = trim((string)$state['current_peer']);
            if ($c !== '' && isset($this->peers[$c])) {
                $this->current = $c;
            }
        }
    }

    public function upsert($peer, $contextToken, $seenAt = null)
    {
        $peer = trim((string)$peer);
        if ($peer === '') {
            return;
        }
        if (!isset($this->peers[$peer])) {
            $this->peers[$peer] = array('context_token' => '', 'last_seen_at' => '');
        }
        if (trim((string)$contextToken) !== '') {
            $this->peers[$peer]['context_token'] = trim((string)$contextToken);
        }
        if ($seenAt !== null) {
            $ts = is_numeric($seenAt) ? (int)$seenAt : strtotime((string)$seenAt);
            if ($ts > 0) {
                $this->peers[$peer]['last_seen_at'] = date('c', $ts);
            } elseif ((string)$seenAt !== '') {
                $this->peers[$peer]['last_seen_at'] = (string)$seenAt;
            }
        }
        if ($this->current === '') {
            $this->current = $peer;
        }
    }

    public function setCurrent($peer)
    {
        $peer = trim((string)$peer);
        if (!isset($this->peers[$peer])) {
            throw new RuntimeException('unknown peer: ' . $peer);
        }
        $this->current = $peer;
    }

    public function current()
    {
        if ($this->current === '' || !isset($this->peers[$this->current])) {
            return null;
        }
        return array($this->current, $this->peers[$this->current]['context_token']);
    }

    public function token($peer)
    {
        $peer = trim((string)$peer);
        if (!isset($this->peers[$peer])) {
            return null;
        }
        return $this->peers[$peer]['context_token'];
    }

    public function lastSeenAt($peer)
    {
        $peer = trim((string)$peer);
        return isset($this->peers[$peer]) ? $this->peers[$peer]['last_seen_at'] : '';
    }

    /** 按最近来信时间倒序返回 peer 列表 */
    public function listAll()
    {
        $arr = $this->peers;
        uasort($arr, function ($a, $b) {
            $la = isset($a['last_seen_at']) ? (string)$a['last_seen_at'] : '';
            $lb = isset($b['last_seen_at']) ? (string)$b['last_seen_at'] : '';
            if ($la === $lb) {
                return 0;
            }
            return $la > $lb ? -1 : 1;
        });
        return array_keys($arr);
    }

    public function count()
    {
        return count($this->peers);
    }

    /** 将内存状态写回会话数组 */
    public function applyToState(array &$state)
    {
        $state['current_peer'] = $this->current;
        $state['peers'] = $this->peers;
    }
}
