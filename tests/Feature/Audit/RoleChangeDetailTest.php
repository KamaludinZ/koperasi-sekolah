<?php

namespace Tests\Feature\Audit;

use App\Livewire\Audit\RoleImpersonation;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\RoleImpersonationTrail;
use App\Services\UserService;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RoleChangeDetailTest extends TestCase
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

    public function test_detail_shows_before_after_diff_menu_impact_and_seal(): void
    {
        app(UserService::class)->updateRoles($this->kasir, ['kasir', 'pengurus'], $this->admin);
        $log = ActivityLog::where('event', 'user.role_changed')->sole();
        $d = app(RoleImpersonationTrail::class)->roleChangeDetail($log->id);

        $this->assertSame('akun', $d['kind']);
        $this->assertSame(['kasir'], $d['before']);
        $this->assertSame(['pengurus', 'kasir'], $d['after']);
        $this->assertSame(['pengurus'], $d['added']);
        $this->assertContains('Pinjaman', $d['menu_impact']['gained']);
        $this->assertSame([], $d['menu_impact']['lost']);
        $this->assertTrue($d['valid']);

        Livewire::test(RoleImpersonation::class)->call('showDetail', $log->id)
            ->assertSet('detailId', $log->id)
            ->assertSeeHtml('data-role-detail="'.$log->id.'"')
            ->assertSeeHtml('data-detail-seal="utuh"')
            ->assertSeeText('+ Pengurus ditambahkan')
            ->assertSeeHtml('data-detail-menu-impact')
            ->assertSeeHtml('data-detail-device') // admin melihat IP/perangkat
            ->call('closeDetail')->assertSet('detailId', null)->assertDontSeeHtml('data-role-detail');
    }

    public function test_removed_role_shows_lost_menus(): void
    {
        $u = User::factory()->role('kasir')->create();
        $u->roles()->attach(Role::named('pengurus')->id);
        app(UserService::class)->removeRole($u->fresh(), 'pengurus', $this->admin);
        $d = app(RoleImpersonationTrail::class)->roleChangeDetail(ActivityLog::where('event', 'user.role_changed')->latest('id')->value('id'));
        $this->assertSame(['pengurus'], $d['removed']);
        $this->assertContains('Pinjaman', $d['menu_impact']['lost']);
    }

    public function test_role_permission_detail_lists_menus_and_holders(): void
    {
        app(UserService::class)->updatePermissions(Role::named('kasir'), [...Role::named('kasir')->permissions, 'laporan']);
        $d = app(RoleImpersonationTrail::class)->roleChangeDetail(ActivityLog::where('event', 'role.permissions_updated')->value('id'));
        $this->assertSame('peran', $d['kind']);
        $this->assertSame(['laporan'], $d['added']);
        $this->assertStringContainsString('Laporan', $d['menus_after']);
        $this->assertSame(User::holdingRole('kasir')->count(), $d['holders']);
    }

    public function test_change_made_while_impersonating_shows_context(): void
    {
        $other = User::factory()->role('kasir')->create();
        $this->post(route('pengguna.impersonasi.petugas', $this->kasir), ['reason' => 'membantu kasir memeriksa struk', 'minutes' => 10]);
        // kasir tak bisa mengubah peran — simulasikan perubahan peran tercatat otomatis selama impersonasi
        $other->roles()->attach(Role::named('pengurus')->id);
        $log = ActivityLog::where('event', 'user.role_added')->sole();
        $this->post('/impersonasi/selesai');

        $d = app(RoleImpersonationTrail::class)->roleChangeDetail($log->id);
        $this->assertSame($this->kasir->name, $d['impersonation']['target_name']);
        $this->assertSame($this->admin->name, $d['actor']['name']);
        $this->assertTrue($d['automatic']);
        Livewire::test(RoleImpersonation::class)->call('showDetail', $log->id)->assertSeeHtml('data-detail-impersonation');
    }

    public function test_non_role_logs_and_bogus_ids_cannot_be_opened(): void
    {
        AuditLog::record('uji.lain', 'kas', 'bukan perubahan peran', null);
        $other = ActivityLog::where('event', 'uji.lain')->value('id');
        $this->assertNull(app(RoleImpersonationTrail::class)->roleChangeDetail($other));
        Livewire::test(RoleImpersonation::class)->call('showDetail', $other)->assertSet('detailId', null)
            ->call('showDetail', 999999)->assertSet('detailId', null);
        $this->get('/audit/peran-impersonasi?rinci=999999')->assertOk()->assertDontSeeHtml('data-role-detail');
        $this->get('/audit/peran-impersonasi?rinci[]=1')->assertOk();
    }

    public function test_pengurus_sees_detail_without_device_info(): void
    {
        app(UserService::class)->addRole($this->kasir, 'pengurus', $this->admin);
        $id = ActivityLog::where('event', 'user.role_changed')->value('id');
        $this->actingAs(User::where('email', 'pengurus@koperasi.test')->first());
        $this->get('/audit/peran-impersonasi?rinci='.$id)->assertOk()->assertSeeHtml('data-role-detail="'.$id.'"')->assertDontSeeHtml('data-detail-device');
    }
}
