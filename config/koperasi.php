<?php

return [
    /*
    | Sesi petugas otomatis berakhir bila tidak ada aktivitas selama sekian menit
    | (komputer kasir sering dipakai bergantian). Diatur Admin di Pengaturan → Keamanan & retensi;
    | isi KOPERASI_IDLE_MINUTES hanya untuk menimpanya di server (0 = nonaktif).
    */
    'idle_minutes' => env('KOPERASI_IDLE_MINUTES'),

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
];
