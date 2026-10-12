<?php

namespace App\Livewire\Pengaturan;

use App\Support\MockData;
use App\Support\PriceCalculator;
use App\Livewire\Concerns\GuardsSettings;
use App\Livewire\Concerns\NotifiesSave;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Form Pajak & Pembulatan: aktifkan pajak, nama & tarif, sudah termasuk harga atau
 * ditambahkan, cara & kelipatan pembulatan, hanya tunai atau semua metode; dengan
 * kalkulator contoh (nominal bisa diubah) memakai PriceCalculator yang sama dengan kasir.
 * Disimpan ke tabel settings lewat SettingsService.
 */
#[Layout('components.layouts.app')]
#[Title('Pajak & Pembulatan')]
class Tax extends Component
{
    use GuardsSettings;
    use NotifiesSave;
    public bool $enabled = false;

    public string $label = 'Pajak';

    public string $rate = '0';

    public bool $included = false;

    public string $rounding = 'none';

    public int $rounding_unit = 100;

    public bool $cash_only = true;

    /** Nominal belanja untuk kalkulator contoh. */
    public ?int $sample = 12340;

    public bool $saved = false;

    public function mount(): void
    {
        $t = MockData::tax();
        $this->fill([
            'enabled' => (bool) $t['enabled'], 'label' => $t['label'] ?? 'Pajak', 'rate' => (string) ($t['rate'] + 0),
            'included' => (bool) $t['included'], 'rounding' => $t['rounding'], 'rounding_unit' => (int) $t['rounding_unit'],
            'cash_only' => (bool) $t['cash_only'],
        ]);
    }

    protected function rules(): array
    {
        return [
            'enabled' => ['boolean'],
            'label' => ['required', 'string', 'max:20'],
            'rate' => ['required', 'string', 'regex:/^\d{1,3}([.,]\d{1,2})?$/', function ($a, $v, $fail) {
                $rate = (float) str_replace(',', '.', $v);
                if ($rate > 100) {
                    $fail('Tarif maksimal 100%.');
                } elseif ($this->enabled && $rate <= 0) {
                    $fail('Isi tarif pajak lebih dari 0, atau matikan pajak.');
                }
            }],
            'included' => ['boolean'],
            'rounding' => ['required', 'in:'.implode(',', array_keys(PriceCalculator::ROUNDING))],
            'rounding_unit' => ['required', 'in:'.implode(',', PriceCalculator::UNITS)],
            'cash_only' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'label.required' => 'Nama pajak wajib diisi, mis. PPN.',
            'rate.required' => 'Isi tarif pajak.',
            'rate.regex' => 'Tulis angka saja, maksimal 2 angka di belakang koma (mis. 11 atau 2,5).',
            'max' => 'Maksimal :max karakter.',
        ];
    }

    protected function normalizedRate(): float
    {
        return is_numeric(str_replace(',', '.', $this->rate)) ? (float) str_replace(',', '.', $this->rate) : 0.0;
    }

    /** Pengaturan dari isian form saat ini. */
    public function current(): array
    {
        return [
            'label' => trim($this->label) ?: 'Pajak',
            'enabled' => $this->enabled,
            'rate' => $this->enabled ? $this->normalizedRate() : 0,
            'included' => $this->included,
            'rounding' => $this->rounding,
            'rounding_unit' => $this->rounding_unit,
            'cash_only' => $this->cash_only,
        ];
    }

    /** Kalkulator contoh untuk tunai & non-tunai. */
    public function preview(): array
    {
        $amount = max(0, min((int) $this->sample, 100_000_000));

        return [
            'tunai' => PriceCalculator::compute($amount, $this->current(), 'tunai'),
            'qris' => PriceCalculator::compute($amount, $this->current(), 'qris'),
        ];
    }

    public function save(): void
    {
        $this->saved = false;
        if (! $this->ensureCanEdit('tax')) {
            return;
        }
        $this->saveWithToast(function () {
            $this->validate();

            MockData::saveSettings('tax', $this->current());
            $this->rate = (string) ($this->current()['rate'] + 0);
            $this->saved = true;
        }, 'Pengaturan pajak & pembulatan disimpan.');
    }

    public function render()
    {
        return view('livewire.pengaturan.tax');
    }
}
