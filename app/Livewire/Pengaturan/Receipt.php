<?php

namespace App\Livewire\Pengaturan;

use App\Services\ReceiptPresenter;
use App\Support\MockData;
use App\Livewire\Concerns\NotifiesSave;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Form pengaturan struk: judul & sub-judul (kosong = ikut profil), alamat/telepon,
 * nama kasir/anggota, catatan, tulisan kaki, ukuran kertas — dengan pratinjau struk
 * langsung memakai partial struk yang sama dengan layar kasir.
 * Tahap frontend: disimpan ke pengaturan tiruan (session).
 */
#[Layout('components.layouts.app')]
#[Title('Pengaturan Struk')]
class Receipt extends Component
{
    use NotifiesSave;
    public const PAPERS = ['58mm' => '58 mm (kasir kecil)', '80mm' => '80 mm (kasir standar)'];

    public string $header = '';

    public string $subheader = '';

    public bool $show_address = true;

    public bool $show_phone = false;

    public bool $show_cashier = true;

    public bool $show_member = true;

    public string $note = '';

    public string $footer = '';

    public string $paper = '58mm';

    public bool $saved = false;

    public function mount(): void
    {
        $r = MockData::receipt();
        $this->fill(collect($r)->map(fn ($v) => $v ?? '')->only(array_keys($this->formState()))->all());
    }

    protected function rules(): array
    {
        return [
            'header' => ['nullable', 'string', 'max:40'],
            'subheader' => ['nullable', 'string', 'max:60'],
            'show_address' => ['boolean'], 'show_phone' => ['boolean'],
            'show_cashier' => ['boolean'], 'show_member' => ['boolean'],
            'note' => ['nullable', 'string', 'max:160', function ($a, $v, $fail) {
                if (substr_count((string) $v, "\n") > 2) {
                    $fail('Maksimal 3 baris.');
                }
            }],
            'footer' => ['required', 'string', 'max:160', function ($a, $v, $fail) {
                if (substr_count((string) $v, "\n") > 2) {
                    $fail('Maksimal 3 baris.');
                }
            }],
            'paper' => ['required', 'in:'.implode(',', array_keys(self::PAPERS))],
        ];
    }

    protected function messages(): array
    {
        return [
            'header.max' => 'Maksimal :max karakter agar muat satu baris.',
            'subheader.max' => 'Maksimal :max karakter.',
            'footer.required' => 'Tulisan kaki struk wajib diisi.',
            'max' => 'Maksimal :max karakter.',
        ];
    }

    public function save(): void
    {
        $this->saved = false;
        $this->saveWithToast(function () {
            $data = $this->validate();
            $data['header'] = trim($data['header'] ?? '') ?: null;
            $data['subheader'] = trim($data['subheader'] ?? '') ?: null;
            $data['note'] = trim($data['note'] ?? '') ?: null;
            $data['footer'] = trim($data['footer']);

            MockData::saveSettings('receipt', $data);
            $this->saved = true;
        }, 'Pengaturan struk disimpan. Struk berikutnya memakai format baru.');
    }

    public function resetDefaults(): void
    {
        $this->fill(collect(MockData::defaultReceipt())->map(fn ($v) => $v ?? '')->only(array_keys($this->formState()))->all());
        $this->resetValidation();
    }

    /** Profil + pengaturan struk dari isian form (untuk pratinjau). */
    public function previewProfile(): array
    {
        return app(ReceiptPresenter::class)->profile([...$this->formState(), 'header' => $this->header ?: null, 'subheader' => $this->subheader ?: null]);
    }

    /** Transaksi contoh untuk pratinjau. */
    public function sample(): array
    {
        return [
            'invoice_number' => 'INV-'.now()->format('Ymd').'-0012',
            'created_at' => now()->setTime(9, 41)->toDateTimeString(),
            'cashier' => auth()->user()?->name ?? 'Bu Rina',
            'member' => 'Andi Pratama (7A)',
            'items' => [
                ['product_name' => 'Buku Tulis 38 Lembar', 'price' => 4000, 'quantity' => 2, 'subtotal' => 8000],
                ['product_name' => 'Pulpen Hitam', 'price' => 3000, 'quantity' => 1, 'subtotal' => 3000],
            ],
            'subtotal' => 11000, 'discount' => 0, 'tax' => 0, 'total' => 11000,
            'paid' => 20000, 'change' => 9000, 'payment_method_label' => 'Tunai',
        ];
    }

    private function formState(): array
    {
        return collect(['header', 'subheader', 'show_address', 'show_phone', 'show_cashier', 'show_member', 'note', 'footer', 'paper'])
            ->mapWithKeys(fn ($k) => [$k => $this->{$k}])->all();
    }

    public function render()
    {
        return view('livewire.pengaturan.receipt');
    }
}
