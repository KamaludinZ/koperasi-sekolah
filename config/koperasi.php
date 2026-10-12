<?php

return [
    /*
    | Sesi petugas otomatis berakhir bila tidak ada aktivitas selama sekian menit
    | (komputer kasir sering dipakai bergantian). 0 = nonaktif.
    */
    'idle_minutes' => (int) env('KOPERASI_IDLE_MINUTES', 60),
];
