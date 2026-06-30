FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql mysqli

RUN set -ex; \
    find /etc/apache2/mods-enabled/ -name "mpm_*" -delete; \
    ln -s /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load; \
    ln -s /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf; \
    ls -la /etc/apache2/mods-enabled/ | grep mpm; \
    a2enmod rewrite

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

COPY apache-port.sh /apache-port.sh
RUN chmod +x /apache-port.sh

EXPOSE 80

CMD ["/apache-port.sh"]
# cache bust 1782820485
