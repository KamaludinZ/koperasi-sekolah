<?php

namespace App\Livewire\Panduan;

use App\Support\GuideCatalog;
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
    }

    public function open(string $key): void
    {
        $this->selected = isset($this->entries[$key]) ? $key : '';
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
            $hay = Str::lower(Str::ascii(implode(' ', [$e['title'], $e['summary'], ...array_column($e['steps'], 'text'), ...$e['tips']])));
            if ($words && collect($words)->contains(fn ($w) => ! str_contains($hay, $w))) {
                continue;
            }
            $groups[GuideCatalog::groupOf($key)][$key] = $e;
        }

        return $groups;
    }

    public function render()
    {
        $current = $this->selected !== '' ? $this->entries[$this->selected] : null;
        $groups = $this->groups;
        if (! $current && $groups) { // pilih otomatis panduan pertama dari hasil
            $first = array_key_first(reset($groups));
            $current = $this->entries[$first];
            $currentKey = $first;
        }

        return view('livewire.panduan.index', ['current' => $current, 'currentKey' => $currentKey ?? $this->selected]);
    }
}
