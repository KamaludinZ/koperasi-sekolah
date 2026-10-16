#!/bin/sh
# Jalankan artisan sebagai www-data dari mana saja di dalam kontainer, mis. di terminal Coolify:
#   artisan koperasi:buat-admin
# Menjalankan `php artisan` sebagai root bisa membuat berkas di storage yang tidak bisa ditulis aplikasi.
cd /var/www/html
if [ "$(id -u)" = "0" ]; then
    exec su-exec www-data php artisan "$@"
fi
exec php artisan "$@"
