FROM php:8.3-apache
RUN docker-php-ext-install pdo pdo_mysql

# Fuso horário do Brasil
ENV TZ=America/Sao_Paulo
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Copia o código para dentro da imagem (usado no Render, local o volume sobrepõe)
COPY src/ /var/www/html/