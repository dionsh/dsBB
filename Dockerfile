FROM php:8.2-apache

# Enable PDO MySQL extension
RUN docker-php-ext-install pdo_mysql

# Enable Apache mod_rewrite (handy for routing, harmless if unused)
RUN a2enmod rewrite

# Copy app source into the Apache document root
COPY . /var/www/html/

WORKDIR /var/www/html

# Render injects $PORT at runtime; Apache must listen on it
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
