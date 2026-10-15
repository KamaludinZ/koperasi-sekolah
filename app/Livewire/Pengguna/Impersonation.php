<?php

namespace App\Livewire\Pengguna;

use App\Livewire\Concerns\NotifiesSave;
use App\Models\Member;
use App\Models\User;
use App\Services\ImpersonationService;
use App\Support\ImpersonationPolicy;
use App\Support\Menu;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Kelola Pengguna → Masuk sebagai akun (Admin): daftar akun petugas & anggota portal yang bisa dimasuki untuk
 * membantu (impersonasi). Tiap baris menunjukkan bisa/tidaknya beserta alasannya (ImpersonationPolicy); aksi
 * "Masuk sebagai" meminta alasan + persetujuan bahwa semua aksi tercatat atas nama admin, lalu memulai sesi.
 */
#[Layout('components.layouts.app')]
#[Title('Masuk sebagai Akun')]
class Impersonation extends Component
{
    use NotifiesSave, WithPagination;

    #[Url(as: 'jenis', except: 'petugas')]
    public $tab = 'petugas';

    #[Url(as: 'q', except: '')]
    public $search = '';

    /** Tautan langsung dari halaman lain: ?buka=petugas-12 / ?buka=anggota-5 membuka dialog akun itu. */
    #[Url(as: 'buka', except: '')]
    public $open = '';

    /** Akun yang sedang dikonfirmasi: ['type' => petugas|anggota, 'id' => int]. */
    #[Locked]
    public ?array $target = null;

    public string $reason = '';

    public bool $acknowledged = false;

    /** Lama sesi impersonasi (menit) — salah satu ImpersonationPolicy::DURATIONS. */
    public $minutes = ImpersonationPolicy::MAX_MINUTES.''; // string: nilai radio dari peramban selalu string

    public function mount(): void
    {
        $this->normalize();
        if (is_string($this->open) && preg_match('/^(petugas|anggota)-(\d{1,10})$/', $this->open, $m)) {
            $this->tab = $m[1];
            $this->confirm($m[1], (int) $m[2]);
        }
        $this->open = '';
    }

    public function updatedTab(): void
    {
        $this->normalize();
        $this->resetPage();
        $this->target = null;
    }

    public function updatedSearch(): void
    {
        $this->normalize();
        $this->resetPage();
    }

    private function normalize(): void
    {
        $this->tab = in_array($this->tab, ['petugas', 'anggota'], true) ? $this->tab : 'petugas';
        $this->search = is_string($this->search) ? mb_substr(trim($this->search), 0, 60) : '';
    }

    #[Computed]
    public function staff()
    {
        $q = $this->search;

        return User::query()->with(['role', 'roles'])
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")))
            ->orderByDesc('is_active')->orderBy('name')
            ->paginate(15, pageName: 'hal');
    }

    #[Computed]
    public function members()
    {
        $q = $this->search;

        return Member::query()
            ->whereNotNull('password')
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('name', 'like', "%{$q}%")->orWhere('member_code', 'like', "%{$q}%")))
            ->orderByDesc('is_active')->orderBy('name')
            ->paginate(15, pageName: 'hal');
    }

    #[Computed]
    public function stats(): array
    {
        $staff = User::query()->with(['role', 'roles'])->get();

        return [
            'petugas' => $staff->filter(fn ($u) => ImpersonationPolicy::staffDenial($u, Auth::guard('web')->user()) === null)->count(),
            'anggota' => Member::query()->whereNotNull('password')->where('is_active', true)->count(),
        ];
    }

    /** Model akun yang sedang dikonfirmasi (dimuat ulang dari basis data, bukan dari isian klien). */
    #[Computed]
    public function targetModel(): User|Member|null
    {
        if (! $this->target) {
            return null;
        }

        return $this->target['type'] === 'anggota'
            ? Member::find($this->target['id'])
            : User::with(['role', 'roles'])->find($this->target['id']);
    }

    public function confirm(string $type, int $id): void
    {
        $this->resetErrorBag();
        $this->reason = '';
        $this->acknowledged = false;
        $this->minutes = (string) ImpersonationPolicy::MAX_MINUTES;
        $model = $type === 'anggota' ? Member::find($id) : ($type === 'petugas' ? User::with(['role', 'roles'])->find($id) : null);
        $denial = $model ? $this->denial($model) : 'Akun tidak ditemukan.';
        if ($denial !== null) {
            $this->target = null;
            $this->toast('error', $denial);

            return;
        }
        $this->target = ['type' => $type, 'id' => $id];
        unset($this->targetModel);
    }

    public function cancel(): void
    {
        $this->target = null;
        $this->reset('reason', 'acknowledged', 'minutes');
        $this->resetErrorBag();
    }

    /** Pakai alasan cepat (indeks daftar preset jenis akun target), bisa dilengkapi setelahnya. */
    public function usePreset(int $i): void
    {
        $preset = ImpersonationPolicy::REASON_PRESETS[$this->target['type'] ?? ''][$i] ?? null;
        if ($preset !== null) {
            $this->reason = $preset;
            $this->resetErrorBag('reason');
        }
    }

    /**
     * Pratinjau yang akan dilihat admin: menu gabungan akun petugas, atau ringkasan portal anggota.
     *
     * @return array{kind: string, items: list<string>}
     */
    #[Computed]
    public function preview(): array
    {
        $m = $this->targetModel;
        if ($m instanceof User) {
            return ['kind' => 'menu', 'items' => array_map(fn ($k) => Menu::ITEMS[$k]['label'], $m->permissions())];
        }
        if ($m instanceof Member) {
            return ['kind' => 'portal', 'items' => ['Beranda & saldo', 'Simpanan', 'Pinjaman', 'Belanja & pesanan', 'Riwayat transaksi']];
        }

        return ['kind' => 'none', 'items' => []];
    }

    public function start()
    {
        $this->reason = is_string($this->reason) ? trim(preg_replace('/\s+/u', ' ', $this->reason)) : '';
        $this->minutes = is_numeric($this->minutes) ? (int) $this->minutes : $this->minutes;
        $this->validate(ImpersonationPolicy::reasonRules() + ['acknowledged' => ['accepted']],
            ImpersonationPolicy::reasonMessages() + ['acknowledged.accepted' => 'Centang pernyataan bahwa semua aksi tercatat atas nama Anda.']);

        $model = $this->targetModel;
        if (! $model || ($denial = $this->denial($model)) !== null) {
            $this->cancel();
            $this->toast('error', $denial ?? 'Akun tidak ditemukan.');

            return null;
        }

        // Layanan impersonasi (backend) memulai sesi & mengarahkan ke halaman akun target.
        if (method_exists(ImpersonationService::class, 'startFromLivewire')) {
            return app(ImpersonationService::class)->startFromLivewire($this, Auth::guard('web')->user(), $model, $this->reason, (int) $this->minutes);
        }
        $this->toast('info', 'Layanan impersonasi belum dipasang.');

        return null;
    }

    public function denial(User|Member $model): ?string
    {
        return ImpersonationPolicy::actorDenial(Auth::guard('web')->user())
            ?? ($model instanceof Member ? ImpersonationPolicy::memberDenial($model) : ImpersonationPolicy::staffDenial($model, Auth::guard('web')->user()));
    }

    public function render()
    {
        return view('livewire.pengguna.impersonation', [
            'rows' => $this->tab === 'anggota' ? $this->members : $this->staff,
            'maxMinutes' => ImpersonationPolicy::MAX_MINUTES,
            'durations' => ImpersonationPolicy::DURATIONS,
            'presets' => ImpersonationPolicy::REASON_PRESETS[$this->target['type'] ?? 'petugas'] ?? [],
        ]);
    }
}
