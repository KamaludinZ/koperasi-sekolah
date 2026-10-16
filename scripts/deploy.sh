#!/usr/bin/env bash
# Deploy/perbarui aplikasi di server TANPA Docker (VPS atau hosting dengan akses SSH + git).
# Jalankan dari folder aplikasi di server:
#   scripts/deploy.sh             → tarik kode terbaru, pasang dependensi, migrasi, cache, restart pekerja
#   scripts/deploy.sh --no-pull   → pakai kode yang sudah ada (mis. hasil ekstrak paket rilis)
# Syarat: .env production sudah diisi (lihat .env.production.example & docs/deploy.md).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
PHP="${PHP:-php}"
PULL=true
[ "${1:-}" = "--no-pull" ] && PULL=false

[ -f .env ] || { echo "Berkas .env belum ada. Salin .env.production.example ke .env lalu isi." >&2; exit 1; }
grep -qE '^APP_KEY=.+' .env || { echo "APP_KEY di .env masih kosong. Jalankan: $PHP artisan key:generate" >&2; exit 1; }

on_error() {
    echo "✗ Deploy gagal. Aplikasi tetap dalam mode perawatan agar data aman." >&2
    echo "  Perbaiki masalahnya lalu jalankan ulang skrip ini, atau buka kembali dengan: $PHP artisan up" >&2
}
trap on_error ERR

echo "→ Mode perawatan…"
"$PHP" artisan down --retry=60 || true

if $PULL; then
    echo "→ Menarik kode terbaru…"
    git pull --ff-only
fi

if command -v composer >/dev/null; then
    echo "→ Memasang dependensi PHP…"
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress
fi

if command -v npm >/dev/null; then
    echo "→ Membangun aset…"
    npm ci --no-audit --no-fund
    npm run build
elif [ ! -f public/build/manifest.json ]; then
    echo "public/build belum ada dan npm tidak tersedia — pakai paket dari scripts/build-release.sh." >&2
    exit 1
fi
rm -f public/hot

echo "→ Migrasi basis data…"
"$PHP" artisan migrate --force --no-interaction

# Instalasi pertama: isi awal Pusat Panduan (aman diulang; panduan manual tidak ditimpa).
if [ ! -f storage/app/.guide-seeded ]; then
    echo "→ Mengisi awal Pusat Panduan…"
    "$PHP" artisan db:seed --class=GuideSeeder --force --no-interaction
    touch storage/app/.guide-seeded
fi

echo "→ Cache konfigurasi, rute, event & view…"
"$PHP" artisan optimize
"$PHP" artisan storage:link >/dev/null 2>&1 || true

echo "→ Memulai ulang pekerja antrean (mengambil kode baru)…"
"$PHP" artisan queue:restart

"$PHP" artisan up
trap - ERR
echo "✓ Deploy selesai. Periksa Sistem → Kesehatan di aplikasi."
