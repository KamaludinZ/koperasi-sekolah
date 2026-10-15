<?php

namespace Tests\Feature\Impersonasi;

use App\Livewire\Pengguna\Impersonation as ImpersonationPage;
use App\Models\Impersonation;
use App\Models\Member;
use App\Models\User;
use App\Support\ImpersonationSession;
use Database\Seeders\ProductSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

class StartMemberImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Member $siti;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, ProductSeeder::class]);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->siti = Member::factory()->create(['name' => 'Siti Portal', 'class' => '7A']);
        $this->siti->forceFill(['password' => 'rahasia-portal-1', 'pin' => '123456'])->save();
        $this->actingAs($this->admin);
    }

    private function start(Member $m, array $body = [])
    {
        return $this->post(route('pengguna.impersonasi.anggota', $m), ['reason' => 'membantu anggota memakai portal', 'minutes' => 10, ...$body]);
    }

    public function test_admin_opens_portal_as_member_while_staying_admin_on_web_guard(): void
    {
        $this->start($this->siti)->assertRedirect('/portal');

        $this->assertSame($this->siti->id, Auth::guard('member')->id());
        $this->assertSame($this->admin->id, Auth::guard('web')->id());
        $rec = Impersonation::sole();
        $this->assertSame(['anggota', $this->siti->id, null, $this->siti->member_code.' · 7A'], [$rec->target_type, $rec->target_member_id, $rec->target_user_id, $rec->target_label]);
        $this->assertSame('anggota', ImpersonationSession::current()['target_type']);
        $this->assertNull($this->siti->fresh()->portal_last_login_at); // bukan login sungguhan anggota

        $this->get('/portal')->assertOk()->assertSeeText('Siti Portal')->assertSeeHtml('data-read-only-mode');
        $this->get('/portal/simpanan')->assertOk();
        $this->get('/portal/checkout')->assertForbidden(); // mode lihat saja
        $this->assertDatabaseMissing('activity_logs', ['event' => 'portal.login']);
    }

    public function test_return_logs_member_out_and_closes_record(): void
    {
        $this->start($this->siti);
        $this->post('/impersonasi/selesai')->assertRedirect(route('pengguna.impersonasi'));
        $this->assertNull(Auth::guard('member')->user());
        $this->assertSame($this->admin->id, Auth::guard('web')->id());
        $this->assertSame('manual', Impersonation::sole()->end_reason);
        $this->assertNull(session('portal.pin_token'));
    }

    public function test_eligibility_and_validation(): void
    {
        $noPortal = Member::factory()->create();
        $inactive = Member::factory()->create(['is_active' => false]);
        $inactive->forceFill(['password' => 'rahasia-portal-1'])->save();
        $deleted = Member::factory()->create();
        $deleted->forceFill(['password' => 'rahasia-portal-1'])->save();
        $deletedId = $deleted->id;
        $deleted->delete();

        $this->start($noPortal)->assertSessionHasErrors('target');
        $this->start($inactive->fresh())->assertSessionHasErrors('target');
        $this->post("/pengguna/impersonasi/anggota/{$deletedId}", ['reason' => 'membantu anggota memakai portal', 'minutes' => 10])->assertNotFound();
        $this->start($this->siti, ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->start($this->siti, ['minutes' => 60])->assertSessionHasErrors('minutes');
        $this->assertSame(0, Impersonation::count());
        $this->assertNull(Auth::guard('member')->user());
    }

    public function test_cannot_start_another_impersonation_while_in_member_mode(): void
    {
        $this->start($this->siti);
        $other = Member::factory()->create();
        $other->forceFill(['password' => 'rahasia-portal-1'])->save();
        $this->start($other)->assertSessionHasErrors('target');
        $this->post(route('pengguna.impersonasi.petugas', User::where('email', 'kasir@koperasi.test')->first()), ['reason' => 'membantu kasir memeriksa', 'minutes' => 10])
            ->assertSessionHasErrors('target');
        $this->assertSame(1, Impersonation::count());
        $this->assertSame($this->siti->id, Auth::guard('member')->id());
    }

    public function test_only_admins_can_start_member_impersonation(): void
    {
        $this->actingAs(User::where('email', 'pengurus@koperasi.test')->first());
        $this->start($this->siti)->assertForbidden();
        $this->assertNull(Auth::guard('member')->user());
    }

    public function test_json_contract_and_livewire_dialog(): void
    {
        $this->postJson(route('pengguna.impersonasi.anggota', $this->siti), ['reason' => 'membantu anggota memakai portal', 'minutes' => 15])
            ->assertCreated()->assertJsonPath('data.target.type', 'anggota')->assertJsonPath('data.redirect', '/portal');
        $this->post('/impersonasi/selesai');

        Livewire::test(ImpersonationPage::class)->set('tab', 'anggota')
            ->call('confirm', 'anggota', $this->siti->id)
            ->set('reason', 'membantu anggota memakai portal')->set('acknowledged', true)
            ->call('start')->assertHasNoErrors()->assertRedirect('/portal');
        $this->assertSame($this->siti->id, Auth::guard('member')->id());
    }
}
