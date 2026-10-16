<?php

namespace App\Livewire\Pengaturan;

use App\Livewire\Concerns\GuardsSettings;
use App\Livewire\Concerns\NotifiesSave;
use App\Services\SettingsService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Form Layar kasir: berapa banyak barang tampil di daftar pilih kasir, periode hitung barang paling laku (urutan
 * daftar), dan jumlah barang berlabel "Terlaris". Disimpan ke tabel settings bagian `cashier` lewat SettingsService
 * (tercatat di audit).
 */
#[Layout('components.layouts.app')]
#[Title('Layar Kasir')]
class Cashier extends Component
{
    use GuardsSettings;
    use NotifiesSave;

    public const LIMITS = SettingsService::CASHIER_LIMITS;

    public int|string|null $picker_limit = 60;

    public int|string|null $bestseller_days = 30;

    public int|string|null $bestseller_badges = 8;

    public function mount(): void
    {
        $this->fill(app(SettingsService::class)->cashier());
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
        if (! $this->ensureCanEdit('cashier')) {
            return;
        }
        $this->saveWithToast(function () {
            $this->validate();
            $this->fill(app(SettingsService::class)->save('cashier', $this->current())['cashier']);
        }, 'Pengaturan layar kasir disimpan. Urutan barang terlaris diperbarui paling lambat 10 menit.');
    }

    public function restoreDefaults(): void
    {
        if (! $this->ensureCanEdit('cashier')) {
            return;
        }
        $this->resetValidation();
        $this->fill(SettingsService::DEFAULTS['cashier']);
        $this->toast('success', 'Isian dikembalikan ke bawaan. Tekan Simpan untuk menerapkan.');
    }

    public function render()
    {
        return view('livewire.pengaturan.cashier');
    }
}
