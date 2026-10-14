<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indeks untuk rekap setoran bulanan & status lunas per bulan:
     * - member_saving_entries (member_id, saving_type_id, period): bulan terbayar per anggota (paidPeriodRows).
     * - saving_dues (saving_type_id, period, status): rekap per bulan per jenis & matriks status.
     */
    public function up(): void
    {
        Schema::table('member_saving_entries', function (Blueprint $table) {
            $table->index(['member_id', 'saving_type_id', 'period'], 'member_saving_entries_member_type_period_index');
        });
        Schema::table('saving_dues', function (Blueprint $table) {
            $table->index(['saving_type_id', 'period', 'status'], 'saving_dues_type_period_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('member_saving_entries', fn (Blueprint $t) => $t->dropIndex('member_saving_entries_member_type_period_index'));
        Schema::table('saving_dues', fn (Blueprint $t) => $t->dropIndex('saving_dues_type_period_status_index'));
    }
};
