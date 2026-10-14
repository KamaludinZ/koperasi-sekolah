<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Log koreksi setoran bulanan (potong gaji): satu baris per koreksi — nominal lama → baru, selisih yang
     * dibukukan (`entry_id`), alasan, dan petugas. Melengkapi jejak audit umum (activity_logs).
     * action: ubah (nominal diubah) | batal (posting dibatalkan) | draf (catatan belum diposting diubah).
     */
    public function up(): void
    {
        Schema::create('saving_due_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saving_due_id')->constrained()->cascadeOnDelete();
            $table->string('action', 10);
            $table->decimal('old_amount', 14, 2);
            $table->decimal('new_amount', 14, 2);
            $table->decimal('delta', 14, 2);
            $table->string('reason', 150);
            $table->foreignId('entry_id')->nullable()->constrained('member_saving_entries')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');
            $table->index(['saving_due_id', 'created_at']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE saving_due_corrections ADD CONSTRAINT saving_due_corrections_action_valid CHECK (action IN ('ubah','batal','draf'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('saving_due_corrections');
    }
};
