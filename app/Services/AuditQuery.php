<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Pencarian & ringkasan jejak audit untuk halaman Audit & Riwayat (admin & pengurus):
 * filter tampilan (semua, login, sirkulasi uang/barang, perubahan data), periode,
 * kategori, pelaku, dan kata kunci; ringkasan aktivitas & per petugas.
 */
class AuditQuery
{
    public const VIEWS = [
        'semua' => 'Semua aktivitas',
        'login' => 'Aktivitas login',
        'sirkulasi' => 'Sirkulasi uang & barang',
        'data' => 'Perubahan data',
    ];

    public const PERIODS = [
        'hari-ini' => 'Hari ini',
        '7-hari' => '7 hari',
        '30-hari' => '30 hari',
        'semua' => 'Semua',
    ];

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    public static function range(string $period, ?string $from = null, ?string $to = null): array
    {
        if (($from ?? '') !== '' || ($to ?? '') !== '') {
            return [
                ($from ?? '') !== '' ? Carbon::createFromFormat('!Y-m-d', $from)->startOfDay() : null,
                ($to ?? '') !== '' ? Carbon::createFromFormat('!Y-m-d', $to)->endOfDay() : null,
            ];
        }

        return match ($period) {
            'hari-ini' => [now()->startOfDay(), null],
            '7-hari' => [now()->subDays(6)->startOfDay(), null],
            '30-hari' => [now()->subDays(29)->startOfDay(), null],
            default => [null, null],
        };
    }

    /**
     * @param  array{view?: string, from?: ?Carbon, to?: ?Carbon, category?: string, actor?: string, search?: string}  $f
     */
    public function query(array $f = []): Builder
    {
        $term = mb_strtolower(trim($f['search'] ?? ''));

        return ActivityLog::query()
            ->betweenDates($f['from'] ?? null, $f['to'] ?? null)
            ->when(($f['view'] ?? 'semua') === 'login', fn ($q) => $q->where('category', 'auth'))
            ->when(($f['view'] ?? 'semua') === 'sirkulasi', fn ($q) => $q->whereIn('category', ActivityLog::CIRCULATION))
            ->when(($f['view'] ?? 'semua') === 'data', fn ($q) => $q->where(fn (Builder $w) => $w
                ->whereIn('category', ['barang', 'anggota', 'pengguna', 'laporan'])
                ->orWhere(fn (Builder $s) => $s->where('category', 'stok')->where('event', '!=', 'stock.adjusted'))))
            ->when(($f['category'] ?? '') !== '', fn ($q) => $q->where('category', $f['category']))
            ->when(($f['actor'] ?? '') !== '', fn ($q) => $q->where('actor_name', $f['actor']))
            ->when($term !== '', function (Builder $q) use ($term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw("LOWER(description) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(subject_label) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(actor_name) LIKE ? ESCAPE '\\'", [$like]));
            })
            ->latestFirst();
    }

    /** Ringkasan pada rentang: jumlah aktivitas, login berhasil/gagal, penjualan, tabungan, penyesuaian stok. */
    public function summary(?Carbon $from, ?Carbon $to): array
    {
        $rows = ActivityLog::query()->betweenDates($from, $to)->toBase()
            ->groupBy('event')
            ->selectRaw('event, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amount')
            ->get()->keyBy('event');
        $n = fn (string $e) => (int) ($rows[$e]->n ?? 0);
        $sum = fn (string $e) => (int) ($rows[$e]->amount ?? 0);

        return [
            'total' => (int) $rows->sum('n'),
            'logins' => $n('auth.login'),
            'failed_logins' => $n('auth.failed') + $n('auth.locked') + $n('auth.inactive'),
            'sales' => $n('sale.created'),
            'sales_amount' => $sum('sale.created'),
            'deposits' => $sum('savings.deposit'),
            'withdrawals' => $sum('savings.withdraw'),
            'stock_changes' => $n('stock.adjusted') + $n('stock.min_updated'),
            'reprints' => $n('receipt.reprinted'),
        ];
    }

    /**
     * Aktivitas per petugas pada rentang: jumlah aksi, penjualan, login terakhir, aktivitas terakhir.
     *
     * @return list<array{name: string, role: ?string, actions: int, sales: int, sales_amount: int, last_login: ?string, last_at: ?string, failed: int}>
     */
    public function perActor(?Carbon $from, ?Carbon $to): array
    {
        return ActivityLog::query()->betweenDates($from, $to)->toBase()
            ->whereNotNull('actor_name')
            ->groupBy('actor_name', 'actor_role')
            ->selectRaw("actor_name, actor_role, COUNT(*) AS actions,
                SUM(CASE WHEN event = 'sale.created' THEN 1 ELSE 0 END) AS sales,
                COALESCE(SUM(CASE WHEN event = 'sale.created' THEN amount ELSE 0 END), 0) AS sales_amount,
                SUM(CASE WHEN event IN ('auth.failed', 'auth.locked', 'auth.inactive') THEN 1 ELSE 0 END) AS failed,
                MAX(CASE WHEN event = 'auth.login' THEN created_at END) AS last_login,
                MAX(created_at) AS last_at")
            ->orderByDesc('last_at')
            ->get()
            ->map(fn ($r) => [
                'name' => $r->actor_name,
                'role' => $r->actor_role,
                'actions' => (int) $r->actions,
                'sales' => (int) $r->sales,
                'sales_amount' => (int) $r->sales_amount,
                'failed' => (int) $r->failed,
                'last_login' => $r->last_login ? (string) $r->last_login : null,
                'last_at' => $r->last_at ? (string) $r->last_at : null,
            ])
            ->all();
    }

    /** @return list<string> */
    public function actors(): array
    {
        return ActivityLog::query()->whereNotNull('actor_name')->distinct()->orderBy('actor_name')->pluck('actor_name')->all();
    }
}
