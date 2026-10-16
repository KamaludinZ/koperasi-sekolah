<?php

namespace App\Services;

/** Penyusunan ulang panduan lain sedang berjalan (kunci guide sync dipegang proses lain). */
class GuideSyncInProgress extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Sinkronisasi panduan lain sedang berjalan. Coba lagi sebentar lagi.');
    }
}
