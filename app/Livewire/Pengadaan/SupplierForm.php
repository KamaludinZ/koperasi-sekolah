<?php

namespace App\Livewire\Pengadaan;

use App\Livewire\Concerns\NotifiesSave;
use App\Models\Category;
use App\Models\Supplier;
use App\Services\SupplierService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Pengadaan → Supplier → Tambah/Ubah (Admin & Pengurus): nama (unik), kontak, telepon, alamat,
 * syarat pembayaran (tunai atau tempo N hari), kategori barang yang dipasok (dari kategori yang
 * ada), catatan, dan status aktif. Disimpan lewat SupplierService (tercatat di audit).
 */
#[Layout('components.layouts.app')]
#[Title('Supplier')]
class SupplierForm extends Component
{
    use NotifiesSave;

    public const TERMS = [0 => 'Tunai', 7 => 'Tempo 7 hari', 14 => 'Tempo 14 hari', 30 => 'Tempo 30 hari'];

    public ?int $supplierId = null;

    public string $name = '';

    public string $contact = '';

    public string $phone = '';

    public string $address = '';

    public string $termsChoice = '0';

    public int|string|null $customDays = null;

    /** @var list<string> */
    public array $categories = [];

    public string $note = '';

    public bool $is_active = true;

    public function mount(?int $supplier = null): void
    {
        if ($supplier === null) {
            return;
        }
        $model = Supplier::with('categories')->findOrFail($supplier);
        $s = SupplierService::toArray($model);
        $this->supplierId = $supplier;
        $this->fill(['name' => $s['name'], 'contact' => (string) $s['contact'], 'phone' => $s['phone'], 'address' => (string) $s['address'],
            'categories' => $s['categories'], 'is_active' => $s['is_active']]);
        $this->note = (string) $s['note'];
        $preset = array_key_exists($s['terms_days'], self::TERMS);
        $this->termsChoice = $preset ? (string) $s['terms_days'] : 'lain';
        $this->customDays = $preset ? null : $s['terms_days'];
    }

    #[Computed]
    public function categoryOptions(): array
    {
        return Category::ordered()->pluck('name')->all();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:100', function ($a, $v, $fail) {
                $taken = Supplier::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($v))])
                    ->when($this->supplierId, fn ($q) => $q->whereKeyNot($this->supplierId))->exists();
                if ($taken) {
                    $fail('Nama supplier sudah terdaftar.');
                }
            }],
            'contact' => ['nullable', 'string', 'max:60'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{6,20}$/'],
            'address' => ['nullable', 'string', 'max:200'],
            'termsChoice' => ['required', 'in:0,7,14,30,lain'],
            'customDays' => ['nullable', 'required_if:termsChoice,lain', 'integer', 'min:1', 'max:120'],
            'categories' => ['array'],
            'categories.*' => ['in:'.implode(',', $this->categoryOptions)],
            'note' => ['nullable', 'string', 'max:200'],
            'is_active' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Isi nama supplier.',
            'name.min' => 'Nama minimal 3 huruf.',
            'phone.required' => 'Isi nomor telepon supplier.',
            'phone.regex' => 'Nomor telepon tidak valid.',
            'customDays.required_if' => 'Isi lama tempo dalam hari.',
            'customDays.min' => 'Tempo minimal 1 hari.',
            'customDays.max' => 'Tempo maksimal 120 hari.',
            'categories.*.in' => 'Kategori tidak dikenal.',
            'max' => 'Maksimal :max karakter.',
        ];
    }

    public function save()
    {
        $this->name = preg_replace('/\s+/', ' ', trim($this->name));
        try {
            $this->validate();
        } catch (ValidationException $e) {
            $this->toast('error', 'Belum tersimpan: periksa isian yang ditandai merah.');
            $this->dispatch('focus-first-error');

            throw $e;
        }

        $data = [
            'name' => $this->name,
            'contact' => trim($this->contact) ?: null,
            'phone' => trim($this->phone),
            'address' => trim($this->address) ?: null,
            'terms_days' => $this->termsChoice === 'lain' ? (int) $this->customDays : (int) $this->termsChoice,
            'categories' => array_values(array_intersect($this->categoryOptions, $this->categories)),
            'note' => trim($this->note) ?: null,
            'is_active' => $this->is_active,
        ];
        $service = app(SupplierService::class);
        $saved = $this->supplierId ? $service->update(Supplier::findOrFail($this->supplierId), $data) : $service->create($data);
        session()->flash('toast', ['type' => 'success', 'message' => $this->supplierId ? "Supplier {$saved->name} diperbarui." : "Supplier {$saved->name} ditambahkan."]);

        return $this->redirectRoute('pengadaan.supplier', navigate: true);
    }

    public function render()
    {
        return view('livewire.pengadaan.supplier-form');
    }
}
