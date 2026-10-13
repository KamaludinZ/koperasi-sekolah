<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kategori barang yang bisa diatur: warna penanda, urutan tampil (dipakai layar kasir, daftar
     * barang & laporan), dan keterangan singkat. Kategori yang sudah ada diberi urutan menurut nama
     * dan warna bergiliran.
     */
    private const COLORS = ['emerald', 'amber', 'sky', 'violet', 'teal', 'rose', 'stone'];

    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('color', 20)->default('emerald')->after('name');
            $table->unsignedInteger('position')->default(0)->after('color');
            $table->string('description', 200)->nullable()->after('position');
            $table->index(['position', 'name']);
        });

        DB::table('categories')->orderBy('name')->get(['id'])->each(fn ($c, $i) => DB::table('categories')
            ->where('id', $c->id)->update(['position' => $i + 1, 'color' => self::COLORS[$i % count(self::COLORS)]]));

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_color_check CHECK (color IN ('".implode("','", self::COLORS)."'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE categories DROP CONSTRAINT IF EXISTS categories_color_check');
        }
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['position', 'name']);
            $table->dropColumn(['color', 'position', 'description']);
        });
    }
};
