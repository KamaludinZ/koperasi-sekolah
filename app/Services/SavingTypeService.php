<?php

namespace App\Services;

use App\Models\SavingType;
use Illuminate\Validation\Rule;

/** Kelola jenis simpanan (pokok/wajib/sukarela/lainnya). Perubahan dicatat MasterDataObserver. */
class SavingTypeService
{
    public const MAX_AMOUNT = 100_000_000;

    public static function rules(?int $ignoreId = null, ?string $kind = null): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_\-]+$/', function ($attr, $value, $fail) use ($ignoreId) {
                $taken = SavingType::whereRaw('UPPER(code) = ?', [mb_strtoupper(trim((string) $value))])
                    ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
                if ($taken) {
                    $fail('Kode sudah dipakai jenis simpanan lain.');
                }
            }],
            'name' => ['required', 'string', 'max:60'],
            'kind' => ['required', Rule::in(array_keys(SavingType::KINDS))],
            // Pokok & wajib wajib punya nominal; sukarela/lainnya boleh 0 (bebas).
            'amount' => ['required', 'integer', in_array($kind, ['pokok', 'wajib'], true) ? 'min:1' : 'min:0', 'max:'.self::MAX_AMOUNT],
            'period' => ['required', Rule::in(array_keys(SavingType::PERIODS))],
            'withdrawable' => ['boolean'],
            'description' => ['nullable', 'string', 'max:200'],
            'is_active' => ['boolean'],
        ];
    }

    public static function messages(): array
    {
        return [
            'required' => 'Wajib diisi.',
            'code.regex' => 'Hanya huruf, angka, - dan _.',
            'amount.integer' => 'Harus berupa angka bulat.',
            'amount.min' => 'Nominal tidak valid (simpanan pokok & wajib harus lebih dari 0).',
            'amount.max' => 'Nominal terlalu besar.',
            'max' => 'Maksimal :max karakter.',
            'in' => 'Pilihan tidak dikenal.',
        ];
    }

    /** @param  array<string, mixed>  $data  data tervalidasi */
    public function save(array $data, ?SavingType $type = null): SavingType
    {
        $attrs = [
            'code' => mb_strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'kind' => $data['kind'],
            'amount' => (int) $data['amount'],
            'period' => $data['period'],
            'withdrawable' => (bool) ($data['withdrawable'] ?? false),
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
        if ($type) {
            $type->update($attrs);

            return $type->refresh();
        }

        return SavingType::create([...$attrs, 'sort_order' => (int) SavingType::max('sort_order') + 1]);
    }

    public function setActive(SavingType $type, bool $active): SavingType
    {
        if ($type->is_active !== $active) {
            $type->update(['is_active' => $active]);
        }

        return $type->refresh();
    }

    /** Kalimat aturan untuk petugas, mis. "Anggota menyetor Rp10.000 tiap bulan; ditahan selama masih anggota." */
    public static function ruleSentence(int $amount, string $period, bool $withdrawable): string
    {
        $nominal = $amount > 0 ? \App\Support\Rupiah::format($amount) : 'nominal bebas';
        $when = match ($period) {
            'sekali' => 'sekali saat mendaftar',
            'bulanan' => 'tiap bulan',
            default => 'kapan saja',
        };

        return "Anggota menyetor {$nominal} {$when}; ".($withdrawable ? 'saldo boleh ditarik kapan saja.' : 'saldo ditahan selama masih menjadi anggota.');
    }

    /** @return array<int, array{members: int, balance: int}> jumlah anggota bersaldo & total saldo per jenis */
    public function stats(): array
    {
        return \App\Models\MemberSavingAccount::where('balance', '>', 0)
            ->selectRaw('saving_type_id, COUNT(*) AS members, COALESCE(SUM(balance), 0) AS total')->groupBy('saving_type_id')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->saving_type_id => ['members' => (int) $r->members, 'balance' => (int) round((float) $r->total)]])->all();
    }

    /**
     * Hapus jenis yang belum pernah dipakai (tanpa saldo/mutasi/pesanan); yang sudah dipakai cukup dinonaktifkan.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function delete(SavingType $type): void
    {
        $used = \App\Models\MemberSavingEntry::where('saving_type_id', $type->id)->exists()
            || \App\Models\MemberSavingAccount::where('saving_type_id', $type->id)->where('balance', '>', 0)->exists()
            || \App\Models\Order::where('saving_type_id', $type->id)->exists();
        if ($used) {
            throw \Illuminate\Validation\ValidationException::withMessages(['type' => "{$type->name} sudah dipakai; nonaktifkan saja."]);
        }
        \App\Models\MemberSavingAccount::where('saving_type_id', $type->id)->delete();
        $type->delete(); // dicatat MasterDataObserver
    }

    public static function toArray(SavingType $t): array
    {
        return [
            'id' => $t->id, 'code' => $t->code, 'name' => $t->name,
            'kind' => $t->kind, 'kind_label' => SavingType::KINDS[$t->kind] ?? $t->kind,
            'amount' => (int) round((float) $t->amount),
            'period' => $t->period, 'period_label' => SavingType::PERIODS[$t->period] ?? $t->period,
            'withdrawable' => $t->withdrawable, 'description' => $t->description, 'is_active' => $t->is_active,
        ];
    }
}
