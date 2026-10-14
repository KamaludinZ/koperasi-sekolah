<?php

namespace App\Livewire\Simpanan;

use App\Models\Member;
use App\Models\MemberSavingEntry;
use App\Models\SavingType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Simpanan → Mutasi: riwayat setor/tarik/potong semua jenis simpanan (terbaru dulu) dengan saring jenis,
 * arah mutasi, anggota (nama/kode atau ?anggota=id), dan rentang tanggal; total masuk/keluar sesuai saringan.
 */
#[Layout('components.layouts.app')]
#[Title('Mutasi Simpanan')]
class Entries extends Component
{
    use WithPagination;

    public const PERIODS = ['30-hari' => '30 hari', '90-hari' => '3 bulan', 'tahun-ini' => 'Tahun ini', 'semua' => 'Semua'];

    public const PER_PAGE = 25;

    #[Url(as: 'jenis', except: '')]
    public string $type = '';

    #[Url(as: 'arah', except: '')]
    public string $kind = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'anggota', except: null)]
    public ?int $memberId = null;

    #[Url(as: 'periode', except: '30-hari')]
    public string $period = '30-hari';

    public function updated(string $property): void
    {
        if (! array_key_exists($this->period, self::PERIODS)) {
            $this->period = '30-hari';
        }
        if (! array_key_exists($this->kind, MemberSavingEntry::TYPES)) {
            $this->kind = '';
        }
        $this->resetPage();
    }

    public function clearMember(): void
    {
        $this->memberId = null;
        $this->resetPage();
    }

    private function from(): ?Carbon
    {
        return match ($this->period) {
            '30-hari' => now()->subDays(29)->startOfDay(),
            '90-hari' => now()->subDays(89)->startOfDay(),
            'tahun-ini' => now()->startOfYear(),
            default => null,
        };
    }

    private function query(): Builder
    {
        $term = mb_strtolower(trim($this->search));

        return MemberSavingEntry::query()
            ->when($this->type !== '', fn ($q) => $q->where('saving_type_id', (int) $this->type))
            ->when($this->kind !== '', fn ($q) => $q->where('type', $this->kind))
            ->when($this->memberId, fn ($q) => $q->where('member_id', $this->memberId))
            ->when($term !== '', fn ($q) => $q->whereHas('member', fn ($m) => $m->withTrashed()
                ->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', ["%{$term}%"])->orWhereRaw('LOWER(member_code) LIKE ?', ["%{$term}%"]))))
            ->when($this->from(), fn ($q, $from) => $q->where('created_at', '>=', $from));
    }

    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        return $this->query()->with(['member:id,member_code,name,deleted_at', 'savingType:id,name,code', 'user:id,name'])
            ->latest('created_at')->latest('id')->paginate(self::PER_PAGE)
            ->through(fn (MemberSavingEntry $e) => [
                'id' => $e->id, 'at' => $e->created_at->toDateTimeString(), 'type' => $e->type, 'type_label' => MemberSavingEntry::TYPES[$e->type] ?? $e->type,
                'amount' => (int) round((float) $e->amount), 'balance_after' => (int) round((float) $e->balance_after),
                'period' => $e->period ? Carbon::parse($e->period.'-01')->translatedFormat('F Y') : null, 'note' => $e->note,
                'member' => $e->member ? ['id' => $e->member->id, 'code' => $e->member->member_code, 'name' => $e->member->name] : null,
                'saving_type' => $e->savingType?->name, 'user' => $e->user?->name,
            ]);
    }

    #[Computed]
    public function totals(): array
    {
        $row = $this->query()->selectRaw("
            COALESCE(SUM(CASE WHEN type = 'setor' THEN amount ELSE 0 END), 0) AS masuk,
            COALESCE(SUM(CASE WHEN type <> 'setor' THEN amount ELSE 0 END), 0) AS keluar,
            COUNT(*) AS n")->first();

        return ['in' => (int) round((float) $row->masuk), 'out' => (int) round((float) $row->keluar), 'count' => (int) $row->n];
    }

    #[Computed]
    public function types(): array
    {
        return SavingType::ordered()->pluck('name', 'id')->all();
    }

    #[Computed]
    public function member(): ?array
    {
        $m = $this->memberId ? Member::withTrashed()->find($this->memberId) : null;

        return $m ? ['id' => $m->id, 'code' => $m->member_code, 'name' => $m->name] : null;
    }

    public function render()
    {
        return view('livewire.simpanan.entries');
    }
}
