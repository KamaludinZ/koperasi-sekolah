<?php

namespace App\Livewire\Pengaturan;

use App\Livewire\Concerns\GuardsSettings;
use App\Livewire\Concerns\NotifiesSave;
use App\Services\SettingsService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Form Retur & Pembatalan: batas hari pengajuan setelah pembelian dan aturan nilai batas pembatalan
 * yang disetujui otomatis (nilai maksimal & menit sejak transaksi, oleh kasir yang sama). Disimpan ke
 * tabel settings bagian `returns` lewat SettingsService (tercatat di audit).
 */
#[Layout('components.layouts.app')]
#[Title('Retur & Pembatalan')]
class Returns extends Component
{
    use GuardsSettings;
    use NotifiesSave;

    public const LIMITS = SettingsService::RETURN_LIMITS;

    public int|string|null $limit_days = 3;

    public int|string|null $void_auto_max = 20000;

    public int|string|null $void_auto_minutes = 30;

    public function mount(): void
    {
        $this->fill(app(SettingsService::class)->returns());
    }

    protected function rules(): array
    {
        return collect(self::LIMITS)->map(fn ($l) => ['required', 'integer', 'min:'.$l[0], 'max:'.$l[1]])->all();
    }

    protected function messages(): array
    {
        return ['required' => 'Wajib diisi.', 'integer' => 'Isi dengan angka bulat.', 'min' => 'Minimal :min.', 'max' => 'Maksimal :max.'];
    }

    public function updated(string $property): void
    {
        if (array_key_exists($property, self::LIMITS)) {
            $this->validateOnly($property);
        }
    }

    public function current(): array
    {
        return collect(self::LIMITS)->keys()->mapWithKeys(fn ($k) => [$k => (int) $this->{$k}])->all();
    }

    public function save(): void
    {
        if (! $this->ensureCanEdit('returns')) {
            return;
        }
        $this->saveWithToast(function () {
            $this->validate();
            $this->fill(app(SettingsService::class)->save('returns', $this->current())['returns']);
        }, 'Pengaturan retur & pembatalan disimpan.');
    }

    public function restoreDefaults(): void
    {
        if (! $this->ensureCanEdit('returns')) {
            return;
        }
        $this->resetValidation();
        $this->fill(SettingsService::DEFAULTS['returns']);
        $this->toast('success', 'Isian dikembalikan ke bawaan. Tekan Simpan untuk menerapkan.');
    }

    public function render()
    {
        return view('livewire.pengaturan.returns');
    }
}
