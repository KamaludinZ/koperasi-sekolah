<?php

namespace App\Livewire\Pengguna;

use App\Livewire\Forms\UserForm;
use App\Support\Menu;
use App\Support\MockData;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kelola pengguna (khusus admin): daftar akun petugas, peran & status,
 * tambah/ubah akun, atur ulang kata sandi, aktif/nonaktifkan, dan ringkasan hak akses tiap peran.
 * Tahap frontend: memakai akun tiruan (MockData::users).
 */
#[Layout('components.layouts.app')]
#[Title('Kelola Pengguna')]
class Index extends Component
{
    public UserForm $form;

    public bool $editing = false;

    public ?string $flash = null;

    public ?string $error = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'peran', except: '')]
    public string $role = '';

    #[Computed]
    public function users(): array
    {
        $term = mb_strtolower(trim($this->search));

        return collect(MockData::users())
            ->filter(fn ($u) => $this->role === '' || $u['role'] === $this->role)
            ->filter(fn ($u) => $term === '' || str_contains(mb_strtolower($u['name']), $term) || str_contains(mb_strtolower($u['email']), $term))
            ->sortBy([['is_active', 'desc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    #[Computed]
    public function stats(): array
    {
        $active = collect(MockData::users())->where('is_active', true);

        return [
            'active' => $active->count(),
            'inactive' => count(MockData::users()) - $active->count(),
            ...collect(MockData::ROLES)->map(fn ($l, $key) => $active->where('role', $key)->count())->all(),
        ];
    }

    /** Daftar menu yang bisa dibuka tiap peran (untuk panel penjelasan). */
    #[Computed]
    public function roleAccess(): array
    {
        return collect(MockData::ROLES)->map(fn ($label, $key) => [
            'label' => $label,
            'menus' => collect(Menu::DEFAULT_PERMISSIONS[$key])->map(fn ($m) => Menu::ITEMS[$m]['label'])->all(),
        ])->all();
    }

    public function currentUserId(): ?int
    {
        return MockData::currentUser()['id'] ?? null;
    }

    public function create(): void
    {
        $this->form->blank();
        $this->reset('flash', 'error');
        $this->editing = true;
    }

    public function edit(int $userId): void
    {
        if ($user = MockData::findUser($userId)) {
            $this->form->load($user);
            $this->reset('flash', 'error');
            $this->editing = true;
        }
    }

    public function save(): void
    {
        $isNew = $this->form->id === null;
        $user = $this->form->save($this->currentUserId());
        if ($user === []) {
            return;
        }

        $this->editing = false;
        $this->flash = $isNew ? "Akun {$user['name']} dibuat sebagai ".MockData::ROLES[$user['role']].'.' : "Akun {$user['name']} diperbarui.";
        unset($this->users, $this->stats);
    }

    public function toggleActive(int $userId): void
    {
        $user = MockData::findUser($userId);
        $this->reset('flash', 'error');
        if (! $user) {
            return;
        }
        if ($user['id'] === $this->currentUserId()) {
            $this->error = 'Anda tidak bisa menonaktifkan akun sendiri.';

            return;
        }
        if ($user['is_active'] && $user['role'] === 'admin' && UserForm::activeAdmins($user['id']) === 0) {
            $this->error = 'Harus ada minimal satu admin aktif.';

            return;
        }

        $user = MockData::saveUser(['id' => $user['id'], 'is_active' => ! $user['is_active']]);
        $this->flash = $user['is_active'] ? "{$user['name']} diaktifkan kembali." : "{$user['name']} dinonaktifkan dan tidak bisa masuk lagi.";
        unset($this->users, $this->stats);
    }

    public function cancel(): void
    {
        $this->editing = false;
    }

    public function render()
    {
        return view('livewire.pengguna.index');
    }
}
