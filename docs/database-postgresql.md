# Menyiapkan Database PostgreSQL

Aplikasi memakai PostgreSQL (lihat PRD). Langkah di bawah membuat **user khusus aplikasi**
(`koperasi`) dan **database** (`koperasi_sekolah`) miliknya. Jangan memakai user `postgres`
(superuser) untuk aplikasi.

## 1. Buat user & database (butuh akses admin server)

Masuk ke psql sebagai superuser:

```bash
sudo -u postgres psql
```

Lalu jalankan (ganti `PASSWORD_KUAT` dengan password acak, mis. dari `openssl rand -base64 24`):

```sql
CREATE ROLE koperasi WITH LOGIN PASSWORD 'PASSWORD_KUAT';
CREATE DATABASE koperasi_sekolah OWNER koperasi ENCODING 'UTF8' TEMPLATE template0;
REVOKE ALL ON DATABASE koperasi_sekolah FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE koperasi_sekolah TO koperasi;
\c koperasi_sekolah
ALTER SCHEMA public OWNER TO koperasi;
\q
```

## 2. Isi `.env`

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1        # di Docker/Coolify: nama service/host database
DB_PORT=5432
DB_DATABASE=koperasi_sekolah
DB_USERNAME=koperasi
DB_PASSWORD=PASSWORD_KUAT
```

Ubuntu/Debian secara bawaan mengizinkan login berpassword lewat `127.0.0.1`
(`scram-sha-256` di `pg_hba.conf`). Bila muncul error autentikasi, pastikan ada baris:

```
host    koperasi_sekolah    koperasi    127.0.0.1/32    scram-sha-256
```

lalu `sudo systemctl reload postgresql`.

## 3. Buat tabel & data awal

```bash
php artisan config:clear
php artisan migrate --seed      # di production: php artisan migrate --force
```

`--seed` mengisi kategori & barang contoh; riwayat transaksi contoh hanya dibuat bila `APP_ENV=local`.

## 4. Cek koneksi

```bash
php artisan db:show
```

## Catatan

- **Backup**: `pg_dump -U koperasi -h 127.0.0.1 -Fc koperasi_sekolah > backup.dump`
  (pulihkan dengan `pg_restore -d koperasi_sekolah backup.dump`). Jadwalkan secara berkala.
- **Test otomatis** memakai SQLite in-memory secara bawaan (`phpunit.xml`), jadi tidak
  menyentuh database aplikasi. Untuk menguji di PostgreSQL, buat database terpisah
  (mis. `koperasi_sekolah_test`) dan jalankan `vendor/bin/phpunit` dengan variabel `DB_*` ke sana.
- Jangan commit `.env` — file itu sudah ada di `.gitignore`.
