<?php

return [
    /*
    | Sesi petugas otomatis berakhir bila tidak ada aktivitas selama sekian menit
    | (komputer kasir sering dipakai bergantian). Diatur Admin di Pengaturan → Keamanan & retensi;
    | isi KOPERASI_IDLE_MINUTES hanya untuk menimpanya di server (0 = nonaktif).
    */
    'idle_minutes' => env('KOPERASI_IDLE_MINUTES'),

    // Toleransi (hari) sebelum angsuran pinjaman yang lewat jatuh tempo dianggap menunggak (0–30). Lihat App\Support\LoanOverdue.
    'loan_grace_days' => (int) env('KOPERASI_PINJAMAN_TOLERANSI_HARI', 0),

    /*
    | Paksa HTTPS: permintaan http dialihkan ke https, URL dibuat dengan https, dan header HSTS
    | dikirim. Bawaan: aktif di production. Matikan (FORCE_HTTPS=false) hanya bila TLS ditangani
    | di luar aplikasi tanpa meneruskan X-Forwarded-Proto.
    */
    'force_https' => (bool) env('FORCE_HTTPS', env('APP_ENV') === 'production'),

    // Lama browser mengingat "selalu HTTPS" (detik); 0 = tidak mengirim HSTS.
    'hsts_max_age' => (int) env('HSTS_MAX_AGE', 31536000),

    // Alamat yang tetap boleh http (mis. pemeriksaan kesehatan dari load balancer di jaringan dalam).
    'https_except' => ['up'],

    // Proxy tepercaya (mis. Nginx/Cloudflare) agar skema asli dibaca dari X-Forwarded-Proto.
    // "*" = semua, atau daftar IP dipisah koma. Kosong = tidak ada.
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    // Cadangan data otomatis: jam harian, jumlah arsip berhasil yang disimpan, dan pg_dump (PostgreSQL).
    'backup' => [
        'enabled' => (bool) env('KOPERASI_BACKUP_ENABLED', true),
        'time' => env('KOPERASI_BACKUP_TIME', '23:00'),
        'keep' => (int) env('KOPERASI_BACKUP_KEEP', 14),
        'pg_dump' => (bool) env('KOPERASI_BACKUP_PG_DUMP', true),
    ],

    /*
     | Nama antrean pekerjaan latar. Jalankan pekerja dengan urutan prioritas:
     |   php artisan queue:work --queue=notifikasi,ekspor,default,cadangan --tries=3 --max-time=3600
     */
    'queues' => [
        'notifikasi' => 'notifikasi',
        'ekspor' => 'ekspor',
        'default' => 'default',
        'cadangan' => 'cadangan',
    ],
    'queue_worker' => '--queue=notifikasi,ekspor,default,cadangan',
    // Catatan pekerjaan gagal disimpan sekian jam sebelum dibersihkan otomatis.
    'failed_jobs_hours' => (int) env('KOPERASI_FAILED_JOBS_HOURS', 24 * 30),
];
