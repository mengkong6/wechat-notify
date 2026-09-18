<?php
/**
 * cloud.php — wechat-notify 多用户网页通知/发件系统入口
 *
 * 用法：
 *   php cloud.php [-port 7860] [-web public] [-poll 60s] [-send-timeout 500ms] [-db data/app.sqlite]
 *
 * 本脚本内部会启动 PHP 内置服务器（php -S），同时服务前端静态文件与 API。
 * 生产环境请用 Docker（Apache），不要依赖内置服务器。
 */

require_once __DIR__ . '/src/bootstrap.php';

exit(CloudApp::cliMain($argv));
