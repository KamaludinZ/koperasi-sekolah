<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jenis simpanan anggota (pokok/wajib/sukarela) — terpisah dari tabungan yang sudah ada.
     * - kind: pokok | wajib | sukarela | lainnya; period: sekali | bulanan | bebas
     * - amount: nominal setoran baku (0 = bebas); withdrawable: boleh ditarik selama masih anggota
     * Menu baru `simpanan` diberikan ke peran admin & pengurus (kasir tidak).
     */
    public function up(): void
    {
        Schema::create('saving_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 60);
            $table->string('kind', 20);
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('period', 20);
            $table->boolean('withdrawable')->default(false);
            $table->string('description', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE saving_types ADD CONSTRAINT saving_types_kind_valid CHECK (kind IN ('pokok','wajib','sukarela','lainnya'))");
            DB::statement("ALTER TABLE saving_types ADD CONSTRAINT saving_types_period_valid CHECK (period IN ('sekali','bulanan','bebas'))");
            DB::statement('ALTER TABLE saving_types ADD CONSTRAINT saving_types_amount_positive CHECK (amount >= 0)');
        }

        $now = now();
        DB::table('saving_types')->insert([
            ['code' => 'POKOK', 'name' => 'Simpanan Pokok', 'kind' => 'pokok', 'amount' => 50000, 'period' => 'sekali', 'withdrawable' => false,
                'description' => 'Dibayar sekali saat mendaftar; kembali saat keluar dari keanggotaan.', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'WAJIB', 'name' => 'Simpanan Wajib', 'kind' => 'wajib', 'amount' => 10000, 'period' => 'bulanan', 'withdrawable' => false,
                'description' => 'Disetor tiap bulan dengan nominal tetap.', 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'SUKARELA', 'name' => 'Simpanan Sukarela', 'kind' => 'sukarela', 'amount' => 0, 'period' => 'bebas', 'withdrawable' => true,
                'description' => 'Setor & tarik kapan saja sesuai kemampuan anggota.', 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);

        foreach (DB::table('roles')->whereIn('name', ['admin', 'pengurus'])->get() as $role) {
            $menus = json_decode($role->permissions, true) ?: [];
            if (in_array('simpanan', $menus, true)) {
                continue;
            }
            $at = array_search('anggota', $menus, true);
            array_splice($menus, $at === false ? count($menus) : $at + 1, 0, 'simpanan');
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($menus), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('roles')->get() as $role) {
            $menus = array_values(array_diff(json_decode($role->permissions, true) ?: [], ['simpanan']));
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($menus)]);
        }
        Schema::dropIfExists('saving_types');
    }
};
