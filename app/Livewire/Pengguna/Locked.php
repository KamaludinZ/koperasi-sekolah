<?php

namespace App\Livewire\Pengguna;

use App\Livewire\Concerns\NotifiesSave;
use App\Support\MockData;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Pengguna → Akun terkunci (admin): akun/email yang dikunci karena gagal masuk berulang
 * dengan kunci bertingkat (5× → 1 menit, 10× → 15 menit, 15× → sampai dibuka Admin),
 * beserta jumlah gagal, IP, dan sisa waktu kunci. Admin membuka kunci dengan catatan opsional.
 * Tahap ini memakai MockData::lockedAccounts().
 */
#[Layout('components.layouts.app')]
#[Title('Akun Terkunci')]
class Locked extends Component
{
    use NotifiesSave;

    public ?int $unlockId = null;

    public string $note = '';

    /** @return list<array{failed: int, minutes: ?int, label: string}> */
    public static function tiers(): array
    {
        return collect(MockData::LOCK_TIERS)->map(fn (?int $m, int $n) => [
            'failed' => $n,
            'minutes' => $m,
            'label' => $m === null ? 'Sampai dibuka Admin' : $m.' menit',
        ])->values()->all();
    }

    /** Sisa menit kunci sementara; null = kunci manual (tanpa batas waktu). */
    public static function minutesLeft(array $row): ?int
    {
        return $row['locked_until'] === null ? null
            : max(0, (int) ceil(now()->diffInSeconds(Carbon::parse($row['locked_until']), false) / 60));
    }

    #[Computed]
    public function accounts(): array
    {
        return collect(MockData::lockedAccounts())
            ->sortBy([['level', 'desc'], ['locked_at', 'desc']])->values()->all();
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

        MockData::unlockAccount($row['id']);
        $this->cancelUnlock();
        unset($this->accounts, $this->summary);
        $this->toast('success', ($row['name'] ?? $row['email']).' dapat mencoba masuk lagi.');
    }

    public function render()
    {
        return view('livewire.pengguna.locked');
    }
}
