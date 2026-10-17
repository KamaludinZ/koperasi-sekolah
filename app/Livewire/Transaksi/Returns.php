<?php

namespace App\Livewire\Transaksi;

use App\Livewire\Concerns\NotifiesSave;
use App\Models\Transaction;
use App\Models\TransactionReturn;
use App\Services\TransactionReturnService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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
 * Data & keputusan lewat TransactionReturnService (sama dengan /transaksi/retur/daftar, .../setujui, .../tolak).
 */
#[Layout('components.layouts.app')]
#[Title('Retur & Pembatalan')]
class Returns extends Component
{
    use NotifiesSave;

    public const STATUSES = ['semua' => 'Semua', 'menunggu' => 'Menunggu', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'];

    public const KINDS = ['' => 'Retur & pembatalan', 'retur' => 'Retur barang', 'batal' => 'Pembatalan'];

    public const STATUS_TONES = ['menunggu' => 'bg-amber-100 text-amber-900', 'disetujui' => 'bg-emerald-100 text-emerald-800', 'ditolak' => 'bg-red-100 text-red-800'];

    public const REFUND_METHODS = TransactionReturn::REFUND_METHODS;

    /** Batas hari sejak pembelian untuk retur/pembatalan. */
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

    public function setStatus(string $status): void
    {
        $this->status = array_key_exists($status, self::STATUSES) ? $status : 'semua';
    }

    private function service(): TransactionReturnService
    {
        return app(TransactionReturnService::class);
    }

    /** Filter jenis & "pengajuan saya" (dipakai daftar dan hitungan). */
    private function baseFilters(): array
    {
        return ['kind' => $this->kind ?: null, 'requested_by' => $this->mine ? auth()->id() : null];
    }

    /** Semua pengajuan (tanpa filter) — untuk rincian & antrean. */
    #[Computed]
    public function all(): array
    {
        return $this->service()->list();
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
            $r['requested_by_id'] === auth()->id() => 'Pengajuan ini Anda buat sendiri — minta Admin/Pengurus lain untuk memutuskan.',
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

        $model = TransactionReturn::findOrFail($id);
        try {
            $done = $status === 'disetujui'
                ? $this->service()->approve($model, auth()->user(), trim($this->decisionNote) ?: null)
                : $this->service()->reject($model, auth()->user(), $this->decisionNote);
        } catch (ValidationException $e) {
            $this->addError('decisionNote', collect($e->errors())->flatten()->first());

            return;
        }
        $this->decisionNote = '';
        unset($this->all, $this->rows, $this->counts, $this->stats, $this->selected);
        $what = $done->isVoid() ? 'Pembatalan' : 'Retur';
        $this->toast('success', $status === 'disetujui'
            ? "{$what} {$done->number} disetujui. ".($done->isVoid() || $done->restock ? 'Barang kembali ke stok dan ' : '')."dana {$this->rupiah($done->amount)} dikembalikan (".mb_strtolower($done->refundLabel()).').'
            : "{$what} {$done->number} ditolak.");
    }

    private function rupiah(int $n): string
    {
        return 'Rp'.number_format($n, 0, ',', '.');
    }

    #[Computed]
    public function rows(): array
    {
        return $this->service()->list([...$this->baseFilters(), 'status' => $this->status === 'semua' ? null : $this->status, 'search' => $this->search]);
    }

    /** @return array<string, int> jumlah per status (setelah filter jenis & pengajuan saya) */
    #[Computed]
    public function counts(): array
    {
        return $this->service()->counts($this->baseFilters());
    }

    #[Computed]
    public function stats(): array
    {
        return $this->service()->stats();
    }

    #[Computed]
    public function selected(): ?array
    {
        $r = $this->selectedId ? TransactionReturn::find($this->selectedId) : null;

        return $r ? TransactionReturnService::toArray($r) : null;
    }

    public function show(int $id): void
    {
        $this->selectedId = TransactionReturn::whereKey($id)->exists() ? $id : null;
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
        $next = $this->service()->queue(1)[0] ?? null;
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
                'expired' => TransactionReturnService::expired($t) || $t->isVoided(),
            ])->all();
    }

    public function render()
    {
        return view('livewire.transaksi.returns');
    }
}
