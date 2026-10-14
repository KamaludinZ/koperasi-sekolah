<?php

use App\Models\LoanInstallment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Porsi pokok & bunga tiap angsuran disimpan di tabel (sebelumnya dihitung ulang dari generator jadwal), sehingga
     * ringkasan bayar/sisa pokok-bunga tetap benar meski aturan generator berubah kelak. Baris lama diisi dari
     * generator (bila jadwal cocok) atau dibagi proporsional. Di PostgreSQL: kedua porsi wajib ada, tidak negatif,
     * dan jumlahnya tepat sama dengan nominal angsuran.
     */
    public function up(): void
    {
        Schema::table('loan_installments', function (Blueprint $table) {
            $table->decimal('principal_part', 14, 2)->nullable()->after('amount');
            $table->decimal('interest_part', 14, 2)->nullable()->after('principal_part');
        });

        DB::table('loans')->whereIn('id', DB::table('loan_installments')->select('loan_id'))->orderBy('id')
            ->each(function ($loan) {
                $rows = DB::table('loan_installments')->where('loan_id', $loan->id)->orderBy('sequence')->get(['id', 'sequence', 'amount']);
                $parts = LoanInstallment::partsFor((int) round((float) $loan->principal), (int) round((float) $loan->interest),
                    $rows->map(fn ($r) => ['sequence' => (int) $r->sequence, 'amount' => (int) round((float) $r->amount)])->all());
                foreach ($rows as $r) {
                    DB::table('loan_installments')->where('id', $r->id)->update($parts[(int) $r->sequence]);
                }
            });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE loan_installments ADD CONSTRAINT loan_installments_parts_valid CHECK (principal_part IS NOT NULL AND interest_part IS NOT NULL AND principal_part >= 0 AND interest_part >= 0 AND principal_part + interest_part = amount)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE loan_installments DROP CONSTRAINT IF EXISTS loan_installments_parts_valid');
        }
        Schema::table('loan_installments', function (Blueprint $table) {
            $table->dropColumn(['principal_part', 'interest_part']);
        });
    }
};
