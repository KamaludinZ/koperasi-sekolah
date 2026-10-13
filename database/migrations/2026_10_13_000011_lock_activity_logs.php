<?php

use App\Services\AuditIntegrity;
use App\Support\AuditGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kunci jejak audit: kolom `checksum` (HMAC isi baris, lihat AuditIntegrity) untuk mendeteksi
     * perubahan langsung di database, tanda untuk baris yang sudah ada, dan penjaga database
     * (PostgreSQL: tolak TRUNCATE selain UPDATE/DELETE; SQLite: tolak UPDATE/DELETE).
     */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->char('checksum', 64)->nullable();
        });

        AuditGuard::suspend(fn () => DB::table('activity_logs')->whereNull('checksum')->orderBy('id')->lazyById(500)
            ->each(fn ($row) => DB::table('activity_logs')->where('id', $row->id)->update(['checksum' => AuditIntegrity::checksum($row)])));

        AuditGuard::install();
    }

    public function down(): void
    {
        AuditGuard::uninstall();

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropColumn('checksum');
        });
    }
};
