<?php

namespace Tests\Feature\Impersonasi;

use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Support\ImpersonationSession;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\FakesImpersonation;
use Tests\TestCase;

class ReturnToOriginalSessionTest extends TestCase
{
    use FakesImpersonation, RefreshDatabase;

    private User $admin;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->kasir = User::where('email', 'kasir@koperasi.test')->first();
    }

    private function state(string $type, int $targetId, string $name): array
    {
        return $this->impersonationRecord([
            'id' => 'aaaaaaaa-1111-2222-3333-444444444444', 'admin_id' => $this->admin->id, 'admin_name' => $this->admin->name,
            'target_type' => $type, 'target_id' => $targetId, 'target_name' => $name, 'target_label' => null,
            'reason' => 'membantu memeriksa layar', 'started_at' => now()->timestamp, 'expires_at' => now()->addMinutes(30)->timestamp,
        ]);
    }

    public function test_banner_and_sidebar_show_return_button_while_impersonating_staff(): void
    {
        $this->actingAs($this->kasir)->withSession($this->state('petugas', $this->kasir->id, $this->kasir->name));
        $this->get('/kasir')->assertOk()
            ->assertSeeHtml('data-impersonation-exit')
            ->assertSeeHtml('data-impersonation-exit-sidebar')
            ->assertSeeHtml('action="'.route('impersonasi.selesai').'"')
            ->assertDontSeeHtml('action="'.route('logout').'"');
    }

    public function test_one_click_returns_admin_from_staff_impersonation(): void
    {
        $this->actingAs($this->kasir)->withSession($this->state('petugas', $this->kasir->id, $this->kasir->name));
        $this->post('/impersonasi/selesai')
            ->assertRedirect(route('pengguna.impersonasi'))
            ->assertSessionHas('toast', fn ($t) => $t['type'] === 'success' && str_contains($t['message'], 'Anda kembali sebagai '.$this->admin->name)
                && str_contains($t['message'], 'Sesi sebagai '.$this->kasir->name.' ditutup'));
        $this->assertSame($this->admin->id, Auth::guard('web')->id());
        $this->assertNull(session(ImpersonationSession::KEY));
        $this->get('/pengguna')->assertOk()->assertDontSeeHtml('data-impersonation-banner');
    }

    public function test_one_click_returns_admin_from_member_impersonation(): void
    {
        $m = Member::factory()->create(['name' => 'Siti']);
        $m->forceFill(['password' => 'rahasia-portal-1'])->save();
        $this->actingAs($this->admin)->actingAs($m->fresh(), 'member')->withSession($this->state('anggota', $m->id, 'Siti'));

        $this->post('/impersonasi/selesai')->assertRedirect(route('pengguna.impersonasi'));
        $this->assertNull(Auth::guard('member')->user());
        $this->assertSame($this->admin->id, Auth::guard('web')->id());
        $this->get('/portal')->assertRedirect(route('portal.login'));
    }

    public function test_portal_logout_while_impersonating_member_returns_to_admin(): void
    {
        $m = Member::factory()->create(['name' => 'Siti']);
        $m->forceFill(['password' => 'rahasia-portal-1'])->save();
        $this->actingAs($this->admin)->actingAs($m->fresh(), 'member')->withSession($this->state('anggota', $m->id, 'Siti'));
        $this->post('/portal/keluar')->assertRedirect(route('pengguna.impersonasi'));
        $this->assertNull(Auth::guard('member')->user());
        $this->assertSame($this->admin->id, Auth::guard('web')->id());
    }

    public function test_logout_while_impersonating_returns_to_admin_instead_of_logging_out(): void
    {
        $this->actingAs($this->kasir)->withSession($this->state('petugas', $this->kasir->id, $this->kasir->name));
        $this->post('/keluar')->assertRedirect(route('pengguna.impersonasi'));
        $this->assertSame($this->admin->id, Auth::guard('web')->id());

        $this->post('/keluar')->assertRedirect(route('login')); // tanpa impersonasi: keluar biasa
        $this->assertNull(Auth::guard('web')->user());
    }

    public function test_deactivated_admin_is_not_restored(): void
    {
        $this->admin->forceFill(['is_active' => false])->save();
        $this->actingAs($this->kasir)->withSession($this->state('petugas', $this->kasir->id, $this->kasir->name));
        $this->post('/impersonasi/selesai')->assertRedirect(route('login'));
        $this->assertNull(Auth::guard('web')->user());
    }

    public function test_admin_who_lost_admin_role_is_not_restored(): void
    {
        $this->admin->roles()->detach();
        $this->admin->forceFill(['role_id' => Role::named('kasir')->id])->save();
        $this->actingAs($this->kasir)->withSession($this->state('petugas', $this->kasir->id, $this->kasir->name));
        $this->post('/impersonasi/selesai')->assertRedirect(route('login'));
        $this->assertNull(Auth::guard('web')->user());
    }

    public function test_without_impersonation_nothing_changes(): void
    {
        $this->actingAs($this->kasir);
        $this->post('/impersonasi/selesai')->assertRedirect(route('beranda'));
        $this->assertSame($this->kasir->id, Auth::guard('web')->id());
        $this->postJson('/impersonasi/selesai')->assertOk()->assertJson(['ok' => false]);
        // GET tidak menjalankan aksinya: diarahkan ke beranda (bukan galat 405), akun tidak berubah.
        $this->get('/impersonasi/selesai')->assertRedirect('/');
        $this->assertSame($this->kasir->id, Auth::guard('web')->id());
    }

    public function test_session_id_changes_on_return(): void
    {
        $this->actingAs($this->kasir)->withSession($this->state('petugas', $this->kasir->id, $this->kasir->name));
        $this->get('/kasir');
        $before = session()->getId();
        $this->post('/impersonasi/selesai');
        $this->assertNotSame($before, session()->getId());
    }
}
