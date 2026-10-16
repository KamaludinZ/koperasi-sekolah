<?php

namespace App\Livewire\Promo;

use App\Livewire\Concerns\NotifiesSave;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promo;
use App\Services\PromoManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Diskon & Promo → Buat/Ubah promo otomatis (Admin & Pengurus, gate kelola-promo): jenis (potongan persen,
 * potongan rupiah per barang, beli X gratis Y, harga grosir), cakupan (semua barang, kategori, atau barang
 * tertentu), syarat minimal jumlah & belanja, periode, hari & jam berlaku, dan saklar aktif. Promo yang belum
 * pernah dipakai bisa dihapus. Disimpan lewat PromoManager (tercatat di audit).
 */
#[Layout('components.layouts.app')]
#[Title('Promo')]
class PromoForm extends Component
{
    use NotifiesSave;

    public ?int $promoId = null;

    public string $name = '';

    public string $type = 'persen';

    public int|string|null $value = null;

    public int|string|null $buy_qty = 2;

    public int|string|null $free_qty = 1;

    public int|string|null $min_qty = 1;

    public int|string|null $min_purchase = 0;

    public string $scope = 'semua';

    /** @var list<int|string> */
    public array $category_ids = [];

    /** @var list<int> */
    public array $product_ids = [];

    public string $productSearch = '';

    public string $starts_on = '';

    public string $ends_on = '';

    /** @var list<int|string> */
    public array $days = [1, 2, 3, 4, 5, 6];

    public bool $timed = false;

    public string $time_from = '';

    public string $time_to = '';

    public bool $is_active = true;

    public function mount(?int $promo = null): void
    {
        Gate::authorize('kelola-promo');
        $this->starts_on = today()->toDateString();
        $this->ends_on = today()->addDays(30)->toDateString();
        if ($promo === null) {
            return;
        }
        $model = Promo::with('targets')->findOrFail($promo);
        $this->promoId = $model->id;
        $this->fill([
            'name' => $model->name, 'type' => $model->type, 'value' => (int) round((float) $model->value),
            'buy_qty' => $model->buy_qty ?? 2, 'free_qty' => $model->free_qty ?? 1, 'min_qty' => $model->min_qty,
            'min_purchase' => (int) round((float) $model->min_purchase), 'scope' => $model->scope,
            'category_ids' => $model->targets->pluck('category_id')->filter()->values()->all(),
            'product_ids' => $model->targets->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->values()->all(),
            'starts_on' => $model->starts_on->toDateString(), 'ends_on' => $model->ends_on->toDateString(),
            'days' => array_map('intval', $model->days ?? []), 'is_active' => $model->is_active,
        ]);
        if ($model->time_from && $model->time_to) {
            $this->timed = true;
            $this->time_from = substr($model->time_from, 0, 5);
            $this->time_to = substr($model->time_to, 0, 5);
        }
    }

    #[Computed]
    public function categoryOptions(): array
    {
        return Category::ordered()->pluck('name', 'id')->all();
    }

    /** Barang terpilih (id → nama, harga), urut nama. */
    #[Computed]
    public function selectedProducts(): array
    {
        return Product::whereIn('id', $this->product_ids)->orderBy('name')->get(['id', 'code', 'name', 'price'])
            ->map(fn (Product $p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'price' => (int) $p->price])->all();
    }

    /** Hasil cari barang aktif (nama/kode/barcode), di luar yang sudah dipilih. */
    #[Computed]
    public function productResults(): array
    {
        $q = trim($this->productSearch);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)).'%';

        return Product::active()->whereNotIn('id', $this->product_ids)
            ->where(fn ($w) => $w->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])->orWhereRaw("LOWER(code) LIKE ? ESCAPE '\\'", [$like])->orWhere('barcode', $q))
            ->orderBy('name')->limit(8)->get(['id', 'code', 'name', 'price'])
            ->map(fn (Product $p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'price' => (int) $p->price])->all();
    }

    /** Sudah dipakai di transaksi → tidak bisa dihapus, hanya dinonaktifkan. */
    #[Computed]
    public function usage(): int
    {
        return $this->promoId ? Promo::find($this->promoId)?->discounts()->count() ?? 0 : 0;
    }

    public function addProduct(int $id): void
    {
        if (count($this->product_ids) >= PromoManager::MAX_TARGETS) {
            $this->addError('targets', 'Paling banyak '.PromoManager::MAX_TARGETS.' barang per promo.');

            return;
        }
        if (Product::active()->whereKey($id)->exists() && ! in_array($id, $this->product_ids, true)) {
            $this->product_ids[] = $id;
        }
        $this->productSearch = '';
        $this->resetErrorBag('targets');
    }

    public function removeProduct(int $id): void
    {
        $this->product_ids = array_values(array_diff($this->product_ids, [$id]));
    }

    protected function rules(): array
    {
        $creating = $this->promoId === null;

        return [
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'type' => ['required', 'in:'.implode(',', array_keys(Promo::TYPES))],
            'value' => match ($this->type) {
                'persen' => ['required', 'integer', 'min:1', 'max:100'],
                'beli_gratis' => ['nullable'],
                default => ['required', 'integer', 'min:1', 'max:10000000'],
            },
            'buy_qty' => $this->type === 'beli_gratis' ? ['required', 'integer', 'min:1', 'max:100'] : ['nullable'],
            'free_qty' => $this->type === 'beli_gratis' ? ['required', 'integer', 'min:1', 'max:100'] : ['nullable'],
            'min_qty' => match ($this->type) {
                'beli_gratis' => ['nullable'],
                'grosir' => ['required', 'integer', 'min:2', 'max:1000'],
                default => ['required', 'integer', 'min:1', 'max:1000'],
            },
            'min_purchase' => ['required', 'integer', 'min:0', 'max:100000000'],
            'scope' => ['required', 'in:'.implode(',', array_keys(Promo::SCOPES))],
            'category_ids' => $this->scope === 'kategori' ? ['required', 'array', 'min:1', 'max:'.PromoManager::MAX_TARGETS] : ['array'],
            'category_ids.*' => ['integer', 'in:'.implode(',', array_keys($this->categoryOptions) ?: [0])],
            'product_ids' => $this->scope === 'barang' ? ['required', 'array', 'min:1', 'max:'.PromoManager::MAX_TARGETS] : ['array'],
            'product_ids.*' => ['integer'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => array_merge(['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'], $creating ? ['after_or_equal:today'] : []),
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['integer', 'between:1,7'],
            'time_from' => $this->timed ? ['required', 'date_format:H:i'] : ['nullable'],
            'time_to' => $this->timed ? ['required', 'date_format:H:i', 'after:time_from'] : ['nullable'],
            'is_active' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Isi nama promo.',
            'name.min' => 'Nama minimal 3 huruf.',
            'value.required' => match ($this->type) {
                'persen' => 'Isi besar potongan (persen).',
                'grosir' => 'Isi harga grosir per pcs.',
                default => 'Isi besar potongan per barang.',
            },
            'value.max' => $this->type === 'persen' ? 'Potongan maksimal 100%.' : 'Maksimal Rp10.000.000.',
            'min_qty.min' => $this->type === 'grosir' ? 'Harga grosir berlaku mulai minimal 2 pcs.' : 'Minimal 1.',
            'category_ids.required' => 'Pilih minimal satu kategori.',
            'category_ids.*.in' => 'Kategori tidak dikenal.',
            'product_ids.required' => 'Pilih minimal satu barang.',
            'ends_on.after_or_equal' => 'Tanggal berakhir tidak boleh sebelum tanggal mulai atau hari ini.',
            'days.required' => 'Pilih minimal satu hari berlaku.',
            'time_from.required' => 'Isi jam mulai.',
            'time_to.required' => 'Isi jam selesai.',
            'time_to.after' => 'Jam selesai harus setelah jam mulai.',
            'required' => 'Wajib diisi.',
            'integer' => 'Isi dengan angka bulat.',
            'min' => 'Minimal :min.',
            'max' => 'Maksimal :max.',
            'date_format' => 'Format tidak valid.',
        ];
    }

    public function save()
    {
        Gate::authorize('kelola-promo');
        $this->name = preg_replace('/\s+/', ' ', trim($this->name));
        $this->category_ids = array_values(array_unique(array_map('intval', $this->category_ids)));
        try {
            $data = $this->validate();
            $model = $this->promoId ? Promo::findOrFail($this->promoId) : null;
            $saved = app(PromoManager::class)->savePromo($model, [...$data, 'time_from' => $this->timed ? $this->time_from : null,
                'time_to' => $this->timed ? $this->time_to : null], auth()->user());
        } catch (ValidationException $e) {
            $this->toast('error', 'Belum tersimpan: periksa isian yang ditandai merah.');
            $this->dispatch('focus-first-error');

            throw $e;
        }
        session()->flash('toast', ['type' => 'success', 'message' => $this->promoId ? "Promo {$saved->name} diperbarui." : "Promo {$saved->name} dibuat."]);

        return $this->redirectRoute('promo', ['status' => $saved->status === 'aktif' ? null : $saved->status], navigate: true);
    }

    public function delete()
    {
        Gate::authorize('kelola-promo');
        $model = Promo::findOrFail($this->promoId);
        try {
            app(PromoManager::class)->delete($model);
        } catch (ValidationException $e) {
            $this->toast('error', $e->getMessage());

            return null;
        }
        session()->flash('toast', ['type' => 'success', 'message' => "Promo {$model->name} dihapus."]);

        return $this->redirectRoute('promo', navigate: true);
    }

    public function render()
    {
        return view('livewire.promo.promo-form');
    }
}
