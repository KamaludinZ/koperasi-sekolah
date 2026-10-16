<?php

namespace App\Livewire\Pengaturan;

use App\Livewire\Concerns\GuardsSettings;
use App\Livewire\Concerns\NotifiesSave;
use App\Services\SettingsService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Form Diskon manual kasir: boleh/tidaknya kasir memberi potongan manual, batas persen & rupiah per transaksi
 * tanpa admin, dan wajib/tidaknya alasan. Dipakai ManualDiscountService di kasir; disimpan ke tabel settings
 * bagian `discount` lewat SettingsService (tercatat di audit).
 */
#[Layout('components.layouts.app')]
#[Title('Diskon Manual Kasir')]
class Discount extends Component
{
    use GuardsSettings;
    use NotifiesSave;

    public const LIMITS = ['max_percent' => [1, 100], 'max_amount' => [0, 10000000]];

    public bool $enabled = true;

    public int|string|null $max_percent = 10;

    public int|string|null $max_amount = 20000;

    public bool $reason_required = true;

    public function mount(): void
    {
        $this->fill(app(SettingsService::class)->discount());
    }

    protected function rules(): array
    {
        return collect(self::LIMITS)->map(fn ($l) => ['required', 'integer', 'min:'.$l[0], 'max:'.$l[1]])->all() + [
            'enabled' => ['boolean'],
            'reason_required' => ['boolean'],
        ];
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
        return collect(self::LIMITS)->keys()->mapWithKeys(fn ($k) => [$k => (int) $this->{$k}])->all()
            + ['enabled' => $this->enabled, 'reason_required' => $this->reason_required];
    }

    public function save(): void
    {
        if (! $this->ensureCanEdit('discount')) {
            return;
        }
        $this->saveWithToast(function () {
            $this->validate();
            $this->fill(app(SettingsService::class)->save('discount', $this->current())['discount']);
        }, 'Pengaturan diskon manual disimpan.');
    }

    public function restoreDefaults(): void
    {
        if (! $this->ensureCanEdit('discount')) {
            return;
        }
        $this->resetValidation();
        $this->fill(SettingsService::DEFAULTS['discount']);
        $this->toast('success', 'Isian dikembalikan ke bawaan. Tekan Simpan untuk menerapkan.');
    }

    public function render()
    {
        return view('livewire.pengaturan.discount');
    }
}
