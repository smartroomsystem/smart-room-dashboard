FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql mysqli

RUN a2dismod mpm_event || true
RUN a2enmod mpm_prefork
RUN a2enmod rewrite

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

COPY apache-port.sh /apache-port.sh
RUN chmod +x /apache-port.sh

EXPOSE 80

CMD ["/apache-port.sh"]
