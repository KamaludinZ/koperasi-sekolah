<?php

namespace App\Livewire\Portal;

use App\Support\GuideCatalog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portal anggota → Panduan (/portal/panduan): panduan versi anggota (beranda, simpanan, pinjaman, belanja, riwayat)
 * dengan langkah praktis berurutan; dibuka dari tombol Panduan di header portal (menumpang login portal yang ada).
 */
#[Layout('components.layouts.portal')]
#[Title('Panduan Portal')]
class Guide extends Component
{
    #[Url(as: 'topik', except: '')]
    public $selected = '';

    public function mount(): void
    {
        $this->selected = is_string($this->selected) && isset($this->entries[$this->selected]) ? $this->selected : '';
    }

    public function open(string $key): void
    {
        $this->selected = isset($this->entries[$key]) ? $key : '';
    }

    #[Computed]
    public function entries(): array
    {
        return GuideCatalog::forAudience('anggota');
    }

    public function render()
    {
        $key = $this->selected ?: array_key_first($this->entries);

        return view('livewire.portal.guide', ['current' => $this->entries[$key] ?? null, 'currentKey' => $key]);
    }
}
