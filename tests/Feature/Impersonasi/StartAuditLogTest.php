<?php

namespace Tests\Feature\Impersonasi;

use App\Models\ActivityLog;
use App\Models\Impersonation;
use App\Models\Member;
use App\Models\User;
use App\Services\AuditIntegrity;
use Database\Seeders\UserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StartAuditLogTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_staff_impersonation_start_is_logged_with_admin_as_actor(): void
    {
        $this->post(route('pengguna.impersonasi.petugas', $this->kasir), ['reason' => 'membantu kasir memeriksa struk', 'minutes' => 15], ['User-Agent' => 'UjiPeramban/1.0']);
        $rec = Impersonation::sole();
        $log = ActivityLog::where('event', 'impersonation.started')->sole();

        $this->assertSame('auth', $log->category);
        $this->assertSame([$this->admin->id, $this->admin->name, 'admin'], [$log->actor_id, $log->actor_name, $log->actor_role]); // bukan kasir
        $this->assertSame(['user', $this->kasir->id], [$log->subject_type, (int) $log->subject_id]);
        $this->assertSame($this->kasir->name.' · Kasir', $log->subject_label);
        $this->assertSame('membantu kasir memeriksa struk', $log->reason);
        $this->assertSame($rec->id, $log->properties['impersonation_id']);
        $this->assertSame(['petugas', 15], [$log->properties['target_type'], $log->properties['minutes']]);
        $this->assertSame($rec->expires_at->toIso8601String(), $log->properties['expires_at']);
        $this->assertSame('UjiPeramban/1.0', $log->user_agent);
        $this->assertStringContainsString('masuk sebagai '.$this->kasir->name.' (petugas · Kasir)', $log->description);
        $this->assertTrue(AuditIntegrity::isValid($log)); // checksum HMAC sah
    }

    public function test_member_impersonation_start_is_logged(): void
    {
        $m = Member::factory()->create(['name' => 'Siti']);
        $m->forceFill(['password' => 'rahasia-portal-1'])->save();
        $this->post(route('pengguna.impersonasi.anggota', $m), ['reason' => 'membantu anggota memakai portal', 'minutes' => 10]);
        $log = ActivityLog::where('event', 'impersonation.started')->sole();
        $this->assertSame(['member', $m->id, $this->admin->id], [$log->subject_type, (int) $log->subject_id, $log->actor_id]);
        $this->assertStringContainsString('(portal anggota)', $log->description);
        $this->assertSame('anggota', $log->properties['target_type']);
    }

    public function test_rejected_attempt_is_not_logged_as_started(): void
    {
        $this->post(route('pengguna.impersonasi.petugas', $this->admin), ['reason' => 'mencoba diri sendiri', 'minutes' => 10]);
        $this->post(route('pengguna.impersonasi.petugas', $this->kasir), ['reason' => 'x', 'minutes' => 10]);
        $this->assertSame(0, ActivityLog::where('event', 'impersonation.started')->count());
    }

    public function test_tampering_with_the_log_is_detected(): void
    {
        $this->post(route('pengguna.impersonasi.petugas', $this->kasir), ['reason' => 'membantu kasir memeriksa struk', 'minutes' => 10]);
        $log = ActivityLog::where('event', 'impersonation.started')->sole();
        $raw = (array) DB::table('activity_logs')->where('id', $log->id)->first();

        // checksum HMAC berubah bila isi log diubah (mis. alasan/pelaku diganti langsung di basis data)
        $this->assertNotSame($raw['checksum'], AuditIntegrity::checksum([...$raw, 'reason' => 'alasan diganti']));
        $this->assertNotSame($raw['checksum'], AuditIntegrity::checksum([...$raw, 'actor_id' => $this->kasir->id]));
        $this->assertSame([], app(AuditIntegrity::class)->verify()['invalid']);

        // dan trigger basis data menolak perubahan/penghapusan baris log (hanya boleh ditambah)
        foreach ([fn () => DB::table('activity_logs')->where('id', $log->id)->update(['reason' => 'alasan diganti']),
            fn () => DB::table('activity_logs')->where('id', $log->id)->delete()] as $tamper) {
            try {
                DB::transaction($tamper);
                $this->fail('log audit tidak boleh bisa diubah/dihapus');
            } catch (QueryException) {
                $this->assertTrue(AuditIntegrity::isValid($log));
            }
        }
    }

    public function test_impersonation_is_rolled_back_when_audit_cannot_be_written(): void
    {
        ActivityLog::creating(function (ActivityLog $l) {
            if ($l->event === 'impersonation.started') {
                throw new \RuntimeException('disk penuh');
            }
        });
        $this->post(route('pengguna.impersonasi.petugas', $this->kasir), ['reason' => 'membantu kasir memeriksa struk', 'minutes' => 10]);
        $this->assertSame(0, Impersonation::count());
        $this->assertSame($this->admin->id, Auth::guard('web')->id()); // tetap admin, tak ada sesi tanpa jejak
    }
}
