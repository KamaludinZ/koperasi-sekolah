<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lengkapi tabel settings (key-value) untuk pengaturan koperasi fase 4:
     * kelompok (profile/receipt/tax/stock), tipe nilai agar bisa dibaca kembali
     * dengan benar (string/bool/int/float/json), dan petugas yang terakhir mengubah.
     * Kunci memakai format "kelompok.nama", mis. profile.name, tax.rate.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('group', 30)->default('general')->after('key');
            $table->string('type', 10)->default('string')->after('value');
            $table->foreignId('updated_by')->nullable()->after('type')->constrained('users')->nullOnDelete();
            $table->index('group');
        });

        // Kunci lama (stock.default_min_stock) ikut dikelompokkan.
        DB::table('settings')->where('key', 'like', 'stock.%')->update(['group' => 'stock', 'type' => 'int']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE settings ADD CONSTRAINT settings_type_valid CHECK (type IN ('string','bool','int','float','json'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE settings DROP CONSTRAINT IF EXISTS settings_type_valid');
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->dropIndex(['group']);
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['group', 'type']);
        });
    }
};
