<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Member;
use App\Models\Notification;
use App\Models\PosOfflineCatalog;
use App\Models\Product;
use App\Models\ReportExport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Pembersihan data yang melewati masa retensi (pengaturan Keamanan & retensi):
 * - barang, kategori, anggota yang dihapus lunak lebih dari `trash_days` → dihapus permanen
 *   (tercatat `*.purged` di audit; anggota yang masih punya riwayat tabungan dan kategori yang
 *   masih dipakai barang dilewati);
 * - berkas ekspor laporan lebih dari `export_days` (baris + berkas di disk privat);
 * - impor barang yang tak kunjung disimpan > 30 hari → dibatalkan & berkasnya dihapus;
 * - notifikasi lebih dari `notification_days` yang sudah dibaca/ditutup petugas
 *   (pengumuman yang masih berlaku tidak dihapus).
 * - salinan katalog kasir offline yang kedaluwarsa lebih dari 30 hari.
 * Jejak audit tidak pernah dibersihkan. Dijalankan lewat `php artisan retensi:bersihkan`.
 */
class RetentionService
{
    public const REASON = 'Masa simpan data terhapus habis';

    public const LABELS = [
        'barang' => 'Barang terhapus',
        'kategori' => 'Kategori terhapus',
        'anggota' => 'Anggota terhapus',
        'kategori_dilewati' => 'Kategori dilewati (masih dipakai barang)',
        'anggota_dilewati' => 'Anggota dilewati (punya riwayat tabungan)',
        'ekspor' => 'Berkas ekspor',
        'impor' => 'Impor barang terbengkalai (dibatalkan)',
        'notifikasi' => 'Notifikasi lama',
        'katalog_offline' => 'Salinan katalog kasir offline kedaluwarsa',
    ];

    /**
     * @return array<string, int> jumlah per jenis (dry run: yang akan dibersihkan)
     */
    public function run(bool $dryRun = false): array
    {
        $members = Member::expiredTrash()->withExists('savings')->get();
        $products = Product::expiredTrash()->get();
        // Barang yang ikut dibersihkan pada putaran ini tidak dihitung sebagai pemakai kategori.
        $categories = Category::expiredTrash()
            ->withExists(['products' => fn ($q) => $q->withTrashed()->whereNotIn('id', $products->modelKeys())])->get();
        $counts = [
            // Barang lebih dulu: kategori yang dibersihkan melepas kategori barangnya.
            'barang' => $this->purge($products, $dryRun),
            'kategori' => $this->purge($categories->where('products_exists', false), $dryRun),
            'kategori_dilewati' => $categories->where('products_exists', true)->count(),
            'anggota' => $this->purge($members->where('savings_exists', false), $dryRun),
            'anggota_dilewati' => $members->where('savings_exists', true)->count(),
            'ekspor' => $this->purgeExports($dryRun),
            'impor' => app(ProductImportService::class)->abandonStale($dryRun),
            'notifikasi' => $this->purgeNotifications($dryRun),
            'katalog_offline' => $this->purgeOfflineCatalogs($dryRun),
        ];

        $purged = array_sum(array_diff_key($counts, ['anggota_dilewati' => 0, 'kategori_dilewati' => 0]));
        if (! $dryRun && $purged > 0) {
            AuditLog::record('retention.purged', 'pengaturan',
                'Pembersihan masa retensi: '.collect($counts)->filter()->map(fn ($n, $k) => mb_strtolower(self::LABELS[$k]).' '.$n)->implode(', '),
                null, ['counts' => $counts, 'settings' => app(SettingsService::class)->security()]);
        }

        return $counts;
    }

    /** @param  iterable<Model>  $models */
    private function purge(iterable $models, bool $dryRun): int
    {
        $n = 0;
        foreach ($models as $model) {
            if (! $dryRun) {
                DB::transaction(fn () => AuditLog::withReason(self::REASON, fn () => $model->forceDelete()));
            }
            $n++;
        }

        return $n;
    }

    private function purgeExports(bool $dryRun): int
    {
        $exports = (new ReportExport)->prunable()->get();
        if (! $dryRun) {
            $exports->each->prune();
        }

        return $exports->count();
    }

    /** Salinan katalog kasir offline yang kedaluwarsa lebih dari 30 hari (penjualan dengan salinan itu sudah tak diterima). */
    private function purgeOfflineCatalogs(bool $dryRun): int
    {
        $query = (new PosOfflineCatalog)->prunable();

        return $dryRun ? $query->count() : $query->delete();
    }

    private function purgeNotifications(bool $dryRun): int
    {
        $days = (int) app(SettingsService::class)->security()['notification_days'];
        $query = Notification::query()
            ->where('created_at', '<', now()->subDays($days))
            ->whereExists(fn ($q) => $q->from('notification_reads')->whereColumn('notification_reads.notification_id', 'notifications.id')
                ->where(fn ($w) => $w->whereNotNull('read_at')->orWhereNotNull('dismissed_at')))
            ->where(fn ($q) => $q->where('type', '!=', 'pengumuman')
                ->orWhere(fn ($p) => $p->whereNotNull('expires_at')->where('expires_at', '<', now()->toDateString())));

        return $dryRun ? $query->count() : $query->delete();
    }
}
