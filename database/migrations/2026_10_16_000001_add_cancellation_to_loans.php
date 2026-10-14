<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pembatalan pengajuan dicatat terpisah dari keputusan (setuju/tolak) agar catatan & pemutus persetujuan
     * tidak tertimpa saat pengajuan yang sudah disetujui dibatalkan: cancelled_at, cancelled_by, cancel_reason.
     * Data lama berstatus "dibatalkan" dipindahkan dari kolom keputusan. PostgreSQL: pinjaman dibatalkan wajib
     * punya waktu & alasan batal; pinjaman disetujui/ditolak wajib punya waktu keputusan.
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('decided_by');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 200)->nullable()->after('cancelled_by');
            $table->index(['member_id', 'applied_at']);
        });

        DB::table('loans')->where('status', 'dibatalkan')->whereNull('cancelled_at')->update([
            'cancelled_at' => DB::raw('COALESCE(decided_at, updated_at, created_at)'),
            'cancelled_by' => DB::raw('decided_by'),
            'cancel_reason' => DB::raw("COALESCE(decision_note, 'Dibatalkan')"),
            'decided_at' => null, 'decided_by' => null, 'decision_note' => null,
        ]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE loans ADD CONSTRAINT loans_cancel_consistent CHECK ((status = 'dibatalkan') = (cancelled_at IS NOT NULL) AND (status <> 'dibatalkan' OR cancel_reason IS NOT NULL))");
            DB::statement("ALTER TABLE loans ADD CONSTRAINT loans_decision_consistent CHECK (status NOT IN ('disetujui','ditolak') OR decided_at IS NOT NULL)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE loans DROP CONSTRAINT IF EXISTS loans_cancel_consistent');
            DB::statement('ALTER TABLE loans DROP CONSTRAINT IF EXISTS loans_decision_consistent');
        }
        DB::table('loans')->where('status', 'dibatalkan')->update([
            'decided_at' => DB::raw('cancelled_at'), 'decided_by' => DB::raw('cancelled_by'), 'decision_note' => DB::raw('cancel_reason'),
        ]);
        Schema::table('loans', function (Blueprint $table) {
            $table->dropIndex(['member_id', 'applied_at']);
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancel_reason']);
        });
    }
};
