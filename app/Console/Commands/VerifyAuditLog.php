<?php

namespace App\Console\Commands;

use App\Services\AuditIntegrity;
use App\Services\AuditVerificationService;
use Illuminate\Console\Command;

/**
 * Periksa keaslian jejak audit (checksum HMAC tiap baris + rantai mulai–selesai sesi impersonasi) dan tandai catatan
 * yang gagal di audit_integrity_failures. Kegagalan baru → notifikasi Admin (sekali per hari) & kode keluar 1.
 * Terjadwal: penuh setiap hari 02.30, --cepat (catatan peran & impersonasi) setiap jam (routes/console.php).
 */
class VerifyAuditLog extends Command
{
    protected $signature = 'audit:verifikasi {--dari= : Hanya periksa checksum mulai id tertentu (tanpa penandaan)} {--cepat : Hanya catatan peran & impersonasi}';

    protected $description = 'Periksa jejak audit tidak diubah langsung di database & tandai catatan yang gagal';

    public function handle(AuditIntegrity $integrity, AuditVerificationService $verifier): int
    {
        if ($this->option('dari')) { // pemeriksaan sebagian manual: hanya laporan, tidak menimpa hasil terakhir
            $r = $integrity->verify((int) $this->option('dari'));
            $this->info("Diperiksa {$r['checked']} baris".($r['checked'] ? " (id {$r['first_id']}–{$r['last_id']})" : '').'.');
            if ($r['invalid'] !== []) {
                $this->error(count($r['invalid']).' baris tidak cocok dengan tandanya: '.implode(', ', array_slice($r['invalid'], 0, 20)));

                return self::FAILURE;
            }
            $this->info('Semua tanda cocok — jejak audit utuh.');

            return self::SUCCESS;
        }

        $s = $verifier->run($this->option('cepat') ? 'cepat' : 'penuh', 'jadwal');
        $this->info("Diperiksa {$s['checked']} baris".($s['checked'] ? " (id {$s['first_id']}–{$s['last_id']})" : '')." dan {$s['chains_checked']} rantai sesi impersonasi.");
        if ($s['unsigned']) {
            $this->warn("{$s['unsigned']} baris belum bertanda (dibuat sebelum penguncian).");
        }
        if ($s['invalid'] === [] && $s['chain_failures'] === 0) {
            $this->info('Semua tanda cocok — jejak audit utuh.');

            return self::SUCCESS;
        }
        if ($s['invalid']) {
            $ids = implode(', ', array_slice($s['invalid'], 0, 20)).(count($s['invalid']) > 20 ? ', …' : '');
            $this->error(count($s['invalid'])." baris tidak cocok dengan tandanya: {$ids}");
        }
        if ($s['chain_failures']) {
            $this->error("{$s['chain_failures']} rantai sesi impersonasi putus.");
        }
        $this->line("Kegagalan baru: {$s['new_failures']} · masih terbuka: {$s['open_failures']}.");

        return self::FAILURE;
    }
}
