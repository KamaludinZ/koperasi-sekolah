<?php

namespace App\Livewire\Audit;

use App\Models\Impersonation;
use App\Services\RoleImpersonationTrail;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Audit → Peran & impersonasi (admin & pengurus): jejak perubahan peran, sesi impersonasi (siapa masuk sebagai
 * siapa, alasan, durasi, cara berakhir) dan aksi yang dilakukan selama impersonasi — semuanya diatribusikan ke
 * admin pelaku dan ditandai sah/tidak menurut checksum HMAC. Data dari RoleImpersonationTrail.
 */
#[Layout('components.layouts.app')]
#[Title('Jejak Peran & Impersonasi')]
class RoleImpersonation extends Component
{
    use WithPagination;

    public const TABS = ['peran' => 'Perubahan peran', 'impersonasi' => 'Sesi impersonasi', 'aksi' => 'Aksi selama impersonasi'];

    #[Url(as: 'tampil', except: 'peran')]
    public $tab = 'peran';

    #[Url(as: 'periode', except: '30')]
    public $period = '30';

    #[Url(as: 'q', except: '')]
    public $search = '';

    #[Url(as: 'pelaku', except: '')]
    public $actor = '';

    #[Url(as: 'status', except: '')]
    public $status = '';

    public function mount(): void
    {
        $this->normalize();
    }

    public function updated(): void
    {
        $this->normalize();
        $this->resetPage('hal');
    }

    private function normalize(): void
    {
        $this->tab = is_string($this->tab) && array_key_exists($this->tab, self::TABS) ? $this->tab : 'peran';
        $this->period = is_scalar($this->period) && array_key_exists((string) $this->period, RoleImpersonationTrail::PERIODS) ? (string) $this->period : '30';
        $this->search = is_string($this->search) ? mb_substr(trim($this->search), 0, 60) : '';
        $this->actor = is_string($this->actor) ? mb_substr($this->actor, 0, 120) : '';
        $this->status = in_array($this->status, ['aktif', 'selesai'], true) ? $this->status : '';
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'actor', 'status');
        $this->period = '30';
        $this->resetPage('hal');
    }

    private function filters(): array
    {
        return ['period' => $this->period, 'search' => $this->search, 'actor' => $this->actor, 'status' => $this->status];
    }

    #[Computed]
    public function trail(): RoleImpersonationTrail
    {
        return app(RoleImpersonationTrail::class);
    }

    public function render()
    {
        $rows = match ($this->tab) {
            'impersonasi' => $this->trail->sessions($this->filters()),
            'aksi' => $this->trail->actions($this->filters()),
            default => $this->trail->roleChanges($this->filters()),
        };
        $items = collect($rows->items());
        $sessionLogs = $this->tab === 'impersonasi' ? $this->trail->sessionLogs($items->pluck('id')->all()) : [];
        $logIds = $this->tab === 'impersonasi'
            ? collect($sessionLogs)->flatMap(fn ($p) => array_filter([$p['started']?->id, $p['ended']?->id]))->all()
            : $items->pluck('id')->all();

        return view('livewire.audit.role-impersonation', [
            'rows' => $rows,
            'sessionLogs' => $sessionLogs,
            'integrity' => $this->trail->integrity(array_map('intval', $logIds)),
            'stats' => $this->trail->stats($this->period),
            'actors' => $this->trail->actors(),
            'periods' => RoleImpersonationTrail::PERIODS,
            'endReasons' => Impersonation::END_REASONS,
        ]);
    }
}
