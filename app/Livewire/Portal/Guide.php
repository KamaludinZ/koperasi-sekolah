<?php

namespace App\Livewire\Portal;

use App\Services\GuideService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portal anggota → Panduan (/portal/panduan): panduan versi anggota (beranda, simpanan, pinjaman, belanja, riwayat)
 * dengan langkah praktis berurutan; dibuka dari tombol Panduan di header portal (menumpang login portal yang ada).
 * Tombol header membawa topik halaman yang sedang dibuka (?topik=) dan halaman asal (?dari=) untuk tautan kembali.
 * Centang langkah tersimpan di peramban per anggota (kemudahan pribadi, bukan data aplikasi).
 */
#[Layout('components.layouts.portal')]
#[Title('Panduan Portal')]
class Guide extends Component
{
    #[Url(as: 'topik', except: '')]
    public $selected = '';

    /** Halaman portal asal (nama rute) untuk tautan "Kembali". */
    #[Url(as: 'dari', except: '')]
    public $from = '';

    public function mount(): void
    {
        $this->selected = is_string($this->selected) && isset($this->entries[$this->selected]) ? $this->selected : '';
        $this->from = $this->validFrom($this->from) ? $this->from : '';
    }

    public function open(string $key): void
    {
        $this->selected = isset($this->entries[$key]) ? $key : '';
    }

    #[Computed]
    public function entries(): array
    {
        return app(GuideService::class)->all('anggota');
    }

    /** Hanya halaman portal tanpa parameter yang bisa dijadikan tujuan kembali. */
    private function validFrom(mixed $name): bool
    {
        if (! is_string($name) || $name === 'portal.panduan' || ! preg_match('/^portal(\.[a-z-]+)*$/', $name) || ! Route::has($name)) {
            return false;
        }
        $route = Route::getRoutes()->getByName($name);

        return $route->parameterNames() === [] && in_array('GET', $route->methods(), true);
    }

    /** Tautan "Buka →" pada langkah: halaman portal tanpa parameter (checkout butuh keranjang terisi, jadi tidak). */
    public function stepLink(?string $route): ?string
    {
        return $route && $route !== 'portal.checkout' && $this->validFrom($route) ? route($route) : null;
    }

    public function render()
    {
        $keys = array_keys($this->entries);
        $key = $this->selected ?: ($keys[0] ?? null);
        $pos = $key !== null ? array_search($key, $keys, true) : false;

        return view('livewire.portal.guide', [
            'current' => $key !== null ? $this->entries[$key] : null, 'currentKey' => $key,
            'prev' => $pos > 0 ? $keys[$pos - 1] : null,
            'next' => $pos !== false && $pos < count($keys) - 1 ? $keys[$pos + 1] : null,
            'memberId' => Auth::guard('member')->id(),
        ]);
    }
}
