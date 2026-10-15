<?php

namespace Tests\Feature\Database;

use App\Models\ActivityLog;
use App\Models\Impersonation;
use App\Models\User;
use App\Services\AuditIntegrity;
use App\Services\AuditLog;
use App\Support\AuditGuard;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImpersonationAuditColumnsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_19_000001_add_impersonation_columns_to_audit_tables.php';

    private User $admin;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->kasir = User::where('email', 'kasir@koperasi.test')->first();
        $this->actingAs($this->admin);
    }

    private function impersonate(): Impersonation
    {
        $this->post(route('pengguna.impersonasi.petugas', $this->kasir), ['reason' => 'membantu kasir memeriksa struk', 'minutes' => 10]);

        return Impersonation::latest('started_at')->first();
    }

    public function test_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('activity_logs', ['impersonation_id', 'on_behalf_type', 'on_behalf_id']));
        $this->assertTrue(Schema::hasColumns('impersonation_sessions', ['start_log_id', 'end_log_id']));
    }

    public function test_actions_during_impersonation_fill_attribution_columns_and_are_signed(): void
    {
        $rec = $this->impersonate();
        $log = AuditLog::record('uji.aksi', 'kas', 'aksi', null);
        $this->assertSame([$rec->id, 'petugas', $this->kasir->id], [$log->impersonation_id, $log->on_behalf_type, (int) $log->on_behalf_id]);
        $this->assertTrue(AuditIntegrity::isValid($log));

        // kolom atribusi ikut ditandatangani: mengganti target/sesi terdeteksi
        $raw = (array) DB::table('activity_logs')->where('id', $log->id)->first();
        $this->assertNotSame($raw['checksum'], AuditIntegrity::checksum([...$raw, 'on_behalf_id' => 999]));
        $this->assertNotSame($raw['checksum'], AuditIntegrity::checksum([...$raw, 'impersonation_id' => null, 'on_behalf_type' => null, 'on_behalf_id' => null]));
        $this->assertSame(1, $rec->actionLogs()->count());
    }

    public function test_session_links_its_start_and_end_logs(): void
    {
        $rec = $this->impersonate();
        $this->post('/impersonasi/selesai');
        $rec->refresh();
        $this->assertSame('impersonation.started', $rec->startLog->event);
        $this->assertSame('impersonation.ended', $rec->endLog->event);
        $this->assertSame([$rec->id, $rec->id], [$rec->startLog->impersonation_id, $rec->endLog->impersonation_id]);
        $this->assertNull($rec->startLog->on_behalf_id); // catatan mulai/selesai bukan aksi "atas nama"
    }

    public function test_logs_outside_impersonation_keep_columns_empty_and_old_signature_rule(): void
    {
        $log = AuditLog::record('uji.biasa', 'kas', 'biasa', null);
        $this->assertNull($log->impersonation_id);
        $raw = (array) DB::table('activity_logs')->where('id', $log->id)->first();
        $legacy = $raw;
        unset($legacy['impersonation_id'], $legacy['on_behalf_type'], $legacy['on_behalf_id']);
        $this->assertSame($raw['checksum'], AuditIntegrity::checksum($legacy)); // sama dengan aturan tanda tangan lama
    }

    public function test_second_session_after_first_ended_is_allowed(): void
    {
        $this->impersonate();
        $this->post('/impersonasi/selesai');
        $this->impersonate();
        $this->assertSame(2, Impersonation::count());
        $this->assertSame(1, Impersonation::open()->count());
    }

    public function test_backfill_resigns_only_rows_that_were_still_valid(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Uji migrasi ulang di SQLite.');
        }
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful();

        // baris lama (sebelum kolom ada) dengan keterangan impersonasi hanya di properties
        $props = json_encode(['impersonation' => ['id' => 'aaaaaaaa-0000-0000-0000-000000000001', 'target_type' => 'petugas', 'target_id' => $this->kasir->id, 'target_name' => 'x']]);
        $good = ActivityLog::create(['event' => 'uji.lama', 'category' => 'kas', 'description' => 'lama sah', 'properties' => json_decode($props, true)]);
        $bad = ActivityLog::create(['event' => 'uji.lama', 'category' => 'kas', 'description' => 'lama dirusak', 'properties' => json_decode($props, true)]);
        AuditGuard::suspend(fn () => DB::table('activity_logs')->where('id', $bad->id)->update(['description' => 'diubah diam-diam']));

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();

        $g = DB::table('activity_logs')->where('id', $good->id)->first();
        $b = DB::table('activity_logs')->where('id', $bad->id)->first();
        $this->assertSame('aaaaaaaa-0000-0000-0000-000000000001', $g->impersonation_id);
        $this->assertTrue(AuditIntegrity::isValid(ActivityLog::find($good->id)));
        $this->assertNull($b->impersonation_id);                                      // tidak disentuh
        $this->assertFalse(AuditIntegrity::isValid(ActivityLog::find($bad->id)));      // tetap terdeteksi rusak
        $this->assertSame([$bad->id], app(AuditIntegrity::class)->verify()['invalid']);
    }
}
