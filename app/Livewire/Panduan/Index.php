<?php

namespace App\Livewire\Panduan;

use App\Support\GuideCatalog;
use App\Support\Menu;
use App\Support\Petugas;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pusat Panduan petugas (/panduan): daftar panduan per menu dikelompokkan seperti menu samping, pencarian kata kunci
 * (judul, ringkasan, langkah, tips), dan isi panduan terpilih berupa ringkasan + langkah praktis berurutan, tips,
 * serta tautan ke halaman terkait. Sumber isi: GuideCatalog.
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
        $this->selected = is_string($this->selected) && isset($this->entries[$this->selected]) ? $this->selected : '';
        $this->showRestricted = filter_var($this->showRestricted, FILTER_VALIDATE_BOOLEAN);
        // panduan menu terbatas hanya bisa dibuka bila penandanya ditampilkan
        if ($this->selected !== '' && ! $this->showRestricted && ! $this->allowed($this->selected)) {
            $this->showRestricted = true;
        }
    }

    /** Petugas boleh membuka menu panduan ini (gabungan izin semua perannya); panduan umum selalu boleh. */
    public function allowed(string $key): bool
    {
        $menu = $this->entries[$key]['menu'] ?? null;

        return $menu === null || Menu::userCan(Petugas::current() ?? ['permissions' => []], $menu);
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
        return GuideCatalog::forAudience('petugas');
    }

    /** Panduan yang cocok dengan kata kunci (semua kata harus muncul), dikelompokkan. @return array<string, array<string, array>> */
    #[Computed]
    public function groups(): array
    {
        $words = array_filter(explode(' ', Str::lower(Str::ascii($this->search))));
        $groups = [];
        foreach ($this->entries as $key => $e) {
            if (! $this->showRestricted && ! $this->allowed($key)) {
                continue;
            }
            $hay = GuideCatalog::haystack($key, $e);
            if ($words && collect($words)->contains(fn ($w) => ! str_contains($hay, $w))) {
                continue;
            }
            $groups[GuideCatalog::groupOf($key)][$key] = $e;
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

        return view('livewire.panduan.index', [
            'current' => $current, 'currentKey' => $this->selected, 'restricted' => $restricted,
            'requiredRoles' => $restricted ? Menu::grantingRoles($current['menu']) : [],
        ]);
    }
}
