#!/bin/bash
PORT="${PORT:-80}"
sed -i "s/^Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-enabled/000-default.conf

# Force only mpm_prefork to be enabled, no matter what happened at build time
a2dismod -f mpm_event mpm_worker > /dev/null 2>&1
a2enmod -f mpm_prefork > /dev/null 2>&1

exec apache2-foreground
