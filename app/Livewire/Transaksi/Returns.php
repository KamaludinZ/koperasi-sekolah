<?php

namespace App\Livewire\Transaksi;

use App\Models\Transaction;
use App\Support\MockData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Transaksi → Retur & pembatalan: daftar pengajuan retur barang dan pembatalan transaksi
 * (status menunggu/disetujui/ditolak) dengan ringkasan & rincian, serta pencarian transaksi
 * untuk memulai pengajuan baru. Tahap ini daftar dari MockData::transactionReturns().
 */
#[Layout('components.layouts.app')]
#[Title('Retur & Pembatalan')]
class Returns extends Component
{
    public const STATUSES = ['semua' => 'Semua', 'menunggu' => 'Menunggu', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'];

    public const KINDS = ['' => 'Retur & pembatalan', 'retur' => 'Retur barang', 'batal' => 'Pembatalan'];

    public const STATUS_TONES = ['menunggu' => 'bg-amber-100 text-amber-900', 'disetujui' => 'bg-emerald-100 text-emerald-800', 'ditolak' => 'bg-red-100 text-red-800'];

    public const REFUND_METHODS = ['tunai' => 'Uang tunai', 'qris' => 'Transfer balik', 'tabungan' => 'Masuk saldo tabungan', 'bon' => 'Mengurangi bon'];

    /** Batas hari sejak pembelian untuk retur/pembatalan. */
    public const LIMIT_DAYS = 3;

    #[Url(as: 'status', except: 'semua')]
    public string $status = 'semua';

    #[Url(as: 'jenis', except: '')]
    public string $kind = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?int $selectedId = null;

    /** Pencarian transaksi untuk pengajuan baru. */
    public string $lookup = '';

    public function setStatus(string $status): void
    {
        $this->status = array_key_exists($status, self::STATUSES) ? $status : 'semua';
    }

    #[Computed]
    public function all(): array
    {
        return MockData::transactionReturns();
    }

    #[Computed]
    public function rows(): array
    {
        $q = mb_strtolower(trim($this->search));

        return array_values(array_filter($this->all, fn ($r) => ($this->status === 'semua' || $r['status'] === $this->status)
            && ($this->kind === '' || $r['kind'] === $this->kind)
            && ($q === '' || str_contains(mb_strtolower($r['number'].' '.$r['transaction']['invoice'].' '.implode(' ', array_column($r['items'], 'name'))), $q))));
    }

    /** @return array<string, int> jumlah per status (setelah filter jenis) */
    #[Computed]
    public function counts(): array
    {
        $rows = array_filter($this->all, fn ($r) => $this->kind === '' || $r['kind'] === $this->kind);
        $counts = array_fill_keys(array_keys(self::STATUSES), 0);
        $counts['semua'] = count($rows);
        foreach ($rows as $r) {
            $counts[$r['status']]++;
        }

        return $counts;
    }

    #[Computed]
    public function stats(): array
    {
        $done = collect($this->all)->where('status', 'disetujui')->filter(fn ($r) => Carbon::parse($r['requested_at'])->isCurrentMonth());

        return [
            'pending' => collect($this->all)->where('status', 'menunggu')->count(),
            'return_total' => $done->where('kind', 'retur')->sum('amount'),
            'return_count' => $done->where('kind', 'retur')->count(),
            'void_total' => $done->where('kind', 'batal')->sum('amount'),
            'void_count' => $done->where('kind', 'batal')->count(),
            'restocked' => $done->where('restock', true)->sum('qty'),
        ];
    }

    #[Computed]
    public function selected(): ?array
    {
        return $this->selectedId ? collect($this->all)->firstWhere('id', $this->selectedId) : null;
    }

    public function show(int $id): void
    {
        $this->selectedId = collect($this->all)->contains('id', $id) ? $id : null;
    }

    public function closeDetail(): void
    {
        $this->selectedId = null;
    }

    /** @return list<array<string, mixed>> transaksi yang cocok dengan nomor yang diketik (maks. 5) */
    #[Computed]
    public function lookupResults(): array
    {
        $q = trim($this->lookup);
        if (mb_strlen($q) < 3) {
            return [];
        }

        return Transaction::where('invoice_number', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtoupper($q)).'%')
            ->latest('id')->take(5)->get()
            ->map(fn (Transaction $t) => [
                'id' => $t->id,
                'invoice' => $t->invoice_number,
                'total' => (int) $t->total,
                'items' => $t->item_count,
                'method' => $t->payment_method,
                'created_at' => $t->created_at->toDateTimeString(),
                'expired' => $t->created_at->lt(now()->subDays(self::LIMIT_DAYS)->startOfDay()),
            ])->all();
    }

    /** Tautan form pengajuan bila sudah tersedia (route menyusul di tugas berikutnya). */
    public static function formLink(string $kind, int $transactionId): ?string
    {
        $route = $kind === 'batal' ? 'transaksi.retur.batal' : 'transaksi.retur.buat';

        return Route::has($route) ? route($route, ['transaction' => $transactionId]) : null;
    }

    public function render()
    {
        return view('livewire.transaksi.returns');
    }
}
