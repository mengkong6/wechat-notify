# wechat-notify

基于微信 **ClawBot** 的实时通知系统。

绑定微信号后，通过网页或脚本向微信发送通知。数据用 SQLite；默认用 Docker 部署，镜像由 GitHub Actions 自动构建。

> 没有 WebSocket / 回调，全靠 HTTP 长轮询。仅作学习/自用。

## 功能介绍

把你自己的微信绑到 ClawBot 后，任何能发 HTTP 请求的地方（脚本、CI、服务器监控、定时任务…）都可以把消息推到微信里。

- 📱 **扫码绑定**：网页注册后用微信扫码，一键绑定到 ClawBot
- 🌐 **网页发信**：登录后在浏览器填写内容，一键发送通知
- 🔌 **HTTP API**：`POST /api/send` 即可发消息，方便接入脚本与自动化
- 🤖 **多入口**：网页控制台、单次发件 CLI、交互式终端聊天
- 🗄️ **本地存储**：账号与会话落在 SQLite 单文件，备份就是拷一个库
- 🐳 **开箱即用**：GitHub Actions 自动构建镜像，`docker run` 即可跑起来

## ✨ 特点

- 🚀 **部署简单** — 官方镜像在 GHCR，无需本地编译或装 PHP
- 🪶 **足够轻量** — 无 Composer、无数据库服务，单容器 + 一个数据目录
- 🧩 **接口清晰** — 注册 / 登录 / 发送都是标准 JSON HTTP API，易对接
- 🔁 **长轮询取信** — 不依赖回调地址，内网/无公网 IP 也能用
- 🔒 **多用户** — 网页端支持注册登录，各账号独立绑定与发送
- 🧰 **自用友好** — 适合个人服务器告警、脚本推送、轻量通知场景

## 快速开始（Docker）

```bash
# 1）拉取镜像并启动（端口 7860）
docker run -d --name wechat-notify -p 7860:80 \
  -v $PWD/data:/var/www/html/data \
  ghcr.io/mengkong6/wechat-notify:latest

# 2）浏览器打开
# http://localhost:7860
```

或用 Compose：

```bash
docker compose up -d
```

镜像地址：`ghcr.io/mengkong6/wechat-notify:latest`  
仓库：https://github.com/mengkong6/wechat-notify  
私有包需 `docker login ghcr.io -u mengkong6`；公开拉取则在 GitHub Packages 里把镜像设为 Public。

| 项 | 值 |
| --- | --- |
| 端口 | `WN_PORT`（默认 `7860` → 容器 `80`） |
| 数据 | 挂载本机 `./data` → `/var/www/html/data` |
| 子目录反代 | `WN_BASE=/wechat-notify`（一般不用） |
| 日志 / 停止 | `docker compose logs -f` · `docker compose down` |

推送到 GitHub `main` 或打 `v*` 标签后，Actions 会自动构建并推到 GHCR（见 `.github/workflows/docker-ghcr.yml`）。

## 使用流程

1. 打开网页 → 注册（设密码）→ 微信扫码绑定 ClawBot  
2. 用绑定的微信向 ClawBot **先发任意一条消息**（激活，才能拿到回信凭证）  
3. 网页填写内容发送，或调用 HTTP API

## HTTP API

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| `GET` | `/health` | `{"ok":true}` |
| `POST` | `/api/register/qr` | `{password}` → 二维码 |
| `GET` | `/api/register/status` | `?qrcode=` 扫码状态 |
| `POST` | `/api/register/finish` | 绑定完成 |
| `POST` | `/api/login` | `{user_id, password}` |
| `GET` | `/api/me` | 当前账号状态 |
| `POST` | `/api/send` | `{text}` 或 `{user_id, password, text}` |

免登录发消息示例：

```bash
curl -s -X POST http://localhost:7860/api/send \
  -H 'Content-Type: application/json' \
  -d '{"user_id":"xxx@im.wechat","password":"***","text":"服务已重启"}'
```

## 命令行（可选）

有 PHP 7.2+（`curl` / `pdo_sqlite` / `gd`）时可用：

```bash
php notify.php login    # 扫码登录
php notify.php chat     # 终端聊天
php send.php -state session.json "hello"   # 单次发件，stdout 一行 JSON
php cloud.php -port 7860                   # 本地内置服务器（仅调试）
```

生产请用 Docker（Apache），不要用 `php cloud.php` 做多用户长轮询。

## 目录结构

```
wechat-notify/
├── notify.php / send.php / cloud.php   # CLI 入口
├── index.php                           # Web 前端控制器
├── src/                                # 业务代码（API 客户端、存储、路由）
├── public/index.html                   # 前端单页
├── Dockerfile · docker-compose.yml · docker/
└── data/                               # SQLite（运行时生成，勿提交）
```

## 原理要点

- 收消息：`POST /ilink/bot/getupdates` 长轮询  
- 发消息：`POST /ilink/bot/sendmessage`，需要对方 `context_token`（对方先发过消息才有）  
- `bot_token` 长期有效；`errcode = -14` 表示过期，需重新扫码  

## 许可

仅作学习与技术研究。二维码库 [kazuhikoarase/qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator)（MIT）。
