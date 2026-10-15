<?php

namespace Tests\Feature\Impersonasi;

use App\Models\Impersonation;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Support\ImpersonationSession;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ImpersonationMiddlewareTest extends TestCase
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

    private function startStaff(int $minutes = 10): Impersonation
    {
        $this->post(route('pengguna.impersonasi.petugas', $this->kasir), ['reason' => 'membantu kasir memeriksa struk', 'minutes' => $minutes])->assertRedirect();

        return Impersonation::latest('started_at')->first();
    }

    public function test_valid_session_marks_responses_and_disables_caching(): void
    {
        $rec = $this->startStaff();
        $res = $this->get('/kasir')->assertOk()->assertHeader('X-Impersonation', $rec->id);
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));

        $this->post('/impersonasi/selesai');
        $this->assertFalse($this->get('/pengguna')->headers->has('X-Impersonation'));
    }

    public function test_expired_session_ends_automatically_and_returns_admin(): void
    {
        $rec = $this->startStaff(10);
        $this->travel(11)->minutes();

        $this->get('/kasir')->assertRedirect(route('pengguna.impersonasi'))
            ->assertSessionHas('toast', fn ($t) => str_contains($t['message'], 'Waktu impersonasi (10 menit) habis') && str_contains($t['message'], 'Anda kembali sebagai '.$this->admin->name));
        $this->assertSame('kedaluwarsa', $rec->fresh()->end_reason);
        $this->assertSame($this->admin->id, Auth::guard('web')->id());
        $this->assertNull(session(ImpersonationSession::KEY));
    }

    public function test_livewire_and_json_requests_get_409_when_session_ends(): void
    {
        $this->startStaff(10);
        $this->travel(11)->minutes();
        $this->getJson('/barang/daftar')->assertStatus(409)->assertJsonPath('impersonation_ended', 'kedaluwarsa');
        $this->assertSame($this->admin->id, Auth::guard('web')->id());
    }

    public function test_session_closed_elsewhere_is_cleaned_up(): void
    {
        $rec = $this->startStaff();
        $rec->update(['ended_at' => now(), 'end_reason' => 'sesi_berakhir']); // ditutup dari perangkat/tab lain
        $this->get('/kasir')->assertRedirect(route('pengguna.impersonasi'));
        $this->assertSame($this->admin->id, Auth::guard('web')->id());
        $this->assertSame('sesi_berakhir', $rec->fresh()->end_reason);
    }

    public function test_target_becoming_ineligible_ends_session_with_reason(): void
    {
        $rec = $this->startStaff();
        $this->kasir->roles()->attach(Role::named('admin')->id); // kini memegang Admin
        $this->get('/kasir')->assertRedirect(route('pengguna.impersonasi'))
            ->assertSessionHas('toast', fn ($t) => str_contains($t['message'], 'tidak lagi bisa dimasuki'));
        $this->assertSame('dicabut', $rec->fresh()->end_reason);
        $this->assertSame($this->admin->id, Auth::guard('web')->id());
    }

    public function test_deactivated_target_does_not_log_admin_out(): void
    {
        $rec = $this->startStaff();
        $this->kasir->forceFill(['is_active' => false])->save();
        $this->get('/kasir')->assertRedirect(route('pengguna.impersonasi'));
        $this->assertSame('dicabut', $rec->fresh()->end_reason);
        $this->assertSame($this->admin->id, Auth::guard('web')->id()); // bukan dikeluarkan EnsureActiveSession
    }

    public function test_admin_losing_rights_ends_everything(): void
    {
        $rec = $this->startStaff();
        $this->admin->forceFill(['is_active' => false])->save();
        $this->get('/kasir')->assertRedirect(route('login'));
        $this->assertSame('dicabut', $rec->fresh()->end_reason);
        $this->assertNull(Auth::guard('web')->user());
    }

    public function test_guard_mismatch_is_rejected(): void
    {
        $rec = $this->startStaff();
        $this->actingAs(User::where('email', 'pengurus@koperasi.test')->first(), 'web'); // akun yang masuk tak lagi cocok dengan target
        $this->get('/laporan')->assertRedirect(route('pengguna.impersonasi'));
        $this->assertSame('sesi_berakhir', $rec->fresh()->end_reason);
    }

    public function test_member_impersonation_validated_too(): void
    {
        $m = Member::factory()->create();
        $m->forceFill(['password' => 'rahasia-portal-1'])->save();
        $this->post(route('pengguna.impersonasi.anggota', $m), ['reason' => 'membantu anggota memakai portal', 'minutes' => 10]);
        $rec = Impersonation::sole();
        $this->get('/portal')->assertOk()->assertHeader('X-Impersonation', $rec->id);

        $m->forceFill(['is_active' => false])->save();
        $this->get('/portal')->assertRedirect(route('pengguna.impersonasi'));
        $this->assertSame('dicabut', $rec->fresh()->end_reason);
        $this->assertNull(Auth::guard('member')->user());
        $this->assertSame($this->admin->id, Auth::guard('web')->id());
    }

    public function test_forged_session_state_without_record_is_discarded(): void
    {
        session([ImpersonationSession::KEY => ['id' => 'bbbbbbbb-0000-0000-0000-000000000000', 'admin_id' => $this->admin->id, 'admin_name' => 'A',
            'target_type' => 'petugas', 'target_id' => $this->admin->id, 'target_name' => 'x', 'reason' => 'pura pura membantu',
            'started_at' => now()->timestamp, 'expires_at' => now()->addHour()->timestamp]]);
        $this->get('/pengguna')->assertRedirect(route('pengguna.impersonasi'));
        $this->assertNull(session(ImpersonationSession::KEY));
        $this->get('/pengguna')->assertOk()->assertDontSeeHtml('data-impersonation-banner');
    }

    public function test_malformed_state_is_dropped_silently(): void
    {
        session([ImpersonationSession::KEY => 'rusak']);
        $this->get('/pengguna')->assertOk();
        $this->assertNull(session(ImpersonationSession::KEY));
    }
}
