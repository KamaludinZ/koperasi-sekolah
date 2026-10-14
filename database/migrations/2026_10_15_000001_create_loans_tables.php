<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Modul pinjaman anggota (terpisah dari bon belanja):
     * - loans: pengajuan → disetujui/ditolak/dibatalkan → berjalan (dicairkan) → lunas; `menunggak` bila ada angsuran lewat jatuh tempo.
     *   Bunga diisi nominal sesuai kesepakatan; tenor dalam bulan; `outstanding` = sisa yang belum dibayar.
     * - loan_disbursements: pencairan dana (tanggal, nominal, bukti serah terima).
     * - loan_installments: jadwal & pembayaran angsuran (ke-n, jatuh tempo, nominal, dibayar, metode).
     * Menu baru `pinjaman` untuk admin & pengurus (kasir tidak).
     */
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->decimal('principal', 14, 2);
            $table->decimal('interest', 14, 2)->default(0);
            $table->unsignedSmallInteger('tenor_months');
            $table->string('payment_method', 15);
            $table->string('status', 15)->default('diajukan');
            $table->string('purpose', 200)->nullable();
            $table->string('decision_note', 200)->nullable();
            $table->decimal('outstanding', 14, 2)->default(0);
            $table->date('applied_at');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('disbursed_at')->nullable();
            $table->timestamp('paid_off_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'applied_at']);
            $table->index(['member_id', 'status']);
            $table->index('payment_method');
        });

        Schema::create('loan_disbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->date('disbursed_on');
            $table->decimal('amount', 14, 2);
            $table->string('proof', 200)->nullable();
            $table->string('proof_file')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index('loan_id');
        });

        Schema::create('loan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('due_date');
            $table->decimal('amount', 14, 2);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->date('paid_at')->nullable();
            $table->string('payment_method', 15)->nullable();
            $table->string('status', 15)->default('belum');
            $table->string('note', 200)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['loan_id', 'sequence']);
            $table->index(['status', 'due_date']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE loans ADD CONSTRAINT loans_status_valid CHECK (status IN ('diajukan','disetujui','ditolak','dibatalkan','berjalan','menunggak','lunas'))");
            DB::statement("ALTER TABLE loans ADD CONSTRAINT loans_method_valid CHECK (payment_method IN ('potong_gaji','transfer','tunai'))");
            DB::statement('ALTER TABLE loans ADD CONSTRAINT loans_amounts_valid CHECK (principal > 0 AND interest >= 0 AND outstanding >= 0 AND tenor_months BETWEEN 1 AND 120)');
            DB::statement('ALTER TABLE loan_disbursements ADD CONSTRAINT loan_disbursements_amount_positive CHECK (amount > 0)');
            DB::statement("ALTER TABLE loan_installments ADD CONSTRAINT loan_installments_status_valid CHECK (status IN ('belum','dibayar','menunggak'))");
            DB::statement("ALTER TABLE loan_installments ADD CONSTRAINT loan_installments_method_valid CHECK (payment_method IS NULL OR payment_method IN ('potong_gaji','transfer','tunai'))");
            DB::statement('ALTER TABLE loan_installments ADD CONSTRAINT loan_installments_amounts_valid CHECK (amount > 0 AND paid_amount >= 0 AND sequence >= 1)');
        }

        foreach (DB::table('roles')->whereIn('name', ['admin', 'pengurus'])->get() as $role) {
            $menus = json_decode($role->permissions, true) ?: [];
            if (in_array('pinjaman', $menus, true)) {
                continue;
            }
            $at = array_search('simpanan', $menus, true);
            array_splice($menus, $at === false ? count($menus) : $at + 1, 0, 'pinjaman');
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($menus), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $menus = array_values(array_diff(json_decode($role->permissions, true) ?: [], ['pinjaman']));
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($menus)]);
        }
        Schema::dropIfExists('loan_installments');
        Schema::dropIfExists('loan_disbursements');
        Schema::dropIfExists('loans');
    }
};
