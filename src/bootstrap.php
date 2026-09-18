<?php
/**
 * wechat-notify — 统一引导入口
 * 基于微信 ClawBot 的实时通知系统
 * 兼容 PHP 7.2+
 */

if (PHP_VERSION_ID < 70200) {
    fwrite(STDERR, "需要 PHP 7.2 或更高版本，当前 " . PHP_VERSION . "\n");
    exit(1);
}

define('WN_ROOT', dirname(__DIR__));

require_once __DIR__ . '/IlClient.php';
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/Peers.php';
require_once __DIR__ . '/QR.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/CloudApp.php';

/**
 * 读取配置（环境变量优先，带默认值）
 */
function wn_config()
{
    $root = defined('WN_ROOT') ? WN_ROOT : dirname(__DIR__);
    return array(
        'db_path'      => getenv('WN_DB') ?: $root . '/data/app.sqlite',
        'poll_timeout' => (float)(getenv('WN_POLL_TIMEOUT') !== false ? getenv('WN_POLL_TIMEOUT') : 60),
        'send_timeout' => (float)(getenv('WN_SEND_TIMEOUT') !== false ? getenv('WN_SEND_TIMEOUT') : 0.5),
    );
}

/**
 * 简易命令行参数解析：支持 -key value / -key=value / --key=value / 位置参数
 */
function wn_parse_args($argv)
{
    $opts = array();
    $rest = array();
    $n = count($argv);
    for ($i = 1; $i < $n; $i++) {
        $a = $argv[$i];
        if (substr($a, 0, 2) === '--') {
            $a = substr($a, 2);
            $key = $a;
            $val = true;
            $eq = strpos($a, '=');
            if ($eq !== false) {
                $key = substr($a, 0, $eq);
                $val = substr($a, $eq + 1);
            }
            $opts[$key] = $val;
        } elseif (substr($a, 0, 1) === '-' && strlen($a) > 1) {
            $key = substr($a, 1);
            $val = true;
            $eq = strpos($key, '=');
            if ($eq !== false) {
                $key = substr($key, 0, $eq);
                $val = substr($key, $eq + 1);
            } elseif ($i + 1 < $n && substr($argv[$i + 1], 0, 1) !== '-') {
                $val = $argv[++$i];
            }
            $opts[$key] = $val;
        } else {
            $rest[] = $a;
        }
    }
    return array($opts, $rest);
}

/** 取命令行参数（带默认值） */
function wn_opt($opts, $key, $default = null)
{
    return array_key_exists($key, $opts) && $opts[$key] !== true ? $opts[$key] : $default;
}

/** 终端日志（CLI 用） */
function wn_log($msg, $detail = '')
{
    $line = '[' . date('H:i:s') . '] ' . $msg;
    if ($detail !== '') {
        $line .= ' ' . $detail;
    }
    fwrite(STDOUT, $line . "\n");
}
