<?php

namespace App\Livewire\Portal;

use App\Models\Transaction;
use App\Services\CheckoutService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portal anggota → Riwayat belanja: transaksi kasir milik anggota yang masuk (terbaru dulu) dengan
 * saringan periode, penanda dibatalkan/retur, dan rincian barang di panel. Transaksi anggota lain → 404.
 */
#[Layout('components.layouts.portal')]
#[Title('Riwayat Belanja')]
class Purchases extends Component
{
    public const PERIODS = ['30-hari' => '30 hari', '90-hari' => '3 bulan', 'tahun-ini' => 'Tahun ini', 'semua' => 'Semua'];

    public const STEP = 20;

    #[Url(as: 'periode', except: '90-hari')]
    public string $period = '90-hari';

    #[Url(as: 'lihat', except: null)]
    public ?int $viewing = null;

    public int $limit = self::STEP;

    public function updatedPeriod(): void
    {
        $this->period = array_key_exists($this->period, self::PERIODS) ? $this->period : '90-hari';
        $this->limit = self::STEP;
    }

    private function query()
    {
        $from = match ($this->period) {
            '30-hari' => now()->subDays(29)->startOfDay(),
            '90-hari' => now()->subDays(89)->startOfDay(),
            'tahun-ini' => now()->startOfYear(),
            default => null,
        };

        return Transaction::where('member_id', Auth::guard('member')->id())
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from));
    }

    #[Computed]
    public function list(): array
    {
        $rows = $this->query()->latest('created_at')->latest('id')->limit($this->limit + 1)->get();

        return [
            'rows' => $rows->take($this->limit)->map(fn (Transaction $t) => [
                'id' => $t->id, 'invoice' => $t->invoice_number, 'at' => $t->created_at, 'items' => (int) $t->item_count,
                'total' => (int) round((float) $t->total), 'method' => CheckoutService::PAYMENT_LABELS[$t->payment_method] ?? $t->payment_method,
                'voided' => $t->voided_at !== null, 'returned' => (int) round((float) $t->returned_amount),
            ])->all(),
            'more' => $rows->count() > $this->limit,
        ];
    }

    #[Computed]
    public function totals(): array
    {
        $row = $this->query()->whereNull('voided_at')->selectRaw('COUNT(*) AS n, COALESCE(SUM(total), 0) - COALESCE(SUM(returned_amount), 0) AS spent')->first();

        return ['count' => (int) $row->n, 'spent' => (int) round((float) $row->spent)];
    }

    #[Computed]
    public function detail(): ?array
    {
        if (! $this->viewing) {
            return null;
        }
        $t = Transaction::with('items')->where('member_id', Auth::guard('member')->id())->find($this->viewing);
        abort_unless($t, 404);

        return [
            'invoice' => $t->invoice_number, 'at' => $t->created_at,
            'method' => CheckoutService::PAYMENT_LABELS[$t->payment_method] ?? $t->payment_method,
            'items' => $t->items->map(fn ($i) => ['name' => $i->product_name, 'qty' => (int) $i->quantity,
                'price' => (int) round((float) $i->price), 'subtotal' => (int) round((float) $i->subtotal)])->all(),
            'subtotal' => (int) round((float) $t->subtotal), 'discount' => (int) round((float) $t->discount),
            'tax' => (int) round((float) $t->tax), 'rounding' => (int) round((float) $t->rounding), 'total' => (int) round((float) $t->total),
            'voided' => $t->voided_at !== null, 'void_reason' => $t->void_reason, 'returned' => (int) round((float) $t->returned_amount),
        ];
    }

    public function show(int $id): void
    {
        $this->viewing = $id;
        unset($this->detail);
    }

    public function close(): void
    {
        $this->viewing = null;
    }

    public function more(): void
    {
        $this->limit = min($this->limit + self::STEP, 200);
    }

    public function render()
    {
        return view('livewire.portal.purchases');
    }
}
