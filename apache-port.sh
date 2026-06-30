#!/bin/bash
echo "=== MPM modules enabled at runtime ==="
ls -la /etc/apache2/mods-enabled/ | grep mpm
echo "======================================="
PORT="${PORT:-80}"
sed -i "s/^Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-enabled/000-default.conf
exec apache2-foreground
