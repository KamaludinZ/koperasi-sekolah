<?php

use App\Support\AuditGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Perluasan jejak audit untuk perubahan data master (barang, kategori, anggota, pengaturan,
     * pengguna): jenis aksi (dibuat/diubah/dihapus/dipulihkan), nilai sebelum & sesudah per kolom,
     * dan alasan perubahan. Baris lama yang sudah menyimpan `properties.changes` diisi ulang;
     * penjaga anti-ubah dimatikan sebentar khusus untuk pengisian ini (AuditGuard::suspend).
     */
    private const ACTIONS = ['dibuat', 'diubah', 'dihapus', 'dipulihkan'];

    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('action', 12)->nullable()->after('category');
            $table->json('old_values')->nullable()->after('properties');
            $table->json('new_values')->nullable()->after('old_values');
            $table->string('reason', 200)->nullable()->after('new_values');

            $table->index(['action', 'created_at']);
            $table->index(['subject_type', 'subject_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_action_check CHECK (action IS NULL OR action IN ('".implode("','", self::ACTIONS)."'))");
        }

        AuditGuard::suspend(fn () => $this->backfill());
    }

    private function backfill(): void
    {
        $actionFor = fn (string $event) => match (true) {
            str_ends_with($event, '.created') => 'dibuat',
            str_ends_with($event, '.deleted') => 'dihapus',
            str_ends_with($event, '.restored') => 'dipulihkan',
            default => 'diubah',
        };

        DB::table('activity_logs')
            ->whereNull('action')
            ->where(fn ($q) => $q->whereIn('event', ['product.created', 'product.updated', 'member.created', 'member.updated', 'member.deleted',
                'setting.updated', 'setting.reset', 'stock.min_updated', 'user.created', 'user.updated', 'role.permissions_updated']))
            ->orderBy('id')
            ->lazyById(200)
            ->each(function ($row) use ($actionFor) {
                $changes = json_decode((string) $row->properties, true)['changes'] ?? [];
                $changes = is_array($changes) ? array_filter($changes, fn ($c) => is_array($c) && array_key_exists('to', $c)) : [];

                DB::table('activity_logs')->where('id', $row->id)->update([
                    'action' => $actionFor($row->event),
                    'old_values' => $changes ? json_encode(array_map(fn ($c) => $c['from'] ?? null, $changes), JSON_UNESCAPED_UNICODE) : null,
                    'new_values' => $changes ? json_encode(array_map(fn ($c) => $c['to'], $changes), JSON_UNESCAPED_UNICODE) : null,
                ]);
            });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE activity_logs DROP CONSTRAINT IF EXISTS activity_logs_action_check');
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['action', 'created_at']);
            $table->dropIndex(['subject_type', 'subject_id', 'created_at']);
            $table->dropColumn(['action', 'old_values', 'new_values', 'reason']);
        });
    }
};
