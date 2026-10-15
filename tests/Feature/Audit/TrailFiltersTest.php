<?php

namespace Tests\Feature\Audit;

use App\Livewire\Audit\RoleImpersonation;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\RoleImpersonationTrail;
use App\Services\UserService;
use App\Support\AuditVerificationStatus;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class TrailFiltersTest extends TestCase
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
        Cache::forget(AuditVerificationStatus::KEY);

        app(UserService::class)->addRole($this->kasir, 'pengurus', $this->admin);                                              // peran akun
        app(UserService::class)->updatePermissions(Role::named('kasir'), [...Role::named('kasir')->permissions, 'laporan']); // hak menu
        $this->post(route('pengguna.impersonasi.petugas', $this->kasir), ['reason' => 'membantu kasir memeriksa struk', 'minutes' => 10]);
        AuditLog::record('uji.kas', 'kas', 'aksi kas saat impersonasi', null);
        AuditLog::record('uji.barang', 'barang', 'aksi barang saat impersonasi', null);
        $this->post('/impersonasi/selesai');
    }

    public function test_tab_counts(): void
    {
        $this->assertSame(['peran' => 2, 'impersonasi' => 1, 'aksi' => 2], app(RoleImpersonationTrail::class)->counts(['period' => '30']));
        $this->get('/audit/peran-impersonasi')->assertSeeHtml('data-tab-count="peran">2<')->assertSeeHtml('data-tab-count="aksi">2<');
    }

    public function test_kind_filter_on_role_tab(): void
    {
        Livewire::test(RoleImpersonation::class)
            ->set('kind', 'menu')->assertSeeText('Hak akses peran Kasir diubah')->assertDontSeeText('Peran '.$this->kasir->name.' diubah')
            ->set('kind', 'akun')->assertSeeText('Peran '.$this->kasir->name.' diubah')
            ->set('kind', 'ngawur')->assertSet('kind', '');
    }

    public function test_category_filter_on_actions_tab(): void
    {
        Livewire::test(RoleImpersonation::class)->set('tab', 'aksi')
            ->set('category', 'barang')->assertSeeText('aksi barang saat impersonasi')->assertDontSeeText('aksi kas saat impersonasi')
            ->assertSeeHtml('data-tab-count="aksi">1<')
            ->set('category', 'bukan-kategori')->assertSet('category', '');
    }

    public function test_only_invalid_filter_uses_last_verification(): void
    {
        $roleLog = ActivityLog::where('event', 'role.permissions_updated')->value('id');
        AuditVerificationStatus::store(['checked' => 10, 'invalid' => [$roleLog], 'unsigned' => 0, 'first_id' => 1, 'last_id' => 10], 'manual', 'uji');

        Livewire::test(RoleImpersonation::class)->set('seal', true)->assertSet('seal', 'rusak')
            ->assertSeeHtml('data-log="'.$roleLog.'"')->assertSeeHtml('data-tab-count="peran">1<')
            ->assertSeeHtml('data-tab-count="impersonasi">0<');
        $this->assertSame(1, app(RoleImpersonationTrail::class)->counts(['period' => '30', 'seal' => 'rusak'])['peran']);

        Cache::forget(AuditVerificationStatus::KEY);
        $this->assertSame(0, app(RoleImpersonationTrail::class)->counts(['period' => '30', 'seal' => 'rusak'])['peran']);
    }

    public function test_reset_clears_every_filter_and_url_values_are_normalized(): void
    {
        Livewire::test(RoleImpersonation::class)->set('kind', 'menu')->set('seal', true)->set('search', 'x')
            ->call('resetFilters')->assertSet('kind', '')->assertSet('seal', '')->assertSet('search', '');
        $this->get('/audit/peran-impersonasi?jenis[]=x&kategori=zz&segel=apa')->assertOk();
    }
}
