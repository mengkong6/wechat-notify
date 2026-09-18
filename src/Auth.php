<?php
/**
 * 密码哈希与会话令牌（对应 Go 的 internal/cloud/auth.go）
 * 使用 PHP 内置 bcrypt，与 Go 的 golang.org/x/crypto/bcrypt 兼容。
 */

class Auth
{
    const MIN_PASS_LEN = 6;
    const SESSION_TTL  = 2592000; // 30 天（秒）

    public static function hashPassword($password)
    {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        if ($hash === false) {
            throw new RuntimeException('bcrypt 哈希失败');
        }
        return $hash;
    }

    public static function checkPassword($hash, $password)
    {
        return password_verify($password, $hash);
    }

    public static function randomToken()
    {
        return bin2hex(random_bytes(32)); // 256-bit
    }
}
