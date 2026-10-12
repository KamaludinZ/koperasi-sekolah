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

    /**
     * @param  array{from?: ?Carbon, to?: ?Carbon, method?: ?string, search?: ?string}  $filters
     */
    public function query(array $filters = []): Builder
    {
        $term = strtoupper(trim($filters['search'] ?? ''));

        return Transaction::query()
            ->with('user:id,name')
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
            'member' => null, // tabel anggota menyusul di fitur Anggota & Tabungan
            'item_count' => $t->item_count,
            'subtotal' => (int) $t->subtotal,
            'tax' => (int) $t->tax,
            'discount' => (int) $t->discount,
            'total' => (int) $t->total,
            'paid' => (int) $t->paid,
            'change' => (int) $t->change,
            'payment_method' => $t->payment_method,
            'payment_method_label' => CheckoutService::PAYMENT_METHODS[$t->payment_method] ?? $t->payment_method,
        ];
    }

    /**
     * Detail lengkap: data struk + item (dengan kode barang), anggota,
     * id transaksi lebih baru/lama (urutan sama dengan daftar), dan tautan struk.
     */
    public function detail(Transaction $t): array
    {
        $t->loadMissing(['items.product:id,code', 'user:id,name']);
        $receipt = $this->receipt->present($t);
        $codes = $t->items->mapWithKeys(fn ($item) => [$item->id => $item->product?->code]);

        return [
            ...$receipt,
            'items' => $t->items->values()->map(fn ($item, $i) => [
                ...$receipt['items'][$i],
                'product_code' => $codes[$item->id],
            ])->all(),
            'item_count' => $t->item_count,
            'member' => null, // tabel anggota menyusul di fitur Anggota & Tabungan
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
}
