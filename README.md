# wechat-notify

基于微信 **ClawBot** 的实时通知系统。

通过微信 iLink Bot HTTP API 绑定你的微信号，实现网页/脚本实时发通知，数据库使用 **SQLite**，界面与部署方式已按本项目整理。

> 没有 WebSocket / 回调，全靠 HTTP 长轮询。
> 本项目为解析原理后的独立实现，仅作学习/自用。

## 特性

- **零外部依赖**：只用 PHP 标准扩展（`curl`、`PDO_SQLite`、`GD`、`bcrypt`），无需 Composer。
- **接口兼容**：与原版 Go 版 API 路径、请求/响应字段保持一致。
- **SQLite 存储**：单文件数据库，无需部署 PostgreSQL。
- **Docker 友好**：GitHub Actions 自动构建镜像并推送到 GHCR。
- **三个入口**：交互式 CLI、单次发件、多用户网页通知系统。

## 环境要求

- PHP **7.2** 或更高（开发测试环境为 PHP 8.3）
- 需启用扩展：`curl`、`pdo_sqlite`、`gd`、`bcrypt`（PHP 内置）、`random_int`（PHP 7+ 内置）

检查扩展是否齐全：

```bash
php -m | grep -iE 'curl|sqlite|gd'
```

## 目录结构

```
wechat-notify/
├── notify.php             # 交互式 CLI（扫码登录 + 聊天）
├── send.php               # 单次发件（脚本友好，stdout 一行 JSON）
├── cloud.php              # 多用户网页通知系统入口
├── index.php              # 前端控制器（Apache/Nginx 子目录部署用）
├── src/
│   ├── bootstrap.php      # 引导 + 工具函数
│   ├── IlClient.php       # 微信 ClawBot（iLink）HTTP API 客户端
│   ├── Session.php        # 会话状态文件（session.json）
│   ├── Peers.php          # 联系人/回信上下文注册表
│   ├── QR.php             # 二维码渲染（SVG / PNG / 终端）
│   ├── Auth.php           # 密码哈希 + 会话令牌
│   ├── Store.php          # SQLite 存储
│   ├── CloudApp.php       # 网页通知系统后端（路由 + API）
│   ├── DemoCli.php        # 交互式 CLI 实现
│   ├── SendCli.php        # 单次发件实现
│   └── vendor/qrcode.php  # 第三方 QR 库（MIT）
├── Dockerfile
├── docker-compose.yml
├── docker/                # Apache 配置与 entrypoint
├── public/index.html      # 前端（无构建，原生单页）
└── data/                  # 运行时生成（SQLite、锁文件）
```

## Docker 部署

镜像用 **PHP 8.3 + Apache**（不是 `php -S`）：系统含长轮询，内置服务器单线程会互相阻塞。

### 云端构建（无需本地 Docker）

1. GitHub 仓库为 [mengkong6/wechat-notify](https://github.com/mengkong6/wechat-notify)
2. 推送到 `main`/`master` 或打 `v*` 标签
3. Actions 工作流 [`.github/workflows/docker-ghcr.yml`](.github/workflows/docker-ghcr.yml) 自动构建并推送到 GHCR

镜像地址：

```text
ghcr.io/mengkong6/wechat-notify:latest
```

服务器拉取运行：

```bash
# 私有包需要先登录（密码用 GitHub PAT，勾选 read:packages）
echo "$GHCR_TOKEN" | docker login ghcr.io -u mengkong6 --password-stdin

docker pull ghcr.io/mengkong6/wechat-notify:latest
docker run -d --name wechat-notify -p 7860:80 \
  -v /opt/wechat-notify/data:/var/www/html/data \
  ghcr.io/mengkong6/wechat-notify:latest
```

若希望匿名 `docker pull`，在 GitHub → Packages 里把镜像可见性设为 **Public**。

### 本地构建（需要 Docker）

```bash
docker compose up -d --build
# 浏览器打开 http://localhost:7860
```

| 项 | 说明 |
| --- | --- |
| 端口 | `WN_PORT`（默认 `7860`） |
| 数据 | 挂载 `./data` → `/var/www/html/data`（SQLite 持久化） |
| 子目录反代 | 在 `docker-compose.yml` 里设置 `WN_BASE`，如 `/wechat-notify` |
| 查看日志 | `docker compose logs -f` |
| 停止 | `docker compose down`（数据保留在 `./data`） |

无 Compose 时也可：

```bash
docker build -t ghcr.io/mengkong6/wechat-notify:latest .
docker run -d --name wechat-notify -p 7860:80 \
  -v "%cd%/data:/var/www/html/data" ghcr.io/mengkong6/wechat-notify:latest
```

Windows PowerShell 用 `-v "${PWD}/data:/var/www/html/data"`。

## 快速开始

### 1. 交互式 CLI

```bash
cd wechat-notify

# 登录（生成二维码 + 扫码）
php notify.php login

# 若已有 session，直接进入聊天
php notify.php chat

# 自动判断：有 session 进聊天，否则登录
php notify.php
```

扫码登录后进入聊天模式：

| 命令 | 说明 |
| --- | --- |
| `/help` | 显示帮助 |
| `/users` | 列出已知联系人 |
| `/who` | 显示当前联系人 |
| `/use <peer>` | 切换当前联系人 |
| `/send <peer> <m>` | 向指定联系人发消息 |
| `/quit` | 退出 |

### 2. 单次发件（脚本友好）

```bash
php send.php -state session.json "hello"
php send.php -state session.json -poll 500ms "hi"
```

`stdout` 只输出一行 JSON：

```json
{"to":"xxx@im.wechat","msgs":[{"from":"xxx@im.wechat","text":"hi","time_ms":1726...}],"sent":true}
```

### 3. 多用户网页通知系统

```bash
php cloud.php -port 7860
```

打开 `http://localhost:7860`：

1. 注册：设密码 → 微信扫码绑定。
2. 激活：用你的微信向 ClawBot 发任意一条消息。
3. 发件：网页输入消息内容，一键发送。

启动参数：

| 参数 | 默认 | 说明 |
| --- | --- | --- |
| `-port` | `7860` | 监听端口（环境变量 `PORT` 或 `WN_PORT`） |
| `-web` | `public` | 前端静态目录 |
| `-poll` | `60s` | 激活页等待第一条消息的总时长 |
| `-send-timeout` | `500ms` | 发件前短轮询取历史时长，`0` 为纯缓存直发 |
| `-db` | `data/app.sqlite` | SQLite 路径（环境变量 `WN_DB`） |
| `-base` | 空 | 子目录基路径（如 `-base /wechat-notify`，环境变量 `WN_BASE`） |

## 部署到二级目录

**方式一：内置服务器（开发调试）**

```bash
php cloud.php -port 7860 -base /wechat-notify
# 访问 http://localhost:7860/wechat-notify/
```

**方式二：Apache（推荐）**

Web 根直接指到项目目录本身（不要指到 `public/`），并开启 rewrite：

```
DocumentRoot "/var/www/html/wechat-notify"
<Directory "/var/www/html/wechat-notify">
    AllowOverride All
    Require all granted
</Directory>
```

访问 `http://your-host/wechat-notify/`，API 走 `/wechat-notify/api/*`。

**方式三：Nginx**

```nginx
location /wechat-notify/ {
    root  /var/www/html;
    try_files $uri $uri/ /wechat-notify/index.php?$query_string;
}
location ~ \.php$ {
    root           /var/www/html;
    include        fastcgi_params;
    fastcgi_pass   127.0.0.1:9000;
    fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

> - 前端控制器是**项目根目录的 `index.php`**。
> - 基路径可用环境变量 `WN_BASE=/wechat-notify` 显式指定。
> - 前端 API 前缀按当前页面目录自动计算。

## API 接口

| 方法 | 路径 | 鉴权 | 说明 |
| --- | --- | --- | --- |
| `GET` | `/health` | 无 | 存活检查 `{"ok":true}` |
| `POST` | `/api/register/qr` | 无 | `{password}` → `{qrcode, qr_png, expires_in}` |
| `GET` | `/api/register/status` | 无 | `?qrcode=` → `{status, user_id?, ...}` |
| `POST` | `/api/register/finish` | 无 | `{password, bot_token, user_id, base_url}` → `{user_id, overwritten}` |
| `POST` | `/api/login` | 无 | `{user_id, password}` → 种 cookie |
| `POST` | `/api/logout` | cookie | 清会话 |
| `GET` | `/api/me` | cookie | `{user_id, ready, peer, poll_timeout}` |
| `GET` | `/api/account/ready` | cookie | 等待第一条消息 `{ready, peer?}` |
| `POST` | `/api/send` | cookie **或** body | `{text}` 或 `{user_id, password, text}` → `{to, msgs, sent, error?}` |

`send` 免登录直调示例：

```bash
curl -s -X POST localhost:7860/api/send \
  -H 'Content-Type: application/json' \
  -d '{"user_id":"xxx@im.wechat","password":"***","text":"hi"}'
```

## 原理要点

- 收消息走 `POST /ilink/bot/getupdates` 长轮询，`get_updates_buf` 是服务端游标。
- 发消息走 `POST /ilink/bot/sendmessage`，必填对端 `context_token`——只能从对方先发来的消息里拿到，**新好友必须先说第一句话**。
- `bot_token` 长期有效，服务端回 `ret/errcode = -14` 才算过期，需重新扫码。
- 请求头带 `Authorization: Bearer <bot_token>`、`AuthorizationType: ilink_bot_token`、`X-WECHAT-UIN`。
- 一个 `session.json` 对应一个扫码绑定的号，多号用 `-state` 分文件跑。

## 常见问题

**Q：`curl` 未启用？**  
Windows 下在 `php.ini` 取消注释 `extension=curl`；Linux 用 `apt install php-curl`。

**Q：SSL certificate 报错？**  
可设置 `WN_INSECURE=1` 跳过校验（仅自用），或正确配置 `curl.cainfo`。

**Q：如何清空数据库重新开始？**  
删除 `data/app.sqlite` 与 `data/app.sqlite-*`。

**Q：本地没有 Docker 怎么打镜像？**  
推 GitHub 后由 Actions 构建，镜像在 `ghcr.io/mengkong6/wechat-notify`。

## 许可

本项目仅作学习与技术研究用途。二维码库为 [kazuhikoarase/qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator)（MIT License）。
