<?php
/**
 * 前端控制器（放在项目根目录，Apache / Nginx 子目录部署用）
 *
 * 这是部署到二级目录时的推荐入口：把 Web 服务器指向本项目目录，
 * 访问 http://host/wechat-notify/ 即可直接看到首页，API 走 /wechat-notify/api/*。
 *
 * 基路径自动从 SCRIPT_NAME 探测；也可用环境变量 WN_BASE 显式指定。
 * 内置服务器（php cloud.php）不走这个文件，它用 src/CloudApp.php 作为路由。
 */

require_once __DIR__ . '/src/bootstrap.php';

$cfg = wn_config();
$web = __DIR__ . '/public';

// 自动探测部署基路径
$scriptName = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '';
$uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';

// 基路径 = 入口脚本所在目录
//   /wechat-notify/index.php  -> /wechat-notify
//   /index.php            -> ''  （根部署 或 内置服务器 SPA 回退）
$base = '';
if ($scriptName !== '' && preg_match('#\.php(?:/|$)#', $scriptName)) {
    $dir = rtrim(dirname($scriptName), '/');
    if ($dir !== '' && $dir !== '/' && $dir !== '.') {
        $base = $dir;
    }
}
// 内置服务器 SPA 回退兜底：SCRIPT_NAME=/index.php 但 REQUEST_URI=/wechat-notify/
// 此时从 REQUEST_URI 推导
if ($base === '') {
    $uriPath = parse_url($uri, PHP_URL_PATH);
    if ($uriPath && $uriPath !== '/' && preg_match('#^(/[^/]+)(/|$)#', $uriPath, $m)) {
        $cand = $m[1];
        // 确认这是子目录而非入口文件名
        if (!preg_match('#\.php$#', $cand)) {
            $base = $cand;
        }
    }
}

// 允许环境变量覆盖
if (getenv('WN_BASE') !== false) {
    $base = rtrim('/' . trim(getenv('WN_BASE'), '/'), '/');
    if ($base === '/') {
        $base = '';
    }
}

$store = new Store($cfg['db_path']);
$app   = new CloudApp($store, $cfg['poll_timeout'], $cfg['send_timeout'], $web, $cfg['db_path'], $base);

// 无 rewrite 部署时，把 API 入口注入首页，前端用 ?_path= 方式调用
if ($base !== '') {
    $app->setInjectApiBase($base . '/index.php');
}

$app->handle($uri);
