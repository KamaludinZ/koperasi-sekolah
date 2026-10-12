<?php

namespace App\Livewire\Anggota;

use App\Support\MockData;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Daftar anggota koperasi (siswa & guru) beserta saldo tabungan.
 * Tahap frontend: memakai data tiruan (MockData::members).
 */
#[Layout('components.layouts.app')]
#[Title('Anggota & Tabungan')]
class Index extends Component
{
    public const TYPES = ['siswa' => 'Siswa', 'guru' => 'Guru'];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'jenis', except: '')]
    public string $type = '';

    #[Url(as: 'kelas', except: '')]
    public string $class = '';

    #[Url(as: 'nonaktif', except: false)]
    public bool $showInactive = false;

    public function updatedType(): void
    {
        if ($this->type === 'guru') {
            $this->class = '';
        }
    }

    #[Computed]
    public function members(): array
    {
        $term = mb_strtolower(trim($this->search));

        return collect(MockData::members())
            ->filter(fn ($m) => $this->showInactive || $m['is_active'])
            ->filter(fn ($m) => $this->type === '' || $m['type'] === $this->type)
            ->filter(fn ($m) => $this->class === '' || $m['class'] === $this->class)
            ->filter(fn ($m) => $term === ''
                || str_contains(mb_strtolower($m['name']), $term)
                || str_contains(mb_strtolower($m['member_code']), $term))
            ->sortBy([['type', 'desc'], ['class', 'asc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    #[Computed]
    public function stats(): array
    {
        $active = collect(MockData::members())->where('is_active', true);

        return [
            'total' => $active->count(),
            'siswa' => $active->where('type', 'siswa')->count(),
            'guru' => $active->where('type', 'guru')->count(),
            'balance' => $active->sum('balance'),
        ];
    }

    /** @return list<string> */
    #[Computed]
    public function classes(): array
    {
        return collect(MockData::members())->pluck('class')->filter()->unique()->sort()->values()->all();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'type', 'class', 'showInactive');
    }

    public function render()
    {
        return view('livewire.anggota.index');
    }
}
