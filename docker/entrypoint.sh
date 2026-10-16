#!/bin/sh
# Entrypoint kontainer Koperasi Sekolah — dijalankan setiap kali kontainer mulai.
#   1. Pilih proses menurut CONTAINER_ROLE (all | web | worker | scheduler).
#   2. Periksa APP_KEY, siapkan folder storage (volume) & hak aksesnya.
#   3. Tunggu basis data siap, jalankan migrasi (peran all/web), isi awal Pusat Panduan saat instalasi pertama.
#   4. Cache konfigurasi/rute/view/event, lalu jalankan supervisord.
# Perintah lain (mis. `docker compose run --rm app php -v`) dijalankan apa adanya tanpa langkah di atas.
set -eu

cd /var/www/html

log() { echo "[koperasi] $*"; }
artisan() { su-exec www-data php artisan "$@"; }

if [ "$#" -gt 0 ] && [ "$1" != "supervisord" ]; then
    exec "$@"
fi

ROLE="${CONTAINER_ROLE:-all}"
case "$ROLE" in
    all)          WEB=true;  QUEUE=true;  SCHED=true ;;
    web)          WEB=true;  QUEUE=false; SCHED=false ;;
    worker|queue) WEB=false; QUEUE=true;  SCHED=false ;;
    scheduler)    WEB=false; QUEUE=false; SCHED=true ;;
    *) log "CONTAINER_ROLE tidak dikenal: '$ROLE' (pilih all, web, worker, atau scheduler)."; exit 1 ;;
esac
export SUPERVISOR_WEB="$WEB" SUPERVISOR_QUEUE="$QUEUE" SUPERVISOR_SCHEDULER="$SCHED"
log "Peran kontainer: $ROLE (web=$WEB, antrean=$QUEUE, penjadwal=$SCHED)"

if [ -z "${APP_KEY:-}" ]; then
    log "APP_KEY belum diisi. Buat dengan: echo \"base64:\$(openssl rand -base64 32)\" lalu simpan sebagai env APP_KEY."
    log "Simpan nilainya baik-baik — APP_KEY yang berganti membuat sesi, data terenkripsi & rantai audit tidak terbaca."
    exit 1
fi
if [ -z "${APP_URL:-}" ]; then
    log "Peringatan: APP_URL belum diisi (mis. https://koperasi.sekolah.sch.id) — tautan & ekspor bisa salah alamat."
fi

# Volume storage bisa kosong (instalasi pertama) atau berisi berkas milik root (perintah artisan tanpa -u www-data).
mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Cache konfigurasi lama (kontainer yang di-restart) dibuang agar env terbaru yang dipakai.
artisan config:clear >/dev/null

# Tunggu basis data (kontainer PostgreSQL bisa lebih lambat siap).
timeout="${DB_WAIT_TIMEOUT:-60}"
waited=0
until su-exec www-data php -r '
    require "vendor/autoload.php";
    $app = require "bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    try { Illuminate\Support\Facades\DB::connection()->getPdo(); exit(0); }
    catch (Throwable $e) { fwrite(STDERR, $e->getMessage().PHP_EOL); exit(1); }
' >/dev/null 2>/tmp/db-wait.err; do
    waited=$((waited + 2))
    if [ "$waited" -ge "$timeout" ]; then
        log "Basis data tidak bisa dihubungi setelah ${timeout} detik:"
        cat /tmp/db-wait.err >&2
        exit 1
    fi
    log "Menunggu basis data… (${waited}s)"
    sleep 2
done
rm -f /tmp/db-wait.err
log "Basis data terhubung."

if [ "$WEB" = "true" ] && [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    log "Menjalankan migrasi…"
    artisan migrate --force --no-interaction

    # Instalasi pertama: isi Pusat Panduan dari konfigurasi menu (aman diulang; panduan manual tidak ditimpa).
    # Penanda disimpan di volume storage. Barang/akun contoh TIDAK diisi di production.
    if [ "${SEED_GUIDES:-true}" = "true" ] && [ ! -f storage/app/.guide-seeded ]; then
        log "Mengisi awal Pusat Panduan…"
        artisan db:seed --class=GuideSeeder --force --no-interaction
        su-exec www-data touch storage/app/.guide-seeded
    fi
fi

log "Menyimpan cache konfigurasi, rute, event & view…"
artisan optimize

log "Siap. Buat admin pertama (sekali saja): artisan koperasi:buat-admin"
exec "$@"
