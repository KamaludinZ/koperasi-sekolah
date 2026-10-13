<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * Query riwayat transaksi: filter rentang tanggal, metode bayar & nomor invoice,
 * urutan terbaru dulu, paginasi, bentuk baris ringkas untuk daftar,
 * serta detail lengkap satu transaksi beserta tetangganya (lebih baru/lama).
 */
class TransactionHistory
{
    public function __construct(private ReceiptPresenter $receipt) {}

    public const PER_PAGE_OPTIONS = [20, 50, 100];

    public const PRESETS = [
        'semua' => 'Semua',
        'hari-ini' => 'Hari ini',
        'kemarin' => 'Kemarin',
        '7-hari' => '7 hari terakhir',
        'bulan-ini' => 'Bulan ini',
    ];

    /**
     * @param  array{from?: ?Carbon, to?: ?Carbon, method?: ?string, search?: ?string}  $filters
     */
    public function query(array $filters = []): Builder
    {
        $term = strtoupper(trim($filters['search'] ?? ''));

        return Transaction::query()
            ->with(['user:id,name', 'member:id,member_code,name,type,class', 'savingsEntry', 'credit'])
            ->betweenDates($filters['from'] ?? null, $filters['to'] ?? null)
            ->paymentMethod($filters['method'] ?? null)
            ->when($term !== '', function (Builder $q) use ($term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $q->whereRaw("UPPER(invoice_number) LIKE ? ESCAPE '\\'", [$like]);
            })
            ->latestFirst();
    }

    public function paginate(array $filters, int $perPage = 20, ?int $page = null): LengthAwarePaginator
    {
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::PER_PAGE_OPTIONS[0];
        $paginator = $this->query($filters)->paginate($perPage, ['*'], 'page', $page);

        // Halaman di luar jangkauan (mis. setelah filter) → pakai halaman terakhir.
        if ($paginator->isEmpty() && $paginator->currentPage() > 1 && $paginator->total() > 0) {
            $paginator = $this->query($filters)->paginate($perPage, ['*'], 'page', $paginator->lastPage());
        }

        return $paginator->through(fn (Transaction $t) => self::toRow($t));
    }

    /** Baris ringkas (tanpa item) — bentuk sama dengan data tiruan halaman Riwayat. */
    public static function toRow(Transaction $t): array
    {
        return [
            'id' => $t->id,
            'invoice_number' => $t->invoice_number,
            'created_at' => $t->created_at->toDateTimeString(),
            'cashier' => $t->user?->name ?? 'Kasir',
            'cashier_id' => $t->user_id,
            'member' => $t->member?->display_name,
            'member_id' => $t->member_id,
            'member_code' => $t->member?->member_code,
            'item_count' => $t->item_count,
            'subtotal' => (int) $t->subtotal,
            'tax' => (int) $t->tax,
            'tax_label' => $t->tax_label,
            'tax_rate' => (float) $t->tax_rate,
            'tax_included' => (int) $t->tax_included,
            'rounding' => (int) $t->rounding,
            'discount' => (int) $t->discount,
            'total' => (int) $t->total,
            'paid' => (int) $t->paid,
            'change' => (int) $t->change,
            'payment_method' => $t->payment_method,
            'payment_method_label' => CheckoutService::PAYMENT_LABELS[$t->payment_method] ?? $t->payment_method,
            // Saldo tabungan setelah transaksi (metode tabungan) dan bon yang tercatat (metode bon).
            'balance_after' => $t->payment_method === 'tabungan' && $t->relationLoaded('savingsEntry') && $t->savingsEntry ? (int) round((float) $t->savingsEntry->balance_after) : null,
            'credit' => $t->payment_method === 'bon' && $t->relationLoaded('credit') && $t->credit
                ? ['number' => $t->credit->number, 'due_at' => $t->credit->due_at->toDateString(), 'status' => $t->credit->status, 'remaining' => $t->credit->remaining()]
                : null,
        ];
    }

    /**
     * Detail lengkap: data struk + item (dengan kode barang), anggota,
     * id transaksi lebih baru/lama (urutan sama dengan daftar), dan tautan struk.
     */
    public function detail(Transaction $t): array
    {
        $t->loadMissing(['items.product:id,code', 'user:id,name', 'member:id,member_code,name,type,class']);
        $receipt = $this->receipt->present($t);
        $codes = $t->items->mapWithKeys(fn ($item) => [$item->id => $item->product?->code]);

        return [
            ...$receipt,
            'items' => $t->items->values()->map(fn ($item, $i) => [
                ...$receipt['items'][$i],
                'product_code' => $codes[$item->id],
            ])->all(),
            'item_count' => $t->item_count,
            'member' => $t->member?->display_name,
            'member_id' => $t->member_id,
            'newer_id' => $this->neighbor($t, newer: true),
            'older_id' => $this->neighbor($t, newer: false),
            'links' => [
                'receipt' => route('kasir.transactions.receipt', $t),
                'receipt_pdf' => route('kasir.transactions.receipt.pdf', $t),
            ],
        ];
    }

    /** Transaksi bersebelahan menurut urutan (created_at, id). */
    private function neighbor(Transaction $t, bool $newer): ?int
    {
        $op = $newer ? '>' : '<';

        return Transaction::query()
            ->where(fn (Builder $q) => $q
                ->where('created_at', $op, $t->created_at)
                ->orWhere(fn (Builder $w) => $w->where('created_at', $t->created_at)->where('id', $op, $t->id)))
            ->orderBy('created_at', $newer ? 'asc' : 'desc')
            ->orderBy('id', $newer ? 'asc' : 'desc')
            ->value('id');
    }

    /**
     * Rentang waktu (awal hari, akhir hari) untuk preset atau tanggal kustom (Y-m-d).
     * Tanggal kustom diutamakan bila diisi; batas null = terbuka.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public static function range(string $preset = 'semua', ?string $from = null, ?string $to = null): array
    {
        if (($from ?? '') !== '' || ($to ?? '') !== '') {
            return [self::parseDate($from)?->startOfDay(), self::parseDate($to)?->endOfDay()];
        }

        $today = now();

        return match ($preset) {
            'hari-ini' => [$today->copy()->startOfDay(), $today->copy()->endOfDay()],
            'kemarin' => [$today->copy()->subDay()->startOfDay(), $today->copy()->subDay()->endOfDay()],
            '7-hari' => [$today->copy()->subDays(6)->startOfDay(), $today->copy()->endOfDay()],
            'bulan-ini' => [$today->copy()->startOfMonth(), $today->copy()->endOfDay()],
            default => [null, null],
        };
    }

    /**
     * Ringkasan penjualan dalam rentang (agregat SQL): jumlah, total, barang terjual,
     * rata-rata, rincian per metode bayar, dan — bila rentang lengkap — perbandingan
     * dengan periode sebelumnya yang sama panjangnya.
     */
    public function summary(?Carbon $start, ?Carbon $end, ?string $method = null): array
    {
        $summary = $this->aggregate($start, $end, $method);
        $summary['previous'] = null;

        if ($start && $end) {
            $days = (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1;
            $prevEnd = $start->copy()->subDay()->endOfDay();
            $prevStart = $start->copy()->subDays($days)->startOfDay();
            $previous = $this->aggregate($prevStart, $prevEnd, $method);

            $summary['previous'] = [
                ...$previous,
                'from' => $prevStart->toDateString(),
                'to' => $prevEnd->toDateString(),
                'label' => $days === 1 ? $prevStart->translatedFormat('d M') : $prevStart->translatedFormat('d M').' – '.$prevEnd->translatedFormat('d M'),
                'count_change' => self::percentChange($previous['count'], $summary['count']),
                'total_change' => self::percentChange($previous['total'], $summary['total']),
            ];
        }

        return $summary;
    }

    /** Label rentang untuk ditampilkan, mis. "12 Oktober 2026" atau "01 Okt 2026 – 12 Okt 2026". */
    public static function rangeLabel(?Carbon $start, ?Carbon $end): ?string
    {
        if (! $start && ! $end) {
            return null;
        }
        if ($start && $end && $start->isSameDay($end)) {
            return $start->translatedFormat('d F Y');
        }

        return ($start ? $start->translatedFormat('d M Y') : '…').' – '.($end ? $end->translatedFormat('d M Y') : '…');
    }

    private function aggregate(?Carbon $start, ?Carbon $end, ?string $method): array
    {
        $rows = Transaction::query()
            ->betweenDates($start, $end)
            ->paymentMethod($method)
            ->groupBy('payment_method')
            ->selectRaw('payment_method, COUNT(*) AS tx_count, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(item_count), 0) AS items, COALESCE(SUM(discount), 0) AS discount, SUM(CASE WHEN discount > 0 THEN 1 ELSE 0 END) AS discounted')
            ->get()
            ->keyBy('payment_method');

        $count = (int) $rows->sum('tx_count');
        $total = (int) round((float) $rows->sum('total'));

        return [
            'count' => $count,
            'total' => $total,
            'items' => (int) $rows->sum('items'),
            'discount' => (int) round((float) $rows->sum('discount')),
            'discounted' => (int) $rows->sum('discounted'),
            'average' => $count ? (int) round($total / $count) : 0,
            'by_method' => collect(CheckoutService::PAYMENT_LABELS)
                ->map(function ($label, $key) use ($rows, $total) {
                    $sum = (int) round((float) ($rows[$key]->total ?? 0));

                    return [
                        'label' => $label,
                        'count' => (int) ($rows[$key]->tx_count ?? 0),
                        'total' => $sum,
                        'share' => $total > 0 ? (int) round($sum / $total * 100) : 0,
                    ];
                })
                // Tunai & QRIS selalu tampil; tabungan/bon hanya bila dipakai.
                ->filter(fn ($m, $key) => isset(CheckoutService::PAYMENT_METHODS[$key]) || $m['count'] > 0)
                ->all(),
        ];
    }

    /** Persentase perubahan; null bila pembanding nol. */
    private static function percentChange(int|float $before, int|float $after): ?int
    {
        return $before > 0 ? (int) round(($after - $before) / $before * 100) : null;
    }

    private static function parseDate(?string $value): ?Carbon
    {
        if (($value ?? '') === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
