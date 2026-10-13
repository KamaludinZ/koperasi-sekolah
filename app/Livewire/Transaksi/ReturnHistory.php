<?php

namespace App\Livewire\Transaksi;

use App\Services\TransactionHistory;
use App\Services\TransactionReturnService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Transaksi → Riwayat retur & pembatalan: pengajuan yang sudah diputus (disetujui/ditolak),
 * dikelompokkan per tanggal keputusan, dengan filter periode/jenis/hasil, ringkasan nilai
 * dikembalikan & unit kembali ke stok, serta rekap per alasan, dan unduhan CSV. Data dari
 * TransactionReturnService::history (sama dengan GET /transaksi/retur/riwayat/data).
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

    #[Computed]
    public function result(): array
    {
        [$start, $end] = $this->range;

        return app(TransactionReturnService::class)->history(['from' => $start, 'to' => $end, 'kind' => $this->kind ?: null, 'outcome' => $this->outcome ?: null]);
    }

    /** Pengajuan yang sudah diputus sesuai filter, terbaru dulu. */
    #[Computed]
    public function rows(): array
    {
        return $this->result['rows'];
    }

    /** Tautan unduh CSV dengan filter yang sedang dipakai. */
    #[Computed]
    public function exportUrl(): string
    {
        [$start, $end] = $this->range;

        return route('transaksi.retur.export', array_filter(['dari' => $start?->toDateString(), 'sampai' => $end?->toDateString(), 'jenis' => $this->kind, 'hasil' => $this->outcome]));
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
        return $this->result['summary'];
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
