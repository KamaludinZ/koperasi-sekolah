<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Transaksi berdiskon yang dibuat sebelum ada tabel rincian diskon: buat satu baris
     * rincian `manual` sebesar kolom discount, dengan alasan dari jejak audit penjualan bila ada.
     * Aman dijalankan ulang (hanya transaksi yang belum punya rincian).
     */
    public function up(): void
    {
        $rows = DB::table('transactions')
            ->where('discount', '>', 0)
            ->whereNotExists(fn ($q) => $q->from('transaction_discounts')->whereColumn('transaction_discounts.transaction_id', 'transactions.id'))
            ->get(['id', 'discount', 'created_at']);

        foreach ($rows as $tx) {
            $properties = DB::table('activity_logs')
                ->where('subject_type', 'transaction')->where('subject_id', $tx->id)->where('event', 'sale.created')
                ->value('properties');
            $reason = json_decode((string) $properties, true)['discount_reason'] ?? null;

            DB::table('transaction_discounts')->insert([
                'transaction_id' => $tx->id,
                'source' => 'manual',
                'label' => 'Diskon manual',
                'amount' => $tx->discount,
                'reason' => $reason ? mb_substr($reason, 0, 150) : null,
                'created_at' => $tx->created_at,
            ]);
        }
    }

    public function down(): void
    {
        // Rincian hasil pengisian tidak dibedakan dari rincian asli; dibiarkan.
    }
};
