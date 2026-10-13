<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hapus lunak untuk data master (barang, kategori, anggota): `deleted_at`, siapa yang menghapus,
     * dan alasannya. Data terhapus disimpan selama masa retensi (pengaturan Keamanan & retensi)
     * sebelum dibersihkan permanen. Kode/nama unik hanya berlaku untuk data yang belum dihapus,
     * sehingga kode barang yang dihapus boleh dipakai lagi (memulihkan yang lama lalu ditolak bila bentrok).
     */
    private const TABLES = [
        'products' => ['column' => 'code', 'old' => 'products_code_unique'],
        'categories' => ['column' => 'name', 'old' => 'categories_name_unique'],
        'members' => ['column' => 'member_code', 'old' => 'members_member_code_unique'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $u) {
            Schema::table($table, function (Blueprint $t) use ($u) {
                $t->softDeletes();
                $t->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                $t->string('delete_reason', 200)->nullable();
                $t->dropUnique($u['old']);
                $t->index('deleted_at');
            });
            DB::statement("CREATE UNIQUE INDEX {$table}_{$u['column']}_active_unique ON {$table} ({$u['column']}) WHERE deleted_at IS NULL");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $u) {
            DB::table($table)->whereNotNull('deleted_at')->delete();
            DB::statement("DROP INDEX IF EXISTS {$table}_{$u['column']}_active_unique");
            Schema::table($table, function (Blueprint $t) use ($u) {
                $t->dropIndex(['deleted_at']);
                $t->dropConstrainedForeignId('deleted_by');
                $t->dropColumn(['deleted_at', 'delete_reason']);
                $t->unique($u['column'], $u['old']);
            });
        }
    }
};
