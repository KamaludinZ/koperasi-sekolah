<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Status menunggak di skema pinjaman: kolom overdue_since (jatuh tempo angsuran tertua yang belum lunas saat
     * pinjaman ditandai menunggak) + indeks (status, overdue_since) untuk daftar tunggakan & umur tunggakan.
     * Baris menunggak yang sudah ada diisi dari angsuran tertunggak tertua (atau tanggal hari ini bila tidak ada).
     * Di PostgreSQL: overdue_since terisi JIKA DAN HANYA JIKA status = menunggak.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->date('overdue_since')->nullable()->after('outstanding');
            $table->index(['status', 'overdue_since']);
        });

        $today = now()->toDateString();
        foreach (DB::table('loans')->where('status', 'menunggak')->pluck('id') as $id) {
            $oldest = DB::table('loan_installments')->where('loan_id', $id)->where('status', '!=', 'dibayar')->where('due_date', '<', $today)->min('due_date');
            DB::table('loans')->where('id', $id)->update(['overdue_since' => $oldest ? substr((string) $oldest, 0, 10) : $today]);
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE loans ADD CONSTRAINT loans_overdue_since_consistent CHECK ((status = 'menunggak') = (overdue_since IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE loans DROP CONSTRAINT IF EXISTS loans_overdue_since_consistent');
        }
        Schema::table('loans', function (Blueprint $table) {
            $table->dropIndex(['status', 'overdue_since']);
            $table->dropColumn('overdue_since');
        });
    }
};
