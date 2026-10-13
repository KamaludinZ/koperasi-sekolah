<?php

// Hanya bagian yang diubah dari bawaan Livewire; sisanya memakai konfigurasi paket.
return [
    /*
    | Unggahan sementara Livewire: batas kasar 10 MB (batas unggah terbesar di pengaturan
    | Keamanan & retensi); jenis & ukuran sebenarnya diperiksa UploadPolicy saat disimpan.
    | Pratinjau hanya untuk gambar raster (tanpa SVG).
    */
    'temporary_file_upload' => [
        'disk' => null,
        'rules' => ['required', 'file', 'max:10240'],
        'directory' => null,
        'middleware' => 'throttle:30,1',
        'preview_mimes' => ['png', 'jpg', 'jpeg', 'webp'],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],
];
