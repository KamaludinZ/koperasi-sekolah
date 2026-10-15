<?php

namespace Tests\Feature\Impersonasi;

use App\Models\ActivityLog;
use App\Models\Impersonation;
use App\Models\Member;
use App\Models\User;
use App\Support\ImpersonationPolicy;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class UnifiedStartEndpointTest extends TestCase
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

    public function test_start_staff_with_indonesian_fields_records_reason_everywhere(): void
    {
        $res = $this->postJson('/pengguna/impersonasi', ['jenis' => 'petugas', 'id' => $this->kasir->id, 'alasan' => 'kasir melapor struk gagal tercetak', 'menit' => 15])
            ->assertCreated()
            ->assertJsonPath('data.target.type', 'petugas')
            ->assertJsonPath('data.reason', 'kasir melapor struk gagal tercetak')
            ->assertJsonPath('data.redirect', '/kasir')
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.actions_recorded', 0);

        $rec = Impersonation::sole();
        $this->assertSame('kasir melapor struk gagal tercetak', $rec->reason);
        $log = ActivityLog::find($rec->start_log_id);
        $this->assertSame(['impersonation.started', 'kasir melapor struk gagal tercetak'], [$log->event, $log->reason]);
        $this->assertSame($rec->start_log_id, $res->json('data.start_log_id'));
        $this->assertSame($this->kasir->id, Auth::guard('web')->id());
    }

    public function test_start_member_with_english_aliases(): void
    {
        $m = Member::factory()->create();
        $m->forceFill(['password' => 'rahasia-portal-1'])->save();
        $this->postJson('/pengguna/impersonasi', ['jenis' => 'anggota', 'id' => $m->id, 'reason' => 'anggota tidak bisa melihat saldo', 'minutes' => 10])
            ->assertCreated()->assertJsonPath('data.redirect', '/portal')->assertJsonPath('data.target.type', 'anggota');
        $this->assertSame($m->id, Auth::guard('member')->id());
    }

    public function test_reason_quality_rules(): void
    {
        foreach (['..........', '1234567890 12', 'aaaaaaaaaaaa', 'pemeriksaanstruk'] as $bad) {
            $this->assertNotNull(ImpersonationPolicy::reasonQuality($bad), $bad);
            $this->postJson('/pengguna/impersonasi', ['jenis' => 'petugas', 'id' => $this->kasir->id, 'alasan' => $bad, 'menit' => 10])
                ->assertUnprocessable()->assertJsonValidationErrors('reason');
        }
        foreach (ImpersonationPolicy::REASON_PRESETS['petugas'] as $ok) {
            $this->assertNull(ImpersonationPolicy::reasonQuality($ok), $ok);
        }
        $this->assertSame(0, Impersonation::count());
    }

    public function test_input_validation(): void
    {
        $this->postJson('/pengguna/impersonasi', ['jenis' => 'raja', 'id' => 1, 'alasan' => 'membantu memeriksa layar', 'menit' => 10])->assertUnprocessable()->assertJsonValidationErrors('jenis');
        $this->postJson('/pengguna/impersonasi', ['jenis' => 'petugas', 'alasan' => 'membantu memeriksa layar', 'menit' => 10])->assertUnprocessable()->assertJsonValidationErrors('id');
        $this->postJson('/pengguna/impersonasi', ['jenis' => 'petugas', 'id' => 999999, 'alasan' => 'membantu memeriksa layar', 'menit' => 10])->assertNotFound();
        $this->postJson('/pengguna/impersonasi', ['jenis' => 'petugas', 'id' => $this->kasir->id, 'alasan' => 'membantu memeriksa layar', 'menit' => 60])->assertUnprocessable()->assertJsonValidationErrors('minutes');
        $this->postJson('/pengguna/impersonasi', ['jenis' => 'petugas', 'id' => $this->admin->id, 'alasan' => 'membantu memeriksa layar', 'menit' => 10])->assertUnprocessable()->assertJsonValidationErrors('target');
    }

    public function test_active_endpoint(): void
    {
        $this->getJson('/pengguna/impersonasi/aktif')->assertOk()->assertJsonPath('data', null);

        $m = Member::factory()->create();
        $m->forceFill(['password' => 'rahasia-portal-1'])->save();
        $this->postJson('/pengguna/impersonasi', ['jenis' => 'anggota', 'id' => $m->id, 'alasan' => 'anggota tidak bisa melihat saldo', 'menit' => 10]);
        // guard web tetap admin selama impersonasi anggota → admin bisa melihat sesinya sendiri
        $this->getJson('/pengguna/impersonasi/aktif')->assertOk()->assertJsonPath('data.target.id', $m->id)->assertJsonPath('data.active', true);
    }

    public function test_only_admin(): void
    {
        $this->actingAs(User::where('email', 'pengurus@koperasi.test')->first());
        $this->postJson('/pengguna/impersonasi', ['jenis' => 'petugas', 'id' => $this->kasir->id, 'alasan' => 'membantu memeriksa layar', 'menit' => 10])->assertForbidden();
        $this->getJson('/pengguna/impersonasi/aktif')->assertForbidden();
    }
}
