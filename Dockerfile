# Dockerfile
FROM php:7.4-cli

# Instalace závislostí
RUN apt-get update && apt-get install -y \
    unzip zip git curl && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app