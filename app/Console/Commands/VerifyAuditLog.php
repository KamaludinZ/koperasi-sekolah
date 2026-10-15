<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Services\AuditIntegrity;
use App\Support\AuditVerificationStatus;
use Illuminate\Console\Command;

/**
 * Periksa keaslian jejak audit (checksum tiap baris). Bila ada baris yang tidak cocok,
 * kirim notifikasi sistem untuk Admin (sekali per hari) dan keluar dengan kode 1.
 * Dijadwalkan setiap hari (routes/console.php).
 */
class VerifyAuditLog extends Command
{
    protected $signature = 'audit:verifikasi {--dari= : Mulai dari id baris tertentu}';

    protected $description = 'Periksa jejak audit tidak diubah langsung di database';

    public function handle(AuditIntegrity $integrity): int
    {
        $r = $integrity->verify($this->option('dari') ? (int) $this->option('dari') : null);
        if (! $this->option('dari')) { // hanya pemeriksaan penuh yang menjadi "hasil terakhir"
            AuditVerificationStatus::store($r, 'jadwal');
        }
        $this->info("Diperiksa {$r['checked']} baris".($r['checked'] ? " (id {$r['first_id']}–{$r['last_id']})" : '').'.');
        if ($r['unsigned']) {
            $this->warn("{$r['unsigned']} baris belum bertanda (dibuat sebelum penguncian).");
        }

        if ($r['invalid'] === []) {
            $this->info('Semua tanda cocok — jejak audit utuh.');

            return self::SUCCESS;
        }

        $ids = implode(', ', array_slice($r['invalid'], 0, 20)).(count($r['invalid']) > 20 ? ', …' : '');
        $this->error(count($r['invalid'])." baris tidak cocok dengan tandanya: {$ids}");

        Notification::firstOrCreate(['dedupe_key' => 'audit:integritas:'.now()->toDateString()], [
            'type' => 'sistem',
            'target' => 'admin',
            'title' => 'Jejak audit terindikasi diubah',
            'message' => count($r['invalid'])." baris jejak audit tidak cocok dengan tanda keasliannya (id {$ids}). Periksa akses langsung ke database.",
            'url' => route('audit', [], false),
        ]);

        return self::FAILURE;
    }
}
