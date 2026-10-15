<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Penanda unduhan berkas ekspor: kapan terakhir diunduh & berapa kali (ditampilkan di daftar ekspor). */
    public function up(): void
    {
        Schema::table('report_exports', function (Blueprint $table) {
            $table->timestamp('downloaded_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('report_exports', function (Blueprint $table) {
            $table->dropColumn(['downloaded_at', 'download_count']);
        });
    }
};
