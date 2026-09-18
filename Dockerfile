# wechat-notify — 基于微信 ClawBot 的实时通知系统
# Apache 运行时：长轮询场景不要用 php -S（单线程会互相阻塞）
FROM php:8.3-apache-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite gd \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Web 根指到项目目录本身（与 README 的 Apache 部署方式一致，不是 public/）
COPY docker/apache.conf /etc/apache2/conf-available/wechat-notify.conf
RUN a2enconf wechat-notify

WORKDIR /var/www/html
COPY . /var/www/html/

COPY docker/entrypoint.sh /usr/local/bin/wechat-notify-entrypoint.sh
RUN chmod +x /usr/local/bin/wechat-notify-entrypoint.sh \
    && mkdir -p /var/www/html/data \
    && chown -R www-data:www-data /var/www/html/data

ENV WN_DB=/var/www/html/data/app.sqlite \
    WN_POLL_TIMEOUT=60 \
    WN_SEND_TIMEOUT=0.5

EXPOSE 80

ENTRYPOINT ["wechat-notify-entrypoint.sh"]
CMD ["apache2-foreground"]
