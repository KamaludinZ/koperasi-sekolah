<?php

namespace Tests\Feature\Audit;

use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Product;
use App\Services\AuditIntegrity;
use App\Services\AuditLog;
use App\Support\AuditGuard;
use Database\Seeders\ProductSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLockTest extends TestCase
{
    use RefreshDatabase;

    private function sample(): ActivityLog
    {
        $this->seed(ProductSeeder::class);
        AuditLog::withReason('Uji', fn () => Product::where('code', 'ATK001')->first()->update(['price' => 4500]));
        AuditLog::record('sale.created', 'transaksi', 'Penjualan INV-1', ['type' => 'transaction', 'id' => 1, 'label' => 'INV-1'],
            ['items' => [['name' => 'Buku "Tulis" / 38', 'qty' => 2]]], 8000);

        return ActivityLog::latest('id')->first();
    }

    public function test_new_rows_are_signed_and_verify(): void
    {
        $log = $this->sample();
        $this->assertSame(64, strlen(DB::table('activity_logs')->where('id', $log->id)->value('checksum')));
        $this->assertTrue(AuditIntegrity::isValid($log));

        $r = app(AuditIntegrity::class)->verify();
        $this->assertSame([], $r['invalid']);
        $this->assertSame(0, $r['unsigned']);
        $this->assertSame(2, $r['checked']);

        $this->artisan('audit:verifikasi')->expectsOutputToContain('jejak audit utuh')->assertExitCode(0);
    }

    public function test_database_rejects_update_delete_and_truncate(): void
    {
        $log = $this->sample();

        foreach ([
            fn () => DB::table('activity_logs')->where('id', $log->id)->update(['description' => 'diubah']),
            fn () => DB::table('activity_logs')->where('id', $log->id)->delete(),
            fn () => ActivityLog::whereKey($log->id)->update(['amount' => 1]),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('Perubahan jejak audit seharusnya ditolak database.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('hanya boleh ditambah', $e->getMessage());
            }
        }

        // Model juga menolak tanpa menyentuh database.
        $log->description = 'diubah';
        $this->assertFalse($log->save());
        $this->assertFalse($log->delete());

        if (DB::getDriverName() === 'pgsql') {
            try {
                DB::transaction(fn () => DB::statement('TRUNCATE activity_logs'));
                $this->fail('TRUNCATE seharusnya ditolak.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('hanya boleh ditambah', $e->getMessage());
            }
        }
        $this->assertSame('Penjualan INV-1', ActivityLog::find($log->id)->description);
    }

    public function test_tampering_behind_the_guard_is_detected_and_reported(): void
    {
        $log = $this->sample();
        AuditGuard::suspend(fn () => DB::table('activity_logs')->where('id', $log->id)->update(['amount' => 1000]));

        $this->assertFalse(AuditIntegrity::isValid($log));
        $this->assertSame([$log->id], app(AuditIntegrity::class)->verify()['invalid']);

        $this->artisan('audit:verifikasi')->expectsOutputToContain('1 baris tidak cocok')->assertExitCode(1);
        $this->artisan('audit:verifikasi')->assertExitCode(1);
        $n = Notification::where('dedupe_key', 'audit:integritas:'.now()->toDateString())->sole();
        $this->assertSame(['sistem', 'admin'], [$n->type, $n->target]);

        // Penjaga aktif kembali setelah suspend.
        $this->expectException(QueryException::class);
        DB::table('activity_logs')->where('id', $log->id)->update(['amount' => 8000]);
    }
}
