<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pencairan pinjaman: satu pencairan per pinjaman (unik), metadata berkas bukti (jenis & ukuran),
     * dan di PostgreSQL berkas bukti wajib berada di folder privat pinjaman/bukti/ tanpa "..".
     */
    public function up(): void
    {
        Schema::table('loan_disbursements', function (Blueprint $table) {
            $table->string('proof_mime', 50)->nullable()->after('proof_file');
            $table->unsignedInteger('proof_size')->nullable()->after('proof_mime');
            $table->dropIndex(['loan_id']);
            $table->unique('loan_id');
            $table->index('disbursed_on');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE loan_disbursements ADD CONSTRAINT loan_disbursements_proof_path CHECK (proof_file IS NULL OR (proof_file LIKE 'pinjaman/bukti/%' AND proof_file NOT LIKE '%..%'))");
            DB::statement('ALTER TABLE loan_disbursements ADD CONSTRAINT loan_disbursements_proof_size CHECK (proof_size IS NULL OR proof_size > 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE loan_disbursements DROP CONSTRAINT IF EXISTS loan_disbursements_proof_path');
            DB::statement('ALTER TABLE loan_disbursements DROP CONSTRAINT IF EXISTS loan_disbursements_proof_size');
        }
        Schema::table('loan_disbursements', function (Blueprint $table) {
            $table->dropIndex(['disbursed_on']);
            $table->dropUnique(['loan_id']);
            $table->index('loan_id');
            $table->dropColumn(['proof_mime', 'proof_size']);
        });
    }
};
