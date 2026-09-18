<?php
/**
 * send.php — wechat-notify 单次发件入口
 * 用法：php send.php [-state session.json] [-poll 2s] <text>
 */

require_once __DIR__ . '/src/bootstrap.php';

$cli = new SendCli();
exit($cli->run($argv));
