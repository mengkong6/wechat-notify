#!/bin/sh
set -e

DATA_DIR="${WN_DATA_DIR:-/var/www/html/data}"
mkdir -p "$DATA_DIR"
# Linux 宿主机挂载时保证 www-data 可写；Docker Desktop/Windows 失败可忽略
chown -R www-data:www-data "$DATA_DIR" 2>/dev/null || true
chmod -R ugo+rwX "$DATA_DIR" 2>/dev/null || true

exec "$@"
