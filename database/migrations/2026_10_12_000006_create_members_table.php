<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Anggota koperasi (siswa & guru) beserta saldo tabungannya,
     * lalu kaitkan transactions.member_id ke tabel ini.
     */
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('member_code', 30)->unique(); // disimpan huruf besar, mis. S-2024-001
            $table->string('name', 100);
            $table->string('type', 10)->default('siswa'); // siswa | guru
            $table->string('class', 20)->nullable(); // wajib untuk siswa (divalidasi di aplikasi)
            $table->string('phone', 20)->nullable();
            $table->decimal('balance', 14, 2)->default(0); // saldo tabungan saat ini
            $table->boolean('is_active')->default(true);
            $table->date('joined_at');
            $table->timestamps();

            $table->index(['type', 'is_active']);
            $table->index('name');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE members ADD CONSTRAINT members_type_valid CHECK (type IN ('siswa', 'guru'))");
            DB::statement('ALTER TABLE members ADD CONSTRAINT members_balance_non_negative CHECK (balance >= 0)');
        }

        // Transaksi lama yang menunjuk anggota tak dikenal dilepas dulu agar FK bisa dibuat.
        DB::table('transactions')->whereNotNull('member_id')->update(['member_id' => null]);

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreign('member_id')->references('id')->on('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['member_id']);
        });

        Schema::dropIfExists('members');
    }
};
