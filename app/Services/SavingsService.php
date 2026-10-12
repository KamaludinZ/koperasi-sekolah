<?php

namespace App\Services;

use App\Models\Member;
use App\Models\SavingsTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Setor & tarik tabungan anggota secara atomik: kunci baris anggota → validasi
 * (anggota aktif, nominal, saldo cukup) → catat mutasi dengan saldo sebelum/sesudah
 * → perbarui saldo anggota. Satu-satunya jalan untuk mengubah `members.balance`.
 */
class SavingsService
{
    public const MIN_AMOUNT = 1_000;

    public const MAX_DEPOSIT = 10_000_000;

    /** Aturan dasar nominal & catatan (batas saldo dicek saat menyimpan). */
    public static function rules(): array
    {
        return [
            'type' => ['required', 'in:'.implode(',', array_keys(SavingsTransaction::TYPES))],
            'amount' => ['required', 'integer', 'min:'.self::MIN_AMOUNT, 'max:'.self::MAX_DEPOSIT],
            'note' => ['nullable', 'string', 'max:150'],
        ];
    }

    public static function messages(): array
    {
        return [
            'type.required' => 'Pilih setor atau tarik.',
            'type.in' => 'Jenis mutasi tidak dikenal.',
            'amount.required' => 'Masukkan jumlah uang.',
            'amount.integer' => 'Jumlah harus angka bulat.',
            'amount.min' => 'Minimal Rp'.number_format(self::MIN_AMOUNT, 0, ',', '.').'.',
            'amount.max' => 'Maksimal Rp'.number_format(self::MAX_DEPOSIT, 0, ',', '.').' per transaksi.',
            'note.max' => 'Catatan maksimal :max karakter.',
        ];
    }

    public function deposit(Member $member, int $amount, ?string $note = null, ?int $userId = null): SavingsTransaction
    {
        return $this->record($member, SavingsTransaction::TYPE_DEPOSIT, $amount, $note, $userId);
    }

    public function withdraw(Member $member, int $amount, ?string $note = null, ?int $userId = null): SavingsTransaction
    {
        return $this->record($member, SavingsTransaction::TYPE_WITHDRAWAL, $amount, $note, $userId);
    }

    /**
     * @throws ValidationException bila anggota nonaktif, nominal di luar batas, atau saldo kurang
     */
    public function record(Member $member, string $type, int $amount, ?string $note = null, ?int $userId = null): SavingsTransaction
    {
        if (! array_key_exists($type, SavingsTransaction::TYPES)) {
            throw ValidationException::withMessages(['type' => 'Jenis mutasi tidak dikenal.']);
        }
        if ($amount < self::MIN_AMOUNT || $amount > self::MAX_DEPOSIT) {
            throw ValidationException::withMessages(['amount' => $amount < self::MIN_AMOUNT
                ? self::messages()['amount.min']
                : self::messages()['amount.max']]);
        }

        $entry = DB::transaction(function () use ($member, $type, $amount, $note, $userId) {
            // Kunci baris anggota agar dua penarikan bersamaan tidak membuat saldo minus.
            $locked = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();

            if (! $locked->is_active) {
                throw ValidationException::withMessages(['member' => 'Anggota nonaktif tidak bisa setor/tarik.']);
            }

            $before = (int) round((float) $locked->balance);
            if ($type === SavingsTransaction::TYPE_WITHDRAWAL && $amount > $before) {
                throw ValidationException::withMessages([
                    'amount' => 'Penarikan melebihi saldo (Rp'.number_format($before, 0, ',', '.').').',
                ]);
            }
            $after = $type === SavingsTransaction::TYPE_DEPOSIT ? $before + $amount : $before - $amount;

            $entry = $locked->savings()->create([
                'type' => $type,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'note' => filled($note) ? trim($note) : ($type === SavingsTransaction::TYPE_DEPOSIT ? 'Setoran tabungan' : 'Penarikan tabungan'),
                'user_id' => $userId,
            ]);

            $locked->forceFill(['balance' => $after])->save();

            return $entry;
        });

        $member->refresh();

        return $entry;
    }

    /** Bentuk array mutasi (sama dengan data tiruan halaman profil anggota). */
    public static function toArray(SavingsTransaction $s): array
    {
        return [
            'id' => $s->id,
            'member_id' => $s->member_id,
            'type' => $s->type,
            'type_label' => $s->type_label,
            'amount' => (int) round((float) $s->amount),
            'balance_before' => (int) round((float) $s->balance_before),
            'balance_after' => (int) round((float) $s->balance_after),
            'note' => $s->note,
            'user' => $s->user?->name ?? 'Petugas',
            'created_at' => $s->created_at->toDateTimeString(),
        ];
    }
}
