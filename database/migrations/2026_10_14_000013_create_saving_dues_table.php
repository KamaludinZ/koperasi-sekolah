<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Setoran simpanan bulanan anggota guru/karyawan (hasil potong gaji), dicatat pengurus per periode bulan.
     * status: belum (dicatat, belum menambah saldo) | disetor (sudah diposting → mutasi setor `entry_id`).
     * Satu baris per anggota + jenis + periode. Sistem tidak menghitung penggajian.
     */
    public function up(): void
    {
        Schema::create('saving_dues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('saving_type_id')->constrained()->restrictOnDelete();
            $table->char('period', 7);
            $table->decimal('amount', 14, 2);
            $table->string('status', 10)->default('belum');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('entry_id')->nullable()->constrained('member_saving_entries')->nullOnDelete();
            $table->string('note', 200)->nullable();
            $table->timestamps();
            $table->unique(['member_id', 'saving_type_id', 'period']);
            $table->index(['period', 'saving_type_id', 'status']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE saving_dues ADD CONSTRAINT saving_dues_status_valid CHECK (status IN ('belum','disetor'))");
            DB::statement('ALTER TABLE saving_dues ADD CONSTRAINT saving_dues_amount_positive CHECK (amount > 0)');
            DB::statement("ALTER TABLE saving_dues ADD CONSTRAINT saving_dues_period_format CHECK (period ~ '^[0-9]{4}-(0[1-9]|1[0-2])$')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('saving_dues');
    }
};
