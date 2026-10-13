<?php

namespace App\Livewire\Transaksi;

use App\Services\TransactionHistory;
use App\Support\MockData;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Transaksi → Riwayat retur & pembatalan: pengajuan yang sudah diputus (disetujui/ditolak),
 * dikelompokkan per tanggal keputusan, dengan filter periode/jenis/hasil, ringkasan nilai
 * dikembalikan & unit kembali ke stok, serta rekap per alasan. Tahap ini dari
 * MockData::transactionReturns(); backend riwayat menyusul dengan bentuk data yang sama.
 */
#[Layout('components.layouts.app')]
#[Title('Riwayat Retur & Pembatalan')]
class ReturnHistory extends Component
{
    public const PRESETS = TransactionHistory::PRESETS;

    public const OUTCOMES = ['' => 'Disetujui & ditolak', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak'];

    #[Url(as: 'periode', except: 'semua')]
    public string $preset = 'semua';

    #[Url(as: 'dari', except: '')]
    public string $from = '';

    #[Url(as: 'sampai', except: '')]
    public string $to = '';

    #[Url(as: 'jenis', except: '')]
    public string $kind = '';

    #[Url(as: 'hasil', except: '')]
    public string $outcome = '';

    public function applyPreset(string $preset): void
    {
        if (array_key_exists($preset, self::PRESETS)) {
            $this->resetValidation();
            $this->preset = $preset;
            $this->from = '';
            $this->to = '';
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to'], true)) {
            $this->preset = 'kustom';
            $this->validate([
                'from' => ['nullable', 'date_format:Y-m-d'],
                'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            ], ['to.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.']);
        }
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    #[Computed]
    public function range(): array
    {
        $valid = fn ($v, $f) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && ! $this->getErrorBag()->has($f) ? $v : null;

        return $this->preset === 'kustom'
            ? TransactionHistory::range('semua', $valid($this->from, 'from'), $valid($this->to, 'to'))
            : TransactionHistory::range($this->preset);
    }

    /** Pengajuan yang sudah diputus sesuai filter, terbaru dulu. */
    #[Computed]
    public function rows(): array
    {
        [$start, $end] = $this->range;

        return array_values(array_filter(MockData::transactionReturns(), function ($r) use ($start, $end) {
            if ($r['status'] === 'menunggu' || ($this->kind !== '' && $r['kind'] !== $this->kind) || ($this->outcome !== '' && $r['status'] !== $this->outcome)) {
                return false;
            }
            $at = Carbon::parse($r['decided_at']);

            return (! $start || $at->gte($start)) && (! $end || $at->lte($end));
        }));
    }

    /** @return array<string, list<array>> tanggal (Y-m-d) → pengajuan, terbaru dulu */
    #[Computed]
    public function days(): array
    {
        $rows = $this->rows;
        usort($rows, fn ($a, $b) => [$b['decided_at'], $b['id']] <=> [$a['decided_at'], $a['id']]);

        return collect($rows)->groupBy(fn ($r) => substr($r['decided_at'], 0, 10))->map->all()->all();
    }

    #[Computed]
    public function summary(): array
    {
        $ok = collect($this->rows)->where('status', 'disetujui');
        $reasons = $ok->groupBy('reason')->map(fn ($g, $reason) => ['reason' => $reason, 'count' => $g->count(), 'amount' => $g->sum('amount')])
            ->sortByDesc('amount')->values()->all();

        return [
            'approved' => $ok->count(),
            'rejected' => collect($this->rows)->where('status', 'ditolak')->count(),
            'refunded' => $ok->sum('amount'),
            'restocked' => $ok->where('restock', true)->sum('qty'),
            'written_off' => $ok->where('restock', false)->sum('qty'),
            'reasons' => $reasons,
        ];
    }

    #[Computed]
    public function rangeLabel(): ?string
    {
        return TransactionHistory::rangeLabel(...$this->range);
    }

    public function render()
    {
        return view('livewire.transaksi.return-history');
    }
}
