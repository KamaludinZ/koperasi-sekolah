<?php

namespace App\Livewire\Promo;

use App\Livewire\Concerns\NotifiesSave;
use App\Models\Coupon;
use App\Services\PromoManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Diskon & Promo → Buat/Ubah kupon (Admin & Pengurus, gate kelola-promo): kode (huruf besar tanpa spasi,
 * unik), nama, potongan persen (dengan batas maksimal) atau rupiah, minimal belanja, kuota total & per
 * anggota, masa berlaku, dan saklar aktif. Kupon yang belum pernah dipakai bisa dihapus. Disimpan lewat
 * PromoManager (tercatat di audit).
 */
#[Layout('components.layouts.app')]
#[Title('Kupon')]
class CouponForm extends Component
{
    use NotifiesSave;

    public ?int $couponId = null;

    public string $code = '';

    public string $name = '';

    public string $type = 'nominal';

    public int|string|null $value = null;

    public int|string|null $max_discount = null;

    public int|string|null $min_purchase = 0;

    public int|string|null $quota = null;

    public int|string|null $per_member = 1;

    public string $starts_on = '';

    public string $ends_on = '';

    public bool $is_active = true;

    public function mount(?int $coupon = null): void
    {
        Gate::authorize('kelola-promo');
        $this->starts_on = today()->toDateString();
        $this->ends_on = today()->addDays(30)->toDateString();
        if ($coupon === null) {
            return;
        }
        $model = Coupon::findOrFail($coupon);
        $this->couponId = $model->id;
        $this->fill([
            'code' => $model->code, 'name' => $model->name, 'type' => $model->type, 'value' => (int) round((float) $model->value),
            'max_discount' => $model->max_discount !== null ? (int) round((float) $model->max_discount) : null,
            'min_purchase' => (int) round((float) $model->min_purchase), 'quota' => $model->quota, 'per_member' => $model->per_member,
            'starts_on' => $model->starts_on->toDateString(), 'ends_on' => $model->ends_on->toDateString(), 'is_active' => $model->is_active,
        ]);
    }

    #[Computed]
    public function used(): int
    {
        return $this->couponId ? (int) Coupon::whereKey($this->couponId)->value('used_count') : 0;
    }

    public function updatedCode(): void
    {
        $this->code = Coupon::normalize($this->code);
    }

    protected function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[A-Z0-9_-]+$/'],
            'name' => ['required', 'string', 'min:3', 'max:100'],
            'type' => ['required', 'in:'.implode(',', array_keys(Coupon::TYPES))],
            'value' => $this->type === 'persen' ? ['required', 'integer', 'min:1', 'max:100'] : ['required', 'integer', 'min:1', 'max:10000000'],
            'max_discount' => $this->type === 'persen' ? ['nullable', 'integer', 'min:1', 'max:10000000'] : ['nullable'],
            'min_purchase' => ['required', 'integer', 'min:0', 'max:100000000'],
            'quota' => ['nullable', 'integer', 'min:'.max(1, $this->used), 'max:1000000'],
            'per_member' => ['nullable', 'integer', 'min:1', 'max:100'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => array_merge(['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'], $this->couponId ? [] : ['after_or_equal:today']),
            'is_active' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'code.required' => 'Isi kode kupon.',
            'code.min' => 'Kode minimal 3 karakter.',
            'code.regex' => 'Kode hanya huruf, angka, tanda - atau _.',
            'name.required' => 'Isi nama kupon.',
            'name.min' => 'Nama minimal 3 huruf.',
            'value.required' => $this->type === 'persen' ? 'Isi besar potongan (persen).' : 'Isi besar potongan (rupiah).',
            'value.max' => $this->type === 'persen' ? 'Potongan maksimal 100%.' : 'Maksimal Rp10.000.000.',
            'quota.min' => $this->used > 0 ? "Kuota tidak boleh di bawah pemakaian ({$this->used}×)." : 'Minimal 1. Kosongkan untuk tanpa batas.',
            'ends_on.after_or_equal' => 'Tanggal berakhir tidak boleh sebelum tanggal mulai atau hari ini.',
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
        $this->code = Coupon::normalize($this->code);
        $this->name = preg_replace('/\s+/', ' ', trim($this->name));
        foreach (['max_discount', 'quota', 'per_member'] as $optional) {
            if ($this->{$optional} === '') {
                $this->{$optional} = null;
            }
        }
        try {
            $data = $this->validate();
            $model = $this->couponId ? Coupon::findOrFail($this->couponId) : null;
            $saved = app(PromoManager::class)->saveCoupon($model, $data, auth()->user());
        } catch (ValidationException $e) {
            $this->toast('error', 'Belum tersimpan: periksa isian yang ditandai merah.');
            $this->dispatch('focus-first-error');

            throw $e;
        }
        session()->flash('toast', ['type' => 'success', 'message' => $this->couponId ? "Kupon {$saved->code} diperbarui." : "Kupon {$saved->code} dibuat."]);

        return $this->redirectRoute('promo', navigate: true);
    }

    public function delete()
    {
        Gate::authorize('kelola-promo');
        $model = Coupon::findOrFail($this->couponId);
        try {
            app(PromoManager::class)->delete($model);
        } catch (ValidationException $e) {
            $this->toast('error', $e->getMessage());

            return null;
        }
        session()->flash('toast', ['type' => 'success', 'message' => "Kupon {$model->code} dihapus."]);

        return $this->redirectRoute('promo', navigate: true);
    }

    public function render()
    {
        return view('livewire.promo.coupon-form');
    }
}
