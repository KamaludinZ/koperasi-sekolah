<?php

namespace App\Livewire\Pengguna;

use App\Livewire\Concerns\NotifiesSave;
use App\Models\LoginLockout;
use App\Services\LoginLockoutService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Pengguna → Akun terkunci (admin): akun/email yang dikunci karena gagal masuk berulang
 * dengan kunci bertingkat (N× → 1 menit, 2N× → 15 menit, 3N× → sampai dibuka Admin; N di pengaturan),
 * beserta jumlah gagal, IP, dan sisa waktu kunci. Admin membuka kunci dengan catatan
 * (wajib untuk tingkat 3). Data dari LoginLockoutService (kontrak sama dengan GET /pengguna/terkunci/data).
 */
#[Layout('components.layouts.app')]
#[Title('Akun Terkunci')]
class Locked extends Component
{
    use NotifiesSave;

    public ?int $unlockId = null;

    public string $note = '';

    /** @return list<array{level: int, failed: int, minutes: ?int, label: string}> */
    public static function tiers(): array
    {
        return LoginLockoutService::tiers();
    }

    /** Sisa menit kunci sementara; null = kunci manual (tanpa batas waktu). */
    public static function minutesLeft(array $row): ?int
    {
        return $row['seconds_left'] === null ? null : (int) ceil($row['seconds_left'] / 60);
    }

    #[Computed]
    public function accounts(): array
    {
        return app(LoginLockoutService::class)->list();
    }

    #[Computed]
    public function summary(): array
    {
        $all = collect($this->accounts);

        return [
            'total' => $all->count(),
            'manual' => $all->whereNull('locked_until')->count(),
            'temporary' => $all->whereNotNull('locked_until')->count(),
            'unknown' => $all->whereNull('user_id')->count(),
            'failed' => $all->sum('failed'),
        ];
    }

    #[Computed]
    public function target(): ?array
    {
        return $this->unlockId ? collect($this->accounts)->firstWhere('id', $this->unlockId) : null;
    }

    public function confirmUnlock(int $id): void
    {
        $this->unlockId = $id;
        $this->note = '';
        $this->resetValidation();
    }

    public function cancelUnlock(): void
    {
        $this->reset('unlockId', 'note');
        $this->resetValidation();
    }

    public function unlock(): void
    {
        $row = $this->target;
        if ($row === null) {
            $this->cancelUnlock();

            return;
        }
        $this->validate(
            ['note' => [$row['level'] >= 3 ? 'required' : 'nullable', 'string', 'max:200']],
            ['note.required' => 'Tulis alasan membuka kunci (wajib untuk kunci tingkat akhir).', 'note.max' => 'Catatan maksimal 200 karakter.'],
        );

        if ($lockout = LoginLockout::find($row['id'])) {
            app(LoginLockoutService::class)->unlock($lockout, trim($this->note) ?: null);
        }
        $this->cancelUnlock();
        unset($this->accounts, $this->summary);
        $this->toast('success', ($row['name'] ?? $row['email']).' dapat mencoba masuk lagi.');
    }

    public function render()
    {
        return view('livewire.pengguna.locked');
    }
}
