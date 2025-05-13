FROM openswoole/swoole

# Install Swoole & deps
RUN apt-get update \
    && apt-get install -y git unzip libonig-dev zip

# Copy Composer
#COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy project files and install deps
COPY composer.json composer.lock* /app/
RUN composer update --optimize-autoloader

COPY . /app

EXPOSE 9501
CMD ["php", "server.php"]
