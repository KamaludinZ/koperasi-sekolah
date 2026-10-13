<?php

namespace App\Livewire\Transaksi;

use App\Livewire\Concerns\NotifiesSave;
use App\Models\Transaction;
use App\Support\MockData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Transaksi → Retur & pembatalan: daftar pengajuan retur barang dan pembatalan transaksi
 * (status menunggu/disetujui/ditolak) dengan ringkasan & rincian, serta pencarian transaksi
 * untuk memulai pengajuan baru. Admin/Pengurus (gate `setujui-retur`) menyetujui atau menolak
 * pengajuan yang menunggu dari panel rincian; tidak boleh memutus pengajuan sendiri.
 * Tahap ini daftar dari MockData::transactionReturns() dan keputusan disimulasikan di layar.
 */
#[Layout('components.layouts.app')]
#[Title('Retur & Pembatalan')]
class Returns extends Component
{
    use NotifiesSave;

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

    /** Hanya pengajuan yang dibuat pengguna ini. */
    #[Url(as: 'saya', except: false)]
    public bool $mine = false;

    public ?int $selectedId = null;

    /** Pencarian transaksi untuk pengajuan baru. */
    public string $lookup = '';

    /** Catatan keputusan persetujuan (wajib saat menolak). */
    public string $decisionNote = '';

    /** @var array<int, array{status: string, by: string, at: string, note: ?string}> keputusan yang dibuat di layar (simulasi) */
    public array $decisions = [];

    public function setStatus(string $status): void
    {
        $this->status = array_key_exists($status, self::STATUSES) ? $status : 'semua';
    }

    #[Computed]
    public function all(): array
    {
        return array_map(function ($r) {
            $d = $this->decisions[$r['id']] ?? null;

            return $d ? [...$r, 'status' => $d['status'], 'decided_by' => $d['by'], 'decided_at' => $d['at'], 'decision_note' => $d['note']] : $r;
        }, MockData::transactionReturns());
    }

    #[Computed]
    public function canApprove(): bool
    {
        return Gate::allows('setujui-retur');
    }

    /** Alasan pengguna ini tidak boleh memutus pengajuan, atau null bila boleh. */
    public function decisionBlock(array $r): ?string
    {
        return match (true) {
            ! $this->canApprove => 'Menunggu persetujuan Admin/Pengurus. Stok dan uang belum berubah sampai disetujui.',
            $r['requested_by'] === auth()->user()->name => 'Pengajuan ini Anda buat sendiri — minta Admin/Pengurus lain untuk memutuskan.',
            default => null,
        };
    }

    public function approve(int $id): void
    {
        $this->decide($id, 'disetujui');
    }

    public function reject(int $id): void
    {
        $this->decide($id, 'ditolak');
    }

    private function decide(int $id, string $status): void
    {
        $r = collect($this->all)->firstWhere('id', $id);
        abort_unless($r && $this->canApprove, 403);
        if ($r['status'] !== 'menunggu') {
            $this->toast('error', "Pengajuan {$r['number']} sudah diputuskan.");

            return;
        }
        if ($msg = $this->decisionBlock($r)) {
            $this->addError('decisionNote', $msg);

            return;
        }
        $this->validate(['decisionNote' => [$status === 'ditolak' ? 'required' : 'nullable', 'string', 'min:'.($status === 'ditolak' ? 5 : 0), 'max:200']], [
            'decisionNote.required' => 'Tulis alasan penolakan.',
            'decisionNote.min' => 'Alasan penolakan minimal 5 karakter.',
            'decisionNote.max' => 'Catatan maksimal 200 karakter.',
        ]);

        // Simulasi: backend menyusul akan mengembalikan stok & dana saat disetujui.
        $this->decisions[$id] = ['status' => $status, 'by' => auth()->user()->name, 'at' => now()->toDateTimeString(), 'note' => trim($this->decisionNote) ?: null];
        $this->decisionNote = '';
        unset($this->all, $this->rows, $this->counts, $this->stats, $this->selected);
        $what = $r['kind'] === 'batal' ? 'Pembatalan' : 'Retur';
        $this->toast('success', $status === 'disetujui'
            ? "{$what} {$r['number']} disetujui. ".($r['kind'] === 'batal' || $r['restock'] ? 'Barang kembali ke stok dan ' : '')."dana {$this->rupiah($r['amount'])} dikembalikan. (simulasi)"
            : "{$what} {$r['number']} ditolak. (simulasi)");
    }

    private function rupiah(int $n): string
    {
        return 'Rp'.number_format($n, 0, ',', '.');
    }

    #[Computed]
    public function rows(): array
    {
        $q = mb_strtolower(trim($this->search));

        return array_values(array_filter($this->all, fn ($r) => ($this->status === 'semua' || $r['status'] === $this->status)
            && ($this->kind === '' || $r['kind'] === $this->kind)
            && (! $this->mine || $r['requested_by'] === auth()->user()->name)
            && ($q === '' || str_contains(mb_strtolower($r['number'].' '.$r['transaction']['invoice'].' '.implode(' ', array_column($r['items'], 'name'))), $q))));
    }

    /** @return array<string, int> jumlah per status (setelah filter jenis) */
    #[Computed]
    public function counts(): array
    {
        $rows = array_filter($this->all, fn ($r) => ($this->kind === '' || $r['kind'] === $this->kind) && (! $this->mine || $r['requested_by'] === auth()->user()->name));
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
        $this->decisionNote = '';
        $this->resetValidation();
    }

    public function closeDetail(): void
    {
        $this->selectedId = null;
    }

    /** Buka pengajuan menunggu tertua berikutnya (antrean persetujuan). */
    public function nextPending(): void
    {
        $next = collect($this->all)->where('status', 'menunggu')->sortBy('requested_at')->first();
        $next ? $this->show($next['id']) : $this->closeDetail();
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
