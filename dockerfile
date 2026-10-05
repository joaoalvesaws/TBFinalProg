FROM php:8.3-apache

RUN docker-php-ext-install pdo pdo_mysql

# Fuso horário do Brasil (importante para datas e lembretes)
ENV TZ=America/Sao_Paulo
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone
