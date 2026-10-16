# Koperasi Sekolah

Aplikasi koperasi sekolah/madrasah: kasir (POS) dengan pemindai barcode, barang & stok, pengadaan &
hutang supplier, diskon/promo/kupon, anggota, simpanan, pinjaman (jadwal angsuran, pencairan, tunggakan),
kas harian & shift, laporan, portal anggota, audit & impersonasi, Pusat Panduan per peran, serta cadangan
data dan pemantauan kesehatan sistem.

**Stack:** Laravel 12 · Livewire 3 · Tailwind CSS 4 (Vite) · PHP 8.4+ (dikembangkan di 8.5) · PostgreSQL.

## Pengembangan lokal

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
# siapkan PostgreSQL: docs/database-postgresql.md (atau DB_CONNECTION=sqlite untuk coba cepat)
php artisan migrate --seed      # di lingkungan local juga mengisi akun & data contoh
composer dev                    # server, antrean, log, dan Vite sekaligus
```

Tes: `php artisan test` (SQLite in-memory). Uji di PostgreSQL dengan `DB_CONNECTION=pgsql` pada env tes.

## Deploy ke production

Siap dipasang lewat **Coolify** (Docker Compose atau Dockerfile), **VPS + Docker Compose**, maupun
**hosting biasa/cPanel**. Panduan lengkap langkah demi langkah: **[docs/deploy.md](docs/deploy.md)**.

| Berkas | Fungsi |
| --- | --- |
| `Dockerfile` | Image production: nginx + php-fpm + pekerja antrean + penjadwal, `pg_dump` untuk cadangan |
| `docker-compose.yml` | Aplikasi + PostgreSQL 18 dengan volume persisten (Coolify / VPS) |
| `docker-compose.vps.yml` | Tambahan untuk VPS: buka port 8080 di 127.0.0.1 bagi reverse proxy |
| `docker/` | Konfigurasi nginx, php, php-fpm, supervisord, entrypoint & healthcheck |
| `.env.production.example` | Semua variabel production beserta penjelasannya |
| `scripts/build-release.sh` | Paket zip siap unggah untuk hosting tanpa Composer/Node |
| `scripts/deploy.sh` | Deploy/pembaruan di server tanpa Docker |

Setelah deploy pertama, buat admin: `php artisan koperasi:buat-admin` (di kontainer: `artisan koperasi:buat-admin`).
