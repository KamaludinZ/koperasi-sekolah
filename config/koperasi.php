<?php

return [
    /*
    | Sesi petugas otomatis berakhir bila tidak ada aktivitas selama sekian menit
    | (komputer kasir sering dipakai bergantian). Diatur Admin di Pengaturan → Keamanan & retensi;
    | isi KOPERASI_IDLE_MINUTES hanya untuk menimpanya di server (0 = nonaktif).
    */
    'idle_minutes' => env('KOPERASI_IDLE_MINUTES'),
];
