<?php
/**
 * notify.php — wechat-notify 交互式 CLI 入口
 * 用法见 README，或运行 `php notify.php help`
 */

require_once __DIR__ . '/src/bootstrap.php';

$cli = new DemoCli();
exit($cli->run($argv));
