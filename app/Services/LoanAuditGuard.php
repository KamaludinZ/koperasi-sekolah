<?php

namespace App\Services;

use App\Models\Notification;
use Illuminate\Support\Facades\Cache;

/**
 * Penegak anti-manipulasi log audit pinjaman: memeriksa rantai HMAC (LoanAuditChain::verify), menyimpan hasil
 * pemeriksaan terakhir (ditampilkan di halaman Jejak audit), dan bila rantai tidak utuh mencatat
 * `pinjaman.audit_tidak_utuh` di jejak audit serta mengirim notifikasi ke Admin & Pengurus (sekali per hari per temuan).
 * Dijalankan terjadwal (`pinjaman:verifikasi-audit`), dari tombol "Periksa sekarang", dan lewat endpoint.
 */
class LoanAuditGuard
{
    public const CACHE_KEY = 'pinjaman:audit:verifikasi';

    public function __construct(private LoanAuditChain $chain) {}

    /** @return array{valid: bool, checked: int, broken: int, tampered: int, missing: int, unchained: int, checked_at: string, details: array} */
    public function check(bool $notify = true): array
    {
        $r = $this->chain->verify();
        $summary = ['valid' => $r['valid'], 'checked' => $r['checked'], 'broken' => count($r['broken']), 'tampered' => count($r['tampered']),
            'missing' => count($r['missing']), 'unchained' => count($r['unchained']), 'checked_at' => now()->toDateTimeString(),
            'details' => ['broken' => array_slice($r['broken'], 0, 50), 'tampered' => array_slice($r['tampered'], 0, 50),
                'missing' => array_slice($r['missing'], 0, 50), 'unchained' => array_slice($r['unchained'], 0, 50)]];
        Cache::forever(self::CACHE_KEY, $summary);

        if (! $r['valid'] && $notify) {
            $fingerprint = substr(hash('sha256', json_encode($summary['details'])), 0, 16);
            $problem = self::describe($summary);
            foreach (['admin', 'pengurus'] as $target) {
                Notification::firstOrCreate(['dedupe_key' => "pinjaman:audit:{$target}:".now()->toDateString().":{$fingerprint}"], [
                    'type' => 'sistem', 'target' => $target, 'title' => 'Jejak audit pinjaman terindikasi diubah',
                    'message' => $problem.' Periksa akses langsung ke database.', 'url' => route('pinjaman.audit', [], false),
                ]);
            }
            if (! \App\Models\ActivityLog::where('event', 'pinjaman.audit_tidak_utuh')->where('properties->fingerprint', $fingerprint)->exists()) {
                AuditLog::record('pinjaman.audit_tidak_utuh', 'pinjaman', 'Rantai jejak audit pinjaman tidak utuh: '.$problem, null,
                    ['fingerprint' => $fingerprint] + $summary['details']);
            }
        }

        return $summary;
    }

    /** Hasil pemeriksaan terakhir (null bila belum pernah). */
    public static function last(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    public static function describe(array $s): string
    {
        return collect(['tampered' => 'baris diubah', 'missing' => 'baris hilang', 'broken' => 'mata rantai putus/palsu', 'unchained' => 'baris tanpa rantai'])
            ->filter(fn ($label, $k) => $s[$k] > 0)->map(fn ($label, $k) => $s[$k].' '.$label)->join(', ') ?: 'utuh';
    }
}
