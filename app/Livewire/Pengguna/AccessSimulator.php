<?php

namespace App\Livewire\Pengguna;

use App\Models\User;
use App\Support\AccessPreview;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kelola Pengguna → Hak akses gabungan (Admin): pilih kombinasi peran tiruan (atau muat dari akun yang ada)
 * lalu lihat menu samping yang akan tampil, asal peran tiap menu, halaman awal, hak aksi, dan halaman
 * yang terbuka/tertutup beserta alasannya. Hanya simulasi — tidak mengubah peran akun mana pun.
 */
#[Layout('components.layouts.app')]
#[Title('Hak Akses Gabungan')]
class AccessSimulator extends Component
{
    /** @var list<string> kombinasi terpilih (selalu dinormalkan: peran valid, urut prioritas) */
    public $roles = [];

    /** Kombinasi di URL, mis. ?peran=pengurus,kasir (tanpa tipe: nilai URL bisa string/array apa pun). */
    #[Url(as: 'peran', except: '')]
    public $peran = '';

    #[Url(as: 'halaman', except: 'semua')]
    public $pageFilter = 'semua';

    public function mount(): void
    {
        $fromUrl = is_string($this->peran) ? explode(',', $this->peran) : (array) $this->peran;
        $this->setRoles($fromUrl);
        if ($this->roles === []) {
            $this->setRoles($this->usedCombinations[0]['roles'] ?? []);
        }
        if (! in_array($this->pageFilter, ['semua', 'terbuka', 'tertutup'], true)) {
            $this->pageFilter = 'semua';
        }
    }

    public function updatedRoles(): void
    {
        $this->setRoles((array) $this->roles);
    }

    public function updatedPeran(): void
    {
        $this->setRoles(is_string($this->peran) ? explode(',', $this->peran) : (array) $this->peran);
    }

    private function setRoles(array $names): void
    {
        $this->roles = $this->preview->normalize(array_filter($names, 'is_string'));
        $this->peran = implode(',', $this->roles);
    }

    public function updatedPageFilter(): void
    {
        if (! in_array($this->pageFilter, ['semua', 'terbuka', 'tertutup'], true)) {
            $this->pageFilter = 'semua';
        }
    }

    /** @param  list<string>  $roles */
    public function useCombination(array $roles): void
    {
        $this->setRoles($roles);
    }

    public function loadUser(int $id): void
    {
        $user = User::with(['roles', 'role'])->find($id);
        if ($user) {
            $this->setRoles($user->roleNames());
        }
    }

    #[Computed]
    public function preview(): AccessPreview
    {
        return new AccessPreview;
    }

    #[Computed]
    public function usedCombinations(): array
    {
        return AccessPreview::usedCombinations();
    }

    #[Computed]
    public function accounts()
    {
        return User::query()->with(['roles', 'role'])->orderBy('name')->get(['id', 'name', 'email', 'role_id', 'is_active']);
    }

    public function render()
    {
        return view('livewire.pengguna.access-simulator', [
            'result' => $this->preview->simulate($this->roles),
            'allRoles' => $this->preview->roles(),
        ]);
    }
}
