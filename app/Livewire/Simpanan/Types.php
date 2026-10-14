<?php

namespace App\Livewire\Simpanan;

use App\Livewire\Concerns\NotifiesSave;
use App\Models\SavingType;
use App\Services\SavingTypeService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Simpanan → Jenis simpanan (Admin & Pengurus): daftar jenis simpanan pokok/wajib/sukarela beserta
 * nominal, periode setor, dan boleh-tidaknya ditarik; tambah/ubah lewat panel geser dan aktif/nonaktif.
 */
#[Layout('components.layouts.app')]
#[Title('Jenis Simpanan')]
class Types extends Component
{
    use NotifiesSave;

    public bool $editing = false;

    public ?int $editId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    #[Computed]
    public function types(): array
    {
        $stats = app(SavingTypeService::class)->stats();

        return SavingType::ordered()->get()->map(fn (SavingType $t) => SavingTypeService::toArray($t)
            + ['members' => $stats[$t->id]['members'] ?? 0, 'total_balance' => $stats[$t->id]['balance'] ?? 0])->all();
    }

    #[Computed]
    public function canManage(): bool
    {
        return (bool) auth()->user()?->can('kelola-simpanan');
    }

    public function create(): void
    {
        abort_unless($this->canManage, 403);
        $this->editId = null;
        $this->form = ['code' => '', 'name' => '', 'kind' => 'sukarela', 'amount' => 0, 'period' => 'bebas', 'withdrawable' => true, 'description' => '', 'is_active' => true];
        $this->resetValidation();
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        abort_unless($this->canManage, 403);
        $t = SavingType::find($id);
        if (! $t) {
            return;
        }
        $this->editId = $t->id;
        $this->form = collect(SavingTypeService::toArray($t))->only(['code', 'name', 'kind', 'amount', 'period', 'withdrawable', 'description', 'is_active'])->all();
        $this->form['description'] ??= '';
        $this->resetValidation();
        $this->editing = true;
    }

    /** Pilih jenis → isi periode & aturan tarik yang lazim (masih bisa diubah). */
    public function updatedFormKind(string $kind): void
    {
        [$period, $withdrawable] = match ($kind) {
            'pokok' => ['sekali', false],
            'wajib' => ['bulanan', false],
            default => ['bebas', true],
        };
        $this->form['period'] = $period;
        $this->form['withdrawable'] = $withdrawable;
    }

    public function save(): void
    {
        abort_unless($this->canManage, 403);
        $data = $this->validate(
            collect(SavingTypeService::rules($this->editId, $this->form['kind'] ?? null))->mapWithKeys(fn ($r, $k) => ["form.{$k}" => $r])->all(),
            collect(SavingTypeService::messages())->mapWithKeys(fn ($m, $k) => [str_contains($k, '.') ? "form.{$k}" : $k => $m])->all(),
        )['form'];

        $type = app(SavingTypeService::class)->save($data, $this->editId ? SavingType::find($this->editId) : null);
        $this->editing = false;
        unset($this->types);
        $this->toast('success', ($this->editId ? 'Perubahan disimpan: ' : 'Jenis simpanan ditambahkan: ').$type->name);
    }

    public function toggle(int $id): void
    {
        abort_unless($this->canManage, 403);
        $t = SavingType::find($id);
        if (! $t) {
            return;
        }
        $t = app(SavingTypeService::class)->setActive($t, ! $t->is_active);
        unset($this->types);
        $this->toast('success', $t->name.($t->is_active ? ' diaktifkan.' : ' dinonaktifkan — tidak bisa dipakai untuk setoran baru.'));
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.simpanan.types');
    }
}
