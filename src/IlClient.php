<?php
/**
 * 微信 ClawBot（iLink）HTTP API 客户端 — wechat-notify
 *
 * 核心：没有 WebSocket/回调，全靠 HTTP 长轮询。
 *  - GET  /ilink/bot/get_bot_qrcode?bot_type=3   获取登录二维码
 *  - GET  /ilink/bot/get_qrcode_status?qrcode=   轮询扫码状态
 *  - POST /ilink/bot/getupdates                  长轮询收消息
 *  - POST /ilink/bot/sendmessage                 发消息（必须带对方 context_token）
 */

class IlTimeoutException extends RuntimeException
{
}

class IlClient
{
    const DEFAULT_BASE_URL         = 'https://ilinkai.weixin.qq.com';
    const DEFAULT_BOT_TYPE         = '3';
    const DEFAULT_LONG_POLL_TIMEOUT = 40;
    const CHANNEL_VERSION          = '1.0.2';

    private $baseUrl;
    private $token;

    public function __construct($baseUrl = '', $token = '')
    {
        $this->baseUrl = rtrim($baseUrl !== '' ? $baseUrl : self::DEFAULT_BASE_URL, '/');
        $this->token = trim((string)$token);
    }

    public function getToken()
    {
        return $this->token;
    }

    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    /**
     * 获取登录二维码
     */
    public function fetchLoginQRCode($botType = self::DEFAULT_BOT_TYPE, $timeout = 30)
    {
        $url = $this->baseUrl . '/ilink/bot/get_bot_qrcode?bot_type=' . urlencode((string)$botType);
        $raw = $this->request('GET', $url, null, array(), $timeout);
        $out = json_decode($raw, true);
        if (!is_array($out)) {
            throw new RuntimeException('get_bot_qrcode 返回无效 JSON: ' . substr($raw, 0, 200));
        }
        return $out;
    }

    /**
     * 轮询扫码状态
     */
    public function pollLoginStatus($qrcode, $timeout = self::DEFAULT_LONG_POLL_TIMEOUT)
    {
        $url = $this->baseUrl . '/ilink/bot/get_qrcode_status?qrcode=' . urlencode((string)$qrcode);
        $raw = $this->request('GET', $url, null, array('iLink-App-ClientVersion: 1'), $timeout);
        $out = json_decode($raw, true);
        if (!is_array($out)) {
            throw new RuntimeException('get_qrcode_status 返回无效 JSON');
        }
        return $out;
    }

    /**
     * 长轮询收消息。超时（IlTimeoutException）当作「无新消息」处理。
     */
    public function getUpdates($buf, $timeout = self::DEFAULT_LONG_POLL_TIMEOUT, $channelVersion = self::CHANNEL_VERSION)
    {
        if ($timeout <= 0) {
            $timeout = self::DEFAULT_LONG_POLL_TIMEOUT;
        }
        $body = array(
            'get_updates_buf' => (string)$buf,
            'base_info'       => array('channel_version' => (string)$channelVersion),
        );
        try {
            $out = $this->postJSON('/ilink/bot/getupdates', $body, $timeout);
        } catch (IlTimeoutException $e) {
            return array('ret' => 0, 'msgs' => array(), 'get_updates_buf' => (string)$buf);
        }
        if (!is_array($out)) {
            $out = array('ret' => 0, 'msgs' => array());
        }
        return $out;
    }

    /**
     * 发送一条纯文本消息
     */
    public function sendText($toUserID, $text, $contextToken, $timeout = 15)
    {
        $body = array(
            'msg' => array(
                'from_user_id'  => '',
                'to_user_id'    => (string)$toUserID,
                'client_id'     => $this->generateClientID(),
                'message_type'  => 2,
                'message_state' => 2,
                'context_token' => (string)$contextToken,
                'item_list'     => array(
                    array('type' => 1, 'text_item' => array('text' => (string)$text)),
                ),
            ),
        );
        $out = $this->postJSON('/ilink/bot/sendmessage', $body, $timeout);
        if ((isset($out['ret']) && (int)$out['ret'] != 0) || (isset($out['errcode']) && (int)$out['errcode'] != 0)) {
            $ret  = isset($out['ret']) ? $out['ret'] : 0;
            $code = isset($out['errcode']) ? $out['errcode'] : 0;
            $msg  = isset($out['errmsg']) ? $out['errmsg'] : '';
            throw new RuntimeException("sendmessage ret=$ret errcode=$code errmsg=$msg");
        }
        return $out;
    }

    // ---------------- 底层 ----------------

    private function postJSON($path, $payload, $timeout = 35)
    {
        $bodyBytes = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $raw = $this->request('POST', $this->baseUrl . $path, $bodyBytes, $this->buildHeaders($bodyBytes), $timeout);
        if ($raw === '') {
            return array();
        }
        $out = json_decode($raw, true);
        if (!is_array($out)) {
            throw new RuntimeException("$path 返回无效 JSON: " . substr($raw, 0, 200));
        }
        return $out;
    }

    private function request($method, $url, $body, $headers, $timeout)
    {
        $ch = curl_init();
        $opts = array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_TIMEOUT        => max(1, (int)$timeout),
            CURLOPT_CONNECTTIMEOUT => 10,
        );
        // Windows 常缺 CA 证书；可用 WN_INSECURE=1 跳过校验（不推荐，仅自用）
        if (getenv('WN_INSECURE') === '1') {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        } else {
            $opts[CURLOPT_SSL_VERIFYPEER] = true;
            $opts[CURLOPT_SSL_VERIFYHOST] = 2;
        }
        curl_setopt_array($ch, $opts);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $raw    = curl_exec($ch);
        $err    = curl_error($ch);
        $errNo  = curl_errno($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            if ($errNo === CURLE_OPERATION_TIMEDOUT) {
                throw new IlTimeoutException('curl timeout: ' . $err);
            }
            throw new RuntimeException('curl error: ' . $err);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("http $status: " . trim($raw));
        }
        return $raw;
    }

    private function buildHeaders($body)
    {
        $headers = array(
            'Content-Type: application/json',
            'AuthorizationType: ilink_bot_token',
            'Content-Length: ' . strlen($body),
            'X-WECHAT-UIN: ' . $this->randomWechatUIN(),
        );
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        return $headers;
    }

    /**
     * 对应 Go 的「随机 uint32 → 十进制字符串 → base64」逻辑。
     */
    private function randomWechatUIN()
    {
        $number = random_int(0, 4294967295);
        return base64_encode((string)$number);
    }

    private function generateClientID()
    {
        return 'wechat-' . strval((int)(microtime(true) * 1000)) . '-' . bin2hex(random_bytes(4));
    }

    // ---------------- 工具 ----------------

    /** 取第一条文本/语音转文字内容 */
    public static function extractText(array $msg)
    {
        $items = isset($msg['item_list']) && is_array($msg['item_list']) ? $msg['item_list'] : array();
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $type = isset($it['type']) ? (int)$it['type'] : 0;
            if ($type === 1 && isset($it['text_item']['text']) && trim($it['text_item']['text']) !== '') {
                return trim($it['text_item']['text']);
            }
            if ($type === 3 && isset($it['voice_item']['text']) && trim($it['voice_item']['text']) !== '') {
                return trim($it['voice_item']['text']);
            }
        }
        return '';
    }

    /** 非文本消息的可读描述 */
    public static function summarize(array $msg)
    {
        $items = isset($msg['item_list']) && is_array($msg['item_list']) ? $msg['item_list'] : array();
        if (count($items) === 0) {
            return '[empty message]';
        }
        $kinds = array();
        foreach ($items as $it) {
            $type = is_array($it) && isset($it['type']) ? (int)$it['type'] : 0;
            switch ($type) {
                case 1:  $kinds[] = 'text';  break;
                case 2:  $kinds[] = 'image'; break;
                case 3:  $kinds[] = 'voice'; break;
                case 4:  $kinds[] = 'file';  break;
                case 5:  $kinds[] = 'video'; break;
                default: $kinds[] = 'type-' . $type;
            }
        }
        return '[' . implode(', ', $kinds) . ']';
    }
}
