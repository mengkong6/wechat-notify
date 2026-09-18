<?php
/**
 * 会话状态文件（对应 Go 的 internal/ilink/store.go）
 * 一个 session.json 对应一个扫码绑定的号，多号用不同文件。
 */

class Session
{
    private $path;
    private $data;

    public function __construct($path)
    {
        $this->path = $path;
        $this->data = $this->load();
    }

    public function get($key, $default = null)
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function set($key, $value)
    {
        $this->data[$key] = $value;
    }

    public function all()
    {
        return $this->data;
    }

    public function hasUsableToken()
    {
        return trim((string)$this->get('bot_token', '')) !== '';
    }

    public function getPath()
    {
        return $this->path;
    }

    /** 将 Peers 对象的状态回写到会话数据 */
    public function applyPeers(Peers $peers)
    {
        $peers->applyToState($this->data);
    }

    /** 原子写入会话文件 */
    public function save()
    {
        $this->data['base_url'] = rtrim((string)$this->get('base_url', IlClient::DEFAULT_BASE_URL), '/');
        if ($this->data['base_url'] === '') {
            $this->data['base_url'] = IlClient::DEFAULT_BASE_URL;
        }
        if (!isset($this->data['peers']) || !is_array($this->data['peers'])) {
            $this->data['peers'] = array();
        }
        $this->data['saved_at'] = date('c');

        $dir = dirname($this->path);
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $tmp  = $this->path . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('无法写入 session: ' . $this->path);
        }
        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new RuntimeException('无法保存 session: ' . $this->path);
        }
    }

    private function load()
    {
        $defaults = $this->defaults();
        if (!is_file($this->path)) {
            return $defaults;
        }
        $raw  = file_get_contents($this->path);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $defaults;
        }
        // 兼容旧版 peers 为 map[string]string 的格式
        if (isset($data['peers']) && is_array($data['peers'])) {
            foreach ($data['peers'] as $k => $v) {
                if (is_string($v)) {
                    $data['peers'][$k] = array('context_token' => $v);
                }
            }
        }
        return array_merge($defaults, $data);
    }

    private function defaults()
    {
        return array(
            'bot_token'      => '',
            'bot_id'         => '',
            'user_id'        => '',
            'base_url'       => IlClient::DEFAULT_BASE_URL,
            'get_updates_buf' => '',
            'current_peer'   => '',
            'peers'          => array(),
            'saved_at'       => '',
        );
    }
}
