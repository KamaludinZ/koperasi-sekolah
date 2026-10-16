<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Sejak kapan panduan ditandai usang (dikosongkan saat ditinjau atau disusun ulang). */
    public function up(): void
    {
        Schema::table('guide_entries', function (Blueprint $table) {
            $table->timestamp('stale_at')->nullable()->after('stale_reason');
        });
    }

    public function down(): void
    {
        Schema::table('guide_entries', function (Blueprint $table) {
            $table->dropColumn('stale_at');
        });
    }
};
