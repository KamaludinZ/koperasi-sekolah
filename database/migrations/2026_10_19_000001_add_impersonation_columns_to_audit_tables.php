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
     * Kolom audit peran & impersonasi (sebelumnya hanya di JSON properties):
     * - activity_logs.impersonation_id (uuid sesi impersonasi saat aksi dilakukan) + on_behalf_type/on_behalf_id
     *   (akun target yang sedang dipakai admin) — berindeks untuk penelusuran, ikut ditandatangani HMAC bila terisi
     *   (catatan lama tanpa nilai tetap sah);
     * - impersonation_sessions.start_log_id / end_log_id → id baris activity_logs mulai & selesainya (tanpa FK).
     * Pengisian data lama: hanya baris yang checksum-nya masih cocok yang diisi & ditandatangani ulang — baris yang
     * sudah tidak cocok dibiarkan apa adanya agar tetap terdeteksi (tidak "dicuci").
     */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->uuid('impersonation_id')->nullable()->after('reason');
            $table->string('on_behalf_type', 10)->nullable()->after('impersonation_id');
            $table->unsignedBigInteger('on_behalf_id')->nullable()->after('on_behalf_type');
            $table->index('impersonation_id');
            $table->index(['on_behalf_type', 'on_behalf_id']);
        });
        Schema::table('impersonation_sessions', function (Blueprint $table) {
            // tanpa foreign key: activity_logs hanya-tambah (TRUNCATE/hapus tetap ditolak penjaganya sendiri) dan tak boleh
            // ikut terkunci oleh tabel lain
            $table->unsignedBigInteger('start_log_id')->nullable()->after('user_agent')->index();
            $table->unsignedBigInteger('end_log_id')->nullable()->after('start_log_id')->index();
        });
        $this->restoreOneActiveIndex();

        AuditGuard::suspend(function () {
            DB::table('activity_logs')->whereNotNull('properties')->orderBy('id')->lazyById(500)->each(function ($row) {
                $p = json_decode((string) $row->properties, true) ?: [];
                $imp = $p['impersonation'] ?? null;
                $own = $p['impersonation_id'] ?? null; // catatan impersonation.started/ended
                if (! $imp && ! $own) {
                    return;
                }
                if ($row->checksum === null || ! hash_equals($row->checksum, AuditIntegrity::checksum($row))) {
                    return; // tidak cocok: biarkan agar tetap terdeteksi
                }
                $values = $imp
                    ? ['impersonation_id' => $imp['id'] ?? null, 'on_behalf_type' => $imp['target_type'] ?? null, 'on_behalf_id' => $imp['target_id'] ?? null]
                    : ['impersonation_id' => $own];
                $values['checksum'] = AuditIntegrity::checksum([...(array) $row, ...$values]);
                DB::table('activity_logs')->where('id', $row->id)->update($values);
            });
        });

        // tautkan sesi ke catatan mulai/selesainya
        DB::table('activity_logs')->whereIn('event', ['impersonation.started', 'impersonation.ended'])->whereNotNull('impersonation_id')
            ->orderBy('id')->get(['id', 'event', 'impersonation_id'])
            ->each(fn ($l) => DB::table('impersonation_sessions')->where('id', $l->impersonation_id)
                ->update([$l->event === 'impersonation.started' ? 'start_log_id' : 'end_log_id' => $l->id]));
    }

    /**
     * SQLite membangun ulang tabel saat menambah/membuang foreign key dan indeks unik parsial ikut dibuat ulang TANPA
     * syarat WHERE-nya (jadi "satu sesi seumur hidup per admin"). Pasang ulang persis seperti semula.
     */
    private function restoreOneActiveIndex(): void
    {
        DB::statement('DROP INDEX IF EXISTS impersonation_sessions_one_active_per_admin');
        DB::statement('CREATE UNIQUE INDEX impersonation_sessions_one_active_per_admin ON impersonation_sessions (admin_id) WHERE ended_at IS NULL');
    }

    public function down(): void
    {
        Schema::table('impersonation_sessions', function (Blueprint $table) {
            $table->dropIndex(['start_log_id']);
            $table->dropIndex(['end_log_id']);
            $table->dropColumn(['start_log_id', 'end_log_id']);
        });
        $this->restoreOneActiveIndex();
        // kembalikan tanda tangan lama (tanpa kolom baru) untuk baris yang sah, lalu buang kolomnya
        AuditGuard::suspend(function () {
            DB::table('activity_logs')->whereNotNull('impersonation_id')->orderBy('id')->lazyById(500)->each(function ($row) {
                if ($row->checksum !== null && hash_equals($row->checksum, AuditIntegrity::checksum($row))) {
                    $base = [...(array) $row, 'impersonation_id' => null, 'on_behalf_type' => null, 'on_behalf_id' => null];
                    DB::table('activity_logs')->where('id', $row->id)->update(['checksum' => AuditIntegrity::checksum($base)]);
                }
            });
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->dropIndex(['impersonation_id']);
                $table->dropIndex(['on_behalf_type', 'on_behalf_id']);
                $table->dropColumn(['impersonation_id', 'on_behalf_type', 'on_behalf_id']);
            });
        });
    }
};
