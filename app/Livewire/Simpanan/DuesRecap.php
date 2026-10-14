<?php

namespace App\Livewire\Simpanan;

use App\Models\SavingType;
use App\Services\SavingDueService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Simpanan → Setoran bulanan → Rekap status: matriks guru × bulan (disetor/dicatat/belum) dan jumlah bulan belum disetor. */
#[Layout('components.layouts.app')]
#[Title('Rekap Setoran Bulanan')]
class DuesRecap extends Component
{
    public const RANGES = [3 => '3 bulan', 6 => '6 bulan', 12 => '12 bulan'];

    #[Url(as: 'jenis')]
    public ?int $typeId = null;

    #[Url(as: 'rentang', except: 6)]
    public int $months = 6;

    public const STATUSES = ['' => 'Semua status', 'lengkap' => 'Lengkap', 'belum' => 'Belum lengkap'];

    #[Url(as: 'status', except: '')]
    public string $status = '';

    public function mount(): void
    {
        $types = SavingDueService::types();
        $this->typeId = $types->firstWhere('id', $this->typeId)?->id ?? $types->firstWhere('kind', 'wajib')?->id ?? $types->first()?->id;
        $this->months = array_key_exists($this->months, self::RANGES) ? $this->months : 6;
    }

    public function updatedStatus(): void
    {
        $this->status = array_key_exists($this->status, self::STATUSES) ? $this->status : '';
    }

    public function updatedMonths(): void
    {
        $this->months = array_key_exists($this->months, self::RANGES) ? $this->months : 6;
    }

    #[Computed]
    public function types()
    {
        return SavingDueService::types();
    }

    #[Computed]
    public function matrix(): array
    {
        $type = $this->typeId ? SavingType::find($this->typeId) : null;
        if (! $type) {
            return ['periods' => [], 'rows' => [], 'totals' => []];
        }
        $m = app(SavingDueService::class)->matrix($type, $this->months);
        if ($this->status !== '') {
            $m['rows'] = array_values(array_filter($m['rows'], fn ($r) => $this->status === 'lengkap' ? $r['missing'] === 0 : $r['missing'] > 0));
        }

        return $m;
    }

    public function render()
    {
        return view('livewire.simpanan.dues-recap');
    }
}
