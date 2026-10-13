<?php

namespace App\Services;

use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pemotongan & pengembalian saldo tabungan untuk pembayaran belanja.
 * - charge(): potong saldo anggota sebesar total transaksi kasir bermetode `tabungan`
 *   (mutasi `tarik`, asal = transaction). Dipanggil di dalam transaksi DB checkout sehingga
 *   gagal bayar = transaksi batal seluruhnya.
 * - refund(): kembalikan saldo (mutasi `setor`, asal = return/transaction) untuk retur/pembatalan.
 * - forTransaction(): mutasi pemotongan sebuah transaksi (untuk struk: saldo awal/akhir).
 * Baris anggota dikunci (lockForUpdate) agar dua pembayaran bersamaan tidak membuat saldo minus.
 */
class SavingsPaymentService
{
    /**
     * @throws ValidationException anggota tidak ada/nonaktif atau saldo kurang (kunci `member_id`)
     */
    public function charge(int $memberId, int $amount, Transaction $transaction, ?int $userId = null): SavingsTransaction
    {
        return DB::transaction(function () use ($memberId, $amount, $transaction, $userId) {
            $member = Member::whereKey($memberId)->lockForUpdate()->first();
            if (! $member || ! $member->is_active) {
                throw ValidationException::withMessages(['member_id' => 'Anggota tidak ditemukan atau sudah nonaktif.']);
            }
            $before = (int) round((float) $member->balance);
            if ($amount > $before) {
                throw ValidationException::withMessages(['member_id' => 'Saldo tabungan kurang Rp'.number_format($amount - $before, 0, ',', '.')
                    .' (saldo Rp'.number_format($before, 0, ',', '.').').']);
            }

            $entry = $this->post($member, SavingsTransaction::TYPE_WITHDRAWAL, $amount, "Belanja {$transaction->invoice_number}", 'transaction', $transaction->id, $userId);

            AuditLog::record('savings.purchase', 'tabungan',
                "Bayar belanja {$transaction->invoice_number} dengan tabungan {$member->name}: Rp".number_format($amount, 0, ',', '.')
                    .' (saldo Rp'.number_format((int) $entry->balance_after, 0, ',', '.').')',
                ['type' => 'member', 'id' => $member->id, 'label' => "{$member->member_code} · {$member->name}"],
                ['savings_id' => $entry->id, 'transaction_id' => $transaction->id, 'balance_before' => $before, 'balance_after' => (int) $entry->balance_after],
                $amount);

            return $entry;
        });
    }

    /**
     * Kembalikan saldo ke anggota (retur/pembatalan). Anggota nonaktif tetap menerima pengembalian.
     *
     * @param  string  $source  return | transaction
     */
    public function refund(int $memberId, int $amount, string $source, int $sourceId, string $note, ?int $userId = null): SavingsTransaction
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Jumlah pengembalian harus lebih dari 0.']);
        }

        return DB::transaction(function () use ($memberId, $amount, $source, $sourceId, $note, $userId) {
            $member = Member::withTrashed()->whereKey($memberId)->lockForUpdate()->firstOrFail();
            $entry = $this->post($member, SavingsTransaction::TYPE_DEPOSIT, $amount, $note, $source, $sourceId, $userId);

            AuditLog::record('savings.refund', 'tabungan',
                "Pengembalian ke tabungan {$member->name}: Rp".number_format($amount, 0, ',', '.')." ({$note})",
                ['type' => 'member', 'id' => $member->id, 'label' => "{$member->member_code} · {$member->name}"],
                ['savings_id' => $entry->id, 'source_type' => $source, 'source_id' => $sourceId, 'balance_after' => (int) $entry->balance_after],
                $amount);

            return $entry;
        });
    }

    /** Mutasi pemotongan tabungan untuk sebuah transaksi kasir, bila ada. */
    public function forTransaction(int $transactionId): ?SavingsTransaction
    {
        return SavingsTransaction::where('source_type', 'transaction')->where('source_id', $transactionId)
            ->where('type', SavingsTransaction::TYPE_WITHDRAWAL)->first();
    }

    private function post(Member $locked, string $type, int $amount, string $note, string $source, int $sourceId, ?int $userId): SavingsTransaction
    {
        $before = (int) round((float) $locked->balance);
        $after = $type === SavingsTransaction::TYPE_DEPOSIT ? $before + $amount : $before - $amount;

        $entry = $locked->savings()->create([
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
            'note' => mb_substr($note, 0, 255),
            'source_type' => $source,
            'source_id' => $sourceId,
            'user_id' => $userId,
        ]);
        $locked->forceFill(['balance' => $after])->save();

        return $entry;
    }
}
