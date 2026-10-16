# Deploy ke Production

Panduan memasang **Koperasi Sekolah** di server production. Pilih salah satu jalur:

| Jalur | Cocok untuk | Berkas yang dipakai |
| --- | --- | --- |
| **A. Coolify + Docker Compose** (disarankan) | Server dengan Coolify; aplikasi + database dalam satu stack | `docker-compose.yml`, `Dockerfile` |
| **B. Coolify + Dockerfile** | Coolify dengan database PostgreSQL yang dikelola terpisah | `Dockerfile` |
| **C. VPS + Docker Compose** | VPS biasa (tanpa Coolify) dengan Docker | `docker-compose.yml` + `docker-compose.vps.yml` |
| **D. Hosting biasa / cPanel / VPS tanpa Docker** | Shared hosting dengan PHP 8.4+ & PostgreSQL | `scripts/build-release.sh`, `scripts/deploy.sh` |

---

## 1. Gambaran aplikasi di production

Aplikasi Laravel 12 + Livewire 3 ini butuh **empat komponen** yang semuanya harus hidup:

1. **Web** — nginx + php-fpm melayani halaman (`public/`).
2. **Pekerja antrean** — `queue:work` memproses ekspor laporan, cadangan, uji pemulihan, notifikasi
   (antrean `notifikasi, ekspor, default, cadangan`).
3. **Penjadwal** — `schedule:run` tiap menit: cadangan harian 23:00, deteksi tunggakan pinjaman 00:05,
   retensi data 02:00, verifikasi jejak audit, pengingat hutang, cuplikan kesehatan tiap 5 menit, dll.
   Lihat semuanya dengan `php artisan schedule:list`.
4. **Penyimpanan persisten** `storage/` — logo & QRIS, bukti pencairan pinjaman, struk PDF, berkas ekspor,
   arsip cadangan (`storage/app/private/cadangan`). **Hilang = data hilang**, jadi wajib volume/folder tetap.

Basis data: **PostgreSQL** (dikembangkan & diuji di PostgreSQL 18; versi 14 ke atas didukung).
Sesi, cache, dan antrean disimpan di tabel PostgreSQL — **tidak butuh Redis**.

Image Docker (`Dockerfile`) sudah menjalankan web + pekerja antrean + penjadwal dalam **satu kontainer**
(`CONTAINER_ROLE=all`) lewat supervisord, plus `pg_dump` 18 untuk cadangan.

Saat kontainer mulai, entrypoint otomatis: memeriksa `APP_KEY`, menyiapkan folder `storage`, menunggu
database, menjalankan **migrasi**, mengisi awal **Pusat Panduan** (hanya instalasi pertama), lalu
`php artisan optimize` (cache config/rute/view/event). Barang & akun contoh **tidak** diisi di production.

---

## 2. Siapkan rahasia sebelum deploy

Buat dua nilai ini sekali saja dan **simpan di pengelola sandi**:

```bash
# APP_KEY — kunci enkripsi (sesi, data terenkripsi, HMAC rantai audit pinjaman)
echo "base64:$(openssl rand -base64 32)"

# DB_PASSWORD — password user database aplikasi
openssl rand -base64 24 | tr -d '/+=' 
```

> ⚠️ **Jangan pernah mengganti APP_KEY** setelah aplikasi dipakai. Sesi, kolom terenkripsi, dan verifikasi
> rantai audit pinjaman bergantung padanya. Bila hilang, data terenkripsi tidak bisa dibaca lagi.

Variabel wajib: `APP_KEY`, `APP_URL` (mis. `https://koperasi.sekolah.sch.id`), `DB_PASSWORD`.
Semua variabel lain beserta penjelasannya ada di **`.env.production.example`**.

---

## 3. Jalur A — Coolify + Docker Compose (disarankan)

1. **Project → + New → Resource → Private Repository (with GitHub App)** — pilih repo & branch `master`.
2. **Build Pack: `Docker Compose`**, Base Directory `/`, Docker Compose Location `/docker-compose.yml`.
3. Tab **Environment Variables** — isi minimal:
   ```dotenv
   APP_KEY=base64:....
   APP_URL=https://koperasi.sekolah.sch.id
   DB_PASSWORD=....
   ```
   Variabel lain (mis. `KOPERASI_BACKUP_TIME`, `QUEUE_WORKERS`) opsional — lihat `.env.production.example`.
4. Pada service **app**, isi **Domains**: `https://koperasi.sekolah.sch.id:8080`
   (`:8080` = port di dalam kontainer; Coolify/Traefik melayani publik di 443 dan mengurus sertifikat
   Let's Encrypt). Service **db** tidak diberi domain (hanya jaringan internal).
5. Arahkan DNS domain (A record) ke IP server Coolify.
6. Klik **Deploy**. Build pertama ±3–6 menit. Pantau log: harus muncul
   `[koperasi] Basis data terhubung.` → `Menjalankan migrasi…` → `Siap.`
7. **Buat admin pertama** — buka tab **Terminal**, pilih kontainer `app`, jalankan:
   ```bash
   artisan koperasi:buat-admin --name="Admin Koperasi" --email=admin@sekolah.sch.id
   ```
   (kata sandi ditanyakan tersembunyi; minimal 8 karakter, huruf & angka).
8. Lanjut ke [§7 Setelah deploy pertama](#7-setelah-deploy-pertama).

Volume `koperasi-storage` (storage aplikasi) dan `koperasi-pgdata` (data PostgreSQL) dibuat otomatis
dan **tetap ada** di setiap redeploy. Aktifkan **Auto Deploy** (webhook GitHub) bila ingin setiap push ke
`master` langsung dipasang — migrasi berjalan otomatis saat kontainer baru mulai.

---

## 4. Jalur B — Coolify + Dockerfile (database terpisah)

1. Buat dulu **+ New → Database → PostgreSQL** (versi 16–18) di project/server yang sama.
   Catat *username*, *password*, *database*, dan **host internal** (bagian host dari "Postgres URL (internal)").
2. **+ New → Private Repository** → **Build Pack: `Dockerfile`**, Ports Exposes **`8080`**.
3. **Environment Variables**:
   ```dotenv
   APP_KEY=base64:....
   APP_URL=https://koperasi.sekolah.sch.id
   DB_CONNECTION=pgsql
   DB_HOST=<host internal PostgreSQL Coolify>
   DB_PORT=5432
   DB_DATABASE=<nama database>
   DB_USERNAME=<user>
   DB_PASSWORD=<password>
   SESSION_DRIVER=database
   CACHE_STORE=database
   QUEUE_CONNECTION=database
   DB_QUEUE_RETRY_AFTER=960
   FORCE_HTTPS=true
   TRUSTED_PROXIES=*
   APP_LOCALE=id
   ```
   (Nilai lain mengikuti `.env.production.example`.)
4. **Persistent Storage → + Add → Volume Mount**: Destination Path **`/var/www/html/storage`**. **Wajib.**
5. **Domains**: `https://koperasi.sekolah.sch.id`. Health check: path `/up`, port `8080` (image sudah
   punya `HEALTHCHECK` sendiri).
6. Deploy, lalu buat admin lewat Terminal seperti Jalur A langkah 7.

Aktifkan juga **Backups** terjadwal pada resource PostgreSQL Coolify (ke S3 bila ada) sebagai lapisan
cadangan kedua.

---

## 5. Jalur C — VPS + Docker Compose (tanpa Coolify)

Butuh Docker Engine + plugin Compose dan reverse proxy HTTPS di host (contoh: Caddy).

```bash
git clone git@github.com:KamaludinZ/koperasi-sekolah.git /opt/koperasi
cd /opt/koperasi
cp .env.production.example .env
nano .env          # isi APP_KEY, APP_URL, DB_PASSWORD (DB_HOST tetap "db")
docker compose -f docker-compose.yml -f docker-compose.vps.yml up -d --build
docker compose logs -f app            # tunggu "[koperasi] Siap."
docker compose exec app artisan koperasi:buat-admin
```

`docker-compose.vps.yml` membuka aplikasi di `127.0.0.1:8080` (hanya dari host). Teruskan dengan Caddy
(`/etc/caddy/Caddyfile`) — sertifikat HTTPS otomatis:

```caddy
koperasi.sekolah.sch.id {
    encode gzip
    reverse_proxy 127.0.0.1:8080
}
```

atau Nginx host (sertifikat lewat certbot):

```nginx
server {
    listen 443 ssl http2;
    server_name koperasi.sekolah.sch.id;
    # ssl_certificate ... (certbot)
    client_max_body_size 20m;
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 180s;
    }
}
```

Pembaruan: `git pull && docker compose -f docker-compose.yml -f docker-compose.vps.yml up -d --build`.

---

## 6. Jalur D — Hosting biasa / cPanel / VPS tanpa Docker

### Kebutuhan server
- **PHP 8.4 atau lebih baru** (disarankan 8.5) dengan ekstensi: `pdo_pgsql`, `mbstring`, `xml`/`dom`,
  `zip`, `gd`, `intl`, `fileinfo`, `openssl`, `curl`, `ctype`, `tokenizer`; `pcntl` untuk CLI (disarankan).
- Fungsi `proc_open` tidak dinonaktifkan (dipakai `pg_dump` saat cadangan) — bila hosting melarangnya,
  set `KOPERASI_BACKUP_PG_DUMP=false` (cadangan tetap berisi `database.json`).
- **PostgreSQL 14+** (buat user & database: `docs/database-postgresql.md`).
- Akses **cron** (wajib) dan sebaiknya **SSH**.
- SSL aktif untuk domain (AutoSSL/Let's Encrypt) — aplikasi memaksa HTTPS.

### Pasang
1. **Siapkan paket** — pilih salah satu:
   - Di komputer pengembang: `scripts/build-release.sh` → unggah `dist/koperasi-sekolah-<commit>.zip`
     (sudah berisi `vendor/` dan `public/build/`, tidak perlu Composer/Node di server); atau
   - Di server dengan SSH + Composer + Node: `git clone` repo.
2. Letakkan aplikasi **di luar** `public_html`, mis. `~/koperasi`. Hanya folder `public/` yang boleh
   terbuka ke web:
   - cPanel → **Domains** → ubah *Document Root* domain/subdomain menjadi `koperasi/public`; atau
   - bila tidak bisa diubah: `mv ~/public_html ~/public_html.lama && ln -s ~/koperasi/public ~/public_html`.
3. Konfigurasi:
   ```bash
   cd ~/koperasi
   cp .env.production.example .env
   nano .env   # APP_KEY, APP_URL, DB_* (DB_HOST biasanya 127.0.0.1), LOG_STACK=daily,
               # TRUSTED_PROXIES= (kosong bila tanpa proxy/Cloudflare),
               # KOPERASI_QUEUE_VIA_CRON=true bila tidak bisa menjalankan pekerja permanen
   chmod -R ug+rwX storage bootstrap/cache
   ```
4. Pasang: `scripts/deploy.sh --no-pull` (paket zip) atau `scripts/deploy.sh` (git). Skrip ini menjalankan
   migrasi, isi awal Pusat Panduan, `optimize`, `storage:link`, dan `queue:restart`.
   Tanpa SSH: jalankan perintah yang sama lewat *Terminal* cPanel.
5. Buat admin: `php artisan koperasi:buat-admin`.
6. **Cron** (cPanel → Cron Jobs, setiap menit):
   ```
   * * * * * cd /home/USER/koperasi && php artisan schedule:run >> /dev/null 2>&1
   ```
   Pastikan `php` di cron adalah PHP 8.4+ (di cPanel sering `/usr/local/bin/ea-php84` atau `/opt/cpanel/ea-php84/root/usr/bin/php`).
7. **Pekerja antrean**:
   - Shared hosting: `KOPERASI_QUEUE_VIA_CRON=true` — cron di atas sekaligus memproses antrean tiap menit.
   - VPS tanpa Docker: jalankan pekerja permanen dengan supervisor
     (`/etc/supervisor/conf.d/koperasi.conf`) dan biarkan `KOPERASI_QUEUE_VIA_CRON=false`:
     ```ini
     [program:koperasi-queue]
     command=php /var/www/koperasi/artisan queue:work --queue=notifikasi,ekspor,default,cadangan --tries=3 --timeout=900 --max-time=3600
     user=www-data
     autostart=true
     autorestart=true
     stopwaitsecs=120
     stdout_logfile=/var/www/koperasi/storage/logs/queue.log
     ```
     Konfigurasi Nginx + php-fpm di VPS dapat meniru `docker/nginx.conf` (root ke `public/`,
     hanya `index.php` yang dieksekusi, `client_max_body_size 20m`).

Pembaruan: unggah paket baru lalu `scripts/deploy.sh --no-pull`, atau cukup `scripts/deploy.sh` (git).
**Jangan menimpa** `.env` dan folder `storage/` saat mengunggah versi baru.

---

## 7. Setelah deploy pertama

1. Masuk sebagai admin di `https://domain/masuk`, ganti/periksa profil.
2. **Pengaturan** → isi profil koperasi, logo, QRIS, struk, pajak, jam buka, keamanan & retensi.
3. **Pengguna** → buat akun petugas (kasir, pengurus) dengan peran yang tepat.
4. **Sistem → Kesehatan**: semua komponen harus **Normal** — basis data, cache, penyimpanan, antrean
   (denyut pekerja), penjadwal (denyut tiap menit, muncul ±1 menit setelah start), cadangan.
5. **Sistem → Cadangan** → *Cadangkan sekarang*, lalu **Sistem → Uji pemulihan** → *Mulai uji pemulihan* untuk memastikan
   arsip bisa dipulihkan.
6. Masukkan data barang (impor XLSX di Barang & Stok) dan anggota.

## 8. Cadangan & pemulihan

- **Otomatis di aplikasi**: tiap hari `KOPERASI_BACKUP_TIME` (bawaan 23:00) → zip di
  `storage/app/private/cadangan` berisi `database.json`, `database.sql` (pg_dump), logo & QRIS; disimpan
  `KOPERASI_BACKUP_KEEP` arsip terakhir. Admin diberi peringatan bila tidak ada cadangan berhasil 26 jam.
- **Simpan salinan di luar server** secara berkala: unduh dari **Sistem → Cadangan**, atau salin volume.
- Cadangan manual database (Docker Compose):
  ```bash
  docker compose exec db sh -c 'pg_dump -U "$POSTGRES_USER" -Fc "$POSTGRES_DB"' > koperasi-$(date +%F).dump
  ```
- Pulihkan dari `database.sql` (di dalam zip cadangan aplikasi) ke database kosong:
  ```bash
  docker compose exec -T db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"' < database.sql
  ```
  atau dari dump format `-Fc`: `pg_restore -U koperasi -d koperasi_sekolah --clean --if-exists koperasi.dump`.
  Pulihkan juga isi `storage/app/private` (logo, QRIS, bukti) dan gunakan **APP_KEY yang sama**.

## 9. Penskalaan (opsional)

Satu kontainer `CONTAINER_ROLE=all` cukup untuk satu koperasi sekolah (±1 GB RAM). Bila perlu dipisah,
jalankan image yang sama beberapa kali dengan volume `storage` yang sama:

| Kontainer | `CONTAINER_ROLE` | Catatan |
| --- | --- | --- |
| web (boleh >1) | `web` | menjalankan migrasi saat start (`RUN_MIGRATIONS=false` di replika tambahan) |
| pekerja | `worker` | `QUEUE_WORKERS` = jumlah proses |
| penjadwal | `scheduler` | **tepat satu** kontainer |

Penyetelan lain: `PHP_FPM_MAX_CHILDREN` (bawaan 20 ≈ 20 permintaan bersamaan), `PHP_MEMORY_LIMIT`.

## 10. Pemecahan masalah

| Gejala | Penyebab & solusi |
| --- | --- |
| Kontainer berhenti: "APP_KEY belum diisi" | Isi env `APP_KEY` (§2). |
| "Basis data tidak bisa dihubungi" | Periksa `DB_HOST`/`DB_PASSWORD`; di Compose `DB_HOST=db`. Password berubah setelah volume db dibuat? Password PostgreSQL hanya dipasang saat volume pertama kali dibuat. |
| Redirect berulang / "Gunakan koneksi aman" (426) | Proxy tidak mengirim `X-Forwarded-Proto` atau `TRUSTED_PROXIES` kosong. Di Docker/Coolify isi `*`. |
| 419 *Page Expired* / tidak bisa login | `APP_URL` harus `https://…` dan domain diakses lewat https (cookie sesi `__Host-` hanya lewat https). Uji lokal tanpa TLS: `FORCE_HTTPS=false` + `APP_URL=http://localhost:8080`. |
| 413 saat unggah | Batas proxy di depan < 20 MB (Nginx host: `client_max_body_size 20m`). |
| Kesehatan: "Pekerja antrean tidak aktif" | Docker: lihat log kontainer (`queue_00`). Hosting: supervisor atau `KOPERASI_QUEUE_VIA_CRON=true`. |
| Kesehatan: "Belum ada denyut penjadwal" | Docker: tunggu 1–2 menit setelah start. Hosting: cron `schedule:run` belum terpasang/salah PHP. |
| Cadangan gagal: pg_dump *server version mismatch* | Server PostgreSQL lebih baru dari 18 → perbarui image (`postgresql18-client`) atau set `KOPERASI_BACKUP_PG_DUMP=false`. |
| "Permission denied" di storage | Jalankan perintah lewat `artisan …` (wrapper, berjalan sebagai www-data) atau `docker compose exec -u www-data app php artisan …`; restart kontainer membetulkan kepemilikan berkas. |
| Tampilan tanpa CSS (Vite manifest not found) | Hosting: `public/build` tidak ikut diunggah — pakai paket `build-release.sh` atau `npm run build`. Pastikan tidak ada berkas `public/hot`. |
| Perlu mode perawatan | `artisan down --secret="kode-rahasia"` → petugas tetap bisa masuk lewat `https://domain/kode-rahasia`; selesai: `artisan up`. |

Log: Docker/Coolify → tab **Logs** atau `docker compose logs -f app`. Hosting → `storage/logs/laravel-*.log`.

## 11. Daftar periksa go-live

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` https yang benar
- [ ] `APP_KEY` & `DB_PASSWORD` tersimpan di pengelola sandi
- [ ] Storage persisten (volume Docker / folder di luar web root) dan database persisten
- [ ] HTTPS aktif, http dialihkan ke https, header HSTS terkirim
- [ ] Admin pertama dibuat lewat `koperasi:buat-admin`; tidak ada akun/barang contoh
- [ ] Sistem → Kesehatan semua **Normal** (antrean & penjadwal berjalan)
- [ ] Cadangan pertama berhasil dan uji pemulihan lulus; salinan di luar server dijadwalkan
- [ ] Zona waktu `Asia/Jakarta` (jam transaksi & laporan sesuai)
- [ ] DNS & sertifikat domain sudah benar; kamera pemindai barcode berfungsi (butuh https)
