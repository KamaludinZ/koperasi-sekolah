<?php

namespace App\Livewire\Panduan;

use App\Models\Role;
use App\Services\GuideMerger;
use App\Services\GuideService;
use App\Support\GuideCatalog;
use App\Support\Menu;
use App\Support\Petugas;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pusat Panduan petugas (/panduan): daftar panduan per menu dikelompokkan seperti menu samping, pencarian kata kunci
 * (judul, ringkasan, langkah, tips), dan isi panduan terpilih berupa ringkasan + langkah praktis berurutan, tips,
 * serta tautan ke halaman terkait. Sumber isi: GuideService (isi otomatis + suntingan admin).
 * Sesuai peran: hanya menu dari gabungan izin semua peran petugas; petugas multi-peran bisa menyaring per peran
 * (?peran=) dan tiap panduan menandai lewat peran mana menunya terbuka. Pengelola panduan (gate kelola-panduan) dapat
 * meninjau daftar dengan izin tiruan satu/beberapa peran (?sebagai=kasir,pengurus) tanpa mengubah akun apa pun.
 */
#[Layout('components.layouts.app')]
#[Title('Pusat Panduan')]
class Index extends Component
{
    #[Url(as: 'menu', except: '')]
    public $selected = '';

    #[Url(as: 'q', except: '')]
    public $search = '';

    /** Tampilkan juga menu yang tidak bisa dibuka (ditandai Terbatas, tanpa langkah). */
    #[Url(as: 'terbatas', except: false)]
    public $showRestricted = false;

    /** Saring panduan menu ke satu peran milik petugas (kosong = gabungan semua perannya). */
    #[Url(as: 'peran', except: '')]
    public $role = '';

    /** Pratinjau sebagai peran lain (nama peran dipisah koma) — hanya untuk pengelola panduan. */
    #[Url(as: 'sebagai', except: '')]
    public $preview = '';

    public function mount(): void
    {
        $this->normalize();
    }

    public function updated(): void
    {
        $this->normalize();
    }

    private function normalize(): void
    {
        $this->search = is_string($this->search) ? mb_substr(trim($this->search), 0, 60) : '';
        $this->preview = $this->canPreview() ? implode(',', $this->previewRoles()) : '';
        unset($this->viewer, $this->myRoles, $this->sources, $this->roleCounts, $this->restrictedCount);
        $this->selected = is_string($this->selected) && isset($this->entries[$this->selected]) ? $this->selected : '';
        $this->showRestricted = filter_var($this->showRestricted, FILTER_VALIDATE_BOOLEAN);
        $this->role = is_string($this->role) && count($this->myRoles) > 1 && in_array($this->role, $this->myRoles, true) ? $this->role : '';
        // panduan menu terbatas hanya bisa dibuka bila penandanya ditampilkan
        if ($this->selected !== '' && ! $this->showRestricted && ! $this->allowed($this->selected)) {
            $this->showRestricted = true;
        }
    }

    /** Pengelola panduan boleh meninjau daftar dengan izin tiruan peran lain. */
    public function canPreview(): bool
    {
        return (bool) Auth::guard('web')->user()?->can('kelola-panduan');
    }

    /** Nama peran pratinjau yang sah (dikenal, tanpa duplikat, urut prioritas). @return list<string> */
    private function previewRoles(): array
    {
        return app(GuideService::class)->previewRoles($this->preview);
    }

    /**
     * Pengguna yang izinnya dipakai menyaring panduan: petugas yang masuk, atau pengguna tiruan berizin gabungan
     * peran pratinjau (dibaca dari roles.permissions saat ini).
     *
     * @return array{role: ?string, roles: list<string>, permissions: list<string>, is_active: bool, mock?: bool}
     */
    #[Computed]
    public function viewer(): array
    {
        return app(GuideService::class)->viewer(Petugas::current(), $this->preview !== '' ? $this->previewRoles() : []);
    }

    /** Tambah/lepas satu peran dari pratinjau (kosong = kembali ke izin sendiri). */
    public function togglePreview(string $role): void
    {
        if (! $this->canPreview() || ! isset(Role::LABELS[$role])) {
            return;
        }
        $names = $this->previewRoles();
        $names = in_array($role, $names, true) ? array_diff($names, [$role]) : [...$names, $role];
        $this->preview = implode(',', $names);
        $this->role = '';
        $this->normalize();
    }

    public function endPreview(): void
    {
        $this->preview = '';
        $this->role = '';
        $this->normalize();
    }

    /** Pembaca boleh membuka menu panduan ini (gabungan izin semua perannya); panduan umum selalu boleh. */
    public function allowed(string $key): bool
    {
        $menu = $this->entries[$key]['menu'] ?? null;

        return $menu === null || Menu::userCan($this->viewer, $menu);
    }

    /** Pembaca boleh membuka halaman tujuan tautan langkah/halaman terkait. */
    public function reach(string $route): bool
    {
        return Menu::canReach($route, $this->viewer);
    }

    /** Peran petugas yang sedang masuk (urut prioritas). @return list<string> */
    #[Computed]
    public function myRoles(): array
    {
        return app(GuideService::class)->rolesOf($this->viewer);
    }

    /** Asal peran tiap menu milik petugas: [menu => [peran, …]]. @return array<string, list<string>> */
    #[Computed]
    public function sources(): array
    {
        return Menu::sourcesFor($this->viewer);
    }

    /**
     * Peran milik petugas yang membuka menu panduan ini — hanya untuk petugas multi-peran dan bila tidak semua
     * perannya memiliki menu itu (selain itu tidak ada yang perlu ditandai). @return list<string>
     */
    public function via(string $key): array
    {
        return app(GuideService::class)->via($this->viewer, $this->sources, $this->entries[$key]['menu'] ?? null);
    }

    /**
     * Langkah yang halamannya tidak terbuka bagi gabungan peran pembaca (mis. halaman khusus Admin di menu milik
     * bersama): daftar peran yang membukanya, untuk ditandai alih-alih disembunyikan. Null bila terbuka/tanpa tautan.
     *
     * @return ?list<string>
     */
    public function stepLock(?string $route): ?array
    {
        return app(GuideService::class)->stepLock($this->viewer, $route);
    }

    /** Jumlah menu yang dibuka tiap peran milik petugas (untuk tombol saring peran). @return array<string, int> */
    #[Computed]
    public function roleCounts(): array
    {
        return array_map('count', app(GuideMerger::class)->merge($this->viewer, $this->entries)['by_role']);
    }

    /** Peran pemegang tiap menu (satu kueri untuk seluruh halaman). @return array<string, list<string>> */
    #[Computed]
    public function grantMap(): array
    {
        return app(GuideService::class)->grantMap();
    }

    /** Peran yang membuka menu panduan terbatas ini (kosong bila belum diberikan ke peran mana pun). @return list<string> */
    public function requiredFor(string $key): array
    {
        $menu = $this->entries[$key]['menu'] ?? null;

        return $menu !== null ? ($this->grantMap[$menu] ?? []) : [];
    }

    /** Jumlah panduan menu yang tersembunyi karena tidak bisa dibuka. */
    #[Computed]
    public function restrictedCount(): int
    {
        return count(array_filter(array_keys($this->entries), fn ($k) => ! $this->allowed($k)));
    }

    public function open(string $key): void
    {
        $this->selected = isset($this->entries[$key]) && ($this->showRestricted || $this->allowed($key)) ? $key : '';
    }

    #[Computed]
    public function entries(): array
    {
        return app(GuideService::class)->all('petugas'); // isi efektif (otomatis + suntingan admin)
    }

    /** Panduan yang cocok dengan kata kunci (semua kata harus muncul), dikelompokkan. @return array<string, array<string, array>> */
    #[Computed]
    public function groups(): array
    {
        return $this->grouped($this->search);
    }

    /** @return array<string, array<string, array>> */
    private function grouped(string $search): array
    {
        $words = array_filter(explode(' ', Str::lower(Str::ascii($search))));
        $groups = [];
        // urutan baku seperti menu samping (GuideService::orderedKeys), menu terbatas di belakang tiap kelompok
        foreach (app(GuideService::class)->orderedKeys($this->entries, fn ($k) => $this->allowed($k)) as $key) {
            $e = $this->entries[$key];
            if (! $this->showRestricted && ! $this->allowed($key)) {
                continue;
            }
            if ($this->role !== '' && $e['menu'] !== null && $this->allowed($key) && ! in_array($this->role, $this->sources[$e['menu']] ?? [], true)) {
                continue; // disaring ke satu peran: menu milik peran lain disembunyikan (panduan umum & terbatas tetap)
            }
            $hay = GuideCatalog::haystack($key, $e);
            if ($words && collect($words)->contains(fn ($w) => ! str_contains($hay, $w))) {
                continue;
            }
            $groups[GuideCatalog::groupFor($e['menu'])][$key] = $e;
        }

        return $groups;
    }

    /** Kata kunci populer yang disarankan saat pencarian tidak menemukan apa pun. */
    public const SUGGESTIONS = ['shift', 'retur', 'bon', 'stok', 'kupon', 'pinjaman', 'kata sandi'];

    /** Jumlah panduan menu terbatas yang sebenarnya cocok dengan kata kunci (saat penandanya disembunyikan). */
    #[Computed]
    public function restrictedMatches(): int
    {
        if ($this->showRestricted || $this->search === '') {
            return 0;
        }
        $words = array_filter(explode(' ', Str::lower(Str::ascii($this->search))));

        return count(array_filter($this->entries, fn ($e, $k) => ! $this->allowed($k)
            && ! collect($words)->contains(fn ($w) => ! str_contains(GuideCatalog::haystack($k, $e), $w)), ARRAY_FILTER_USE_BOTH));
    }

    /** Kembali ke ikhtisar daftar panduan. */
    public function closeGuide(): void
    {
        $this->selected = '';
    }

    public function render()
    {
        // tanpa pilihan → ikhtisar kartu semua panduan (hasil pencarian); dengan pilihan → isi panduan
        $current = $this->selected !== '' ? $this->entries[$this->selected] : null;
        $restricted = $current && ! $this->allowed($this->selected);
        // sebelum/sesudah mengikuti daftar yang terlihat (tanpa kata kunci), agar tidak melompat ke panduan tersembunyi
        $visible = array_keys(array_merge([], ...array_values($this->grouped(''))));
        if ($current && ! in_array($this->selected, $visible, true)) {
            $visible = array_keys($this->entries);
        }

        return view('livewire.panduan.index', [
            'current' => $current, 'currentKey' => $this->selected, 'restricted' => $restricted,
            'requiredRoles' => $restricted ? $this->requiredFor($this->selected) : [],
            'visible' => $visible,
        ]);
    }
}
