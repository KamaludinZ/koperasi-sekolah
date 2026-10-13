<?php

namespace App\Livewire\Pengadaan;

use App\Models\SupplierBill;
use App\Services\SupplierBillService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pengadaan → Hutang (Admin & Pengurus): hutang pembelian ke supplier dengan sisa, jatuh tempo
 * (lewat / ≤ 7 hari / nanti), dan status; saring status, supplier, dan rentang jatuh tempo;
 * catat pembayaran (tunai/transfer, maks. sisa hutang) dan lihat riwayat pembayarannya.
 * Data & pembayaran lewat SupplierBillService (tagihan dibuat otomatis saat barang diterima).
 */
#[Layout('components.layouts.app')]
#[Title('Hutang Supplier')]
class Payables extends Component
{
    public const FILTERS = [
        'belum-lunas' => 'Belum lunas',
        'lewat' => 'Lewat jatuh tempo',
        'minggu-ini' => 'Jatuh tempo ≤ 7 hari',
        'lunas' => 'Lunas',
        'semua' => 'Semua',
    ];

    public const METHODS = ['tunai' => 'Tunai', 'transfer' => 'Transfer'];

    #[Url(as: 'status', except: 'belum-lunas')]
    public string $filter = 'belum-lunas';

    #[Url(as: 'supplier', except: '')]
    public string $supplier = '';

    #[Url(as: 'dari', except: '')]
    public string $from = '';

    #[Url(as: 'sampai', except: '')]
    public string $to = '';

    public ?int $payingId = null;

    public ?int $historyId = null;

    public int|string|null $amount = null;

    public string $paidAt = '';

    public string $method = 'transfer';

    public string $reference = '';

    public string $note = '';

    #[Computed]
    public function all(): array
    {
        return app(SupplierBillService::class)->list();
    }

    public static function daysLeft(array $p): int
    {
        return (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($p['due_at']), false);
    }

    private function matches(array $p, string $filter): bool
    {
        $open = $p['status'] !== 'lunas';

        return match ($filter) {
            'belum-lunas' => $open,
            'lewat' => $open && self::daysLeft($p) < 0,
            'minggu-ini' => $open && self::daysLeft($p) >= 0 && self::daysLeft($p) <= 7,
            'lunas' => ! $open,
            default => true,
        };
    }

    #[Computed]
    public function payables(): array
    {
        return collect($this->all)
            ->filter(fn ($p) => $this->matches($p, $this->filter))
            ->when($this->supplier !== '', fn ($c) => $c->where('supplier_id', (int) $this->supplier))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from), fn ($c) => $c->where('due_at', '>=', $this->from))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->to), fn ($c) => $c->where('due_at', '<=', $this->to))
            ->values()->all();
    }

    #[Computed]
    public function counts(): array
    {
        return collect(self::FILTERS)->map(fn ($l, $k) => collect($this->all)->filter(fn ($p) => $this->matches($p, $k))->count())->all();
    }

    #[Computed]
    public function summary(): array
    {
        $open = collect($this->all)->where('status', '!=', 'lunas');
        $remain = fn ($c) => $c->sum(fn ($p) => $p['amount'] - $p['paid']);

        return [
            'outstanding' => $remain($open),
            'overdue' => $remain($open->filter(fn ($p) => self::daysLeft($p) < 0)),
            'week' => $remain($open->filter(fn ($p) => self::daysLeft($p) >= 0 && self::daysLeft($p) <= 7)),
            'paid_month' => app(SupplierBillService::class)->summary()['paid_month'],
        ];
    }

    #[Computed]
    public function supplierOptions(): array
    {
        return collect($this->all)->pluck('supplier', 'supplier_id')->sort()->all();
    }

    #[Computed]
    public function paying(): ?array
    {
        return $this->payingId ? collect($this->all)->firstWhere('id', $this->payingId) : null;
    }

    #[Computed]
    public function history(): ?array
    {
        $p = $this->historyId ? collect($this->all)->firstWhere('id', $this->historyId) : null;

        return $p ? $p + ['payments' => app(SupplierBillService::class)->payments(SupplierBill::findOrFail($p['id']))] : null;
    }

    public function setFilter(string $filter): void
    {
        $this->filter = array_key_exists($filter, self::FILTERS) ? $filter : 'belum-lunas';
    }

    public function resetFilters(): void
    {
        $this->reset('filter', 'supplier', 'from', 'to');
    }

    public function pay(int $id): void
    {
        $p = collect($this->all)->firstWhere('id', $id);
        if (! $p || $p['status'] === 'lunas' || ! \Illuminate\Support\Facades\Gate::allows('kelola-pengadaan')) {
            return;
        }
        $this->payingId = $id;
        $this->amount = $p['amount'] - $p['paid'];
        $this->paidAt = now()->toDateString();
        $this->reset('reference', 'note');
        $this->method = 'transfer';
        $this->resetValidation();
    }

    public function cancelPay(): void
    {
        $this->reset('payingId', 'amount', 'reference', 'note');
        $this->resetValidation();
    }

    public function savePayment(): void
    {
        \Illuminate\Support\Facades\Gate::authorize('kelola-pengadaan');
        $p = $this->paying;
        if (! $p) {
            return;
        }
        $remaining = $p['amount'] - $p['paid'];
        try {
            $this->validate([
                'amount' => ['required', 'integer', 'min:1', 'max:'.$remaining],
                'paidAt' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                'method' => ['required', 'in:'.implode(',', array_keys(self::METHODS))],
                'reference' => ['nullable', 'string', 'max:50'],
                'note' => ['nullable', 'string', 'max:200'],
            ], [
                'amount.required' => 'Isi jumlah yang dibayar.',
                'amount.min' => 'Jumlah minimal Rp1.',
                'amount.max' => 'Melebihi sisa hutang (Rp'.number_format($remaining, 0, ',', '.').').',
                'paidAt.before_or_equal' => 'Tanggal bayar tidak boleh setelah hari ini.',
            ]);
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'error', message: 'Pembayaran belum tersimpan: periksa isian.');

            throw $e;
        }

        app(SupplierBillService::class)->pay([$p['id'] => (int) $this->amount], ['paid_at' => $this->paidAt, 'method' => $this->method,
            'reference' => trim($this->reference) ?: null, 'note' => trim($this->note) ?: null], auth()->user());
        $lunas = (int) $this->amount === $remaining;
        $this->cancelPay();
        unset($this->all, $this->payables, $this->counts, $this->summary);
        $this->dispatch('toast', type: 'success', message: $lunas ? "Hutang {$p['po_number']} ke {$p['supplier']} lunas." : "Pembayaran {$p['po_number']} dicatat.");
    }

    public function showHistory(int $id): void
    {
        $this->historyId = $id;
    }

    public function closeHistory(): void
    {
        $this->reset('historyId', 'cancelPaymentId', 'cancelPaymentReason');
        $this->resetValidation();
    }

    public ?int $cancelPaymentId = null;

    public string $cancelPaymentReason = '';

    #[Computed]
    public function canPay(): bool
    {
        return \Illuminate\Support\Facades\Gate::allows('kelola-pengadaan');
    }

    #[Computed]
    public function isAdmin(): bool
    {
        return (bool) auth()->user()?->hasRole(\App\Models\Role::ADMIN);
    }

    /** Batalkan pembayaran salah input (khusus Admin, wajib alasan). */
    public function cancelPayment(): void
    {
        $payment = $this->isAdmin && $this->cancelPaymentId ? \App\Models\SupplierPayment::find($this->cancelPaymentId) : null;
        if (! $payment) {
            return;
        }
        $this->validate(['cancelPaymentReason' => ['required', 'string', 'min:3', 'max:200']],
            ['cancelPaymentReason.required' => 'Tulis alasan pembatalan.', 'cancelPaymentReason.min' => 'Alasan minimal 3 huruf.']);
        app(SupplierBillService::class)->cancelPayment($payment, $this->cancelPaymentReason);
        $this->reset('cancelPaymentId', 'cancelPaymentReason');
        unset($this->all, $this->payables, $this->counts, $this->summary, $this->history);
        $this->dispatch('toast', type: 'success', message: 'Pembayaran dibatalkan dan sisa hutang dihitung ulang.');
    }

    public function render()
    {
        return view('livewire.pengadaan.payables');
    }
}
