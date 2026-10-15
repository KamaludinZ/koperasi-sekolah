<?php

namespace Tests\Feature\Audit;

use App\Livewire\Audit\RoleImpersonation;
use App\Models\ActivityLog;
use App\Models\Impersonation;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\RoleImpersonationTrail;
use App\Services\UserService;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RoleImpersonationTrailPageTest extends TestCase
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

    private function impersonateAndAct(): Impersonation
    {
        $this->post(route('pengguna.impersonasi.petugas', $this->kasir), ['reason' => 'membantu kasir memeriksa struk', 'minutes' => 10]);
        AuditLog::record('uji.aksi', 'kas', 'Aksi uji saat impersonasi', null);
        $this->travel(2)->minutes();
        $this->post('/impersonasi/selesai');

        return Impersonation::sole();
    }

    public function test_role_changes_tab_lists_changes_with_seal(): void
    {
        app(UserService::class)->addRole($this->kasir, 'pengurus', $this->admin);
        $log = ActivityLog::where('event', 'user.role_changed')->sole();

        $this->get('/audit/peran-impersonasi')->assertOk()
            ->assertSeeText('Peran & impersonasi')
            ->assertSeeHtml('data-log="'.$log->id.'"')
            ->assertSeeText('+ Pengurus')
            ->assertSeeHtmlInOrder(['data-log="'.$log->id.'"', 'data-seal="utuh"'])
            ->assertSeeHtml('data-stat="role_changes">1<');
    }

    public function test_sessions_tab_shows_who_as_whom_reason_duration_and_seal(): void
    {
        $rec = $this->impersonateAndAct();
        Livewire::test(RoleImpersonation::class)->set('tab', 'impersonasi')
            ->assertSeeHtml('data-session="'.$rec->id.'"')
            ->assertSeeText($this->admin->name.' masuk sebagai '.$this->kasir->name)
            ->assertSeeText('Alasan: membantu kasir memeriksa struk')
            ->assertSeeText('2 menit · Kembali lewat tombol')
            ->assertSeeHtml('data-seal="utuh"')
            ->set('status', 'aktif')->assertSeeHtml('data-empty');
    }

    public function test_actions_tab_lists_actions_done_while_impersonating(): void
    {
        $this->impersonateAndAct();
        AuditLog::record('uji.biasa', 'kas', 'Aksi biasa tanpa impersonasi', null);
        Livewire::test(RoleImpersonation::class)->set('tab', 'aksi')
            ->assertSeeText('Aksi uji saat impersonasi')
            ->assertSeeText('sebagai '.$this->kasir->name)
            ->assertDontSeeText('Aksi biasa tanpa impersonasi');
    }

    public function test_active_session_and_integrity_cards(): void
    {
        $this->post(route('pengguna.impersonasi.petugas', $this->kasir), ['reason' => 'membantu kasir memeriksa struk', 'minutes' => 10]);
        $this->post('/impersonasi/selesai');
        $stats = app(RoleImpersonationTrail::class)->stats('30');
        $this->assertSame([1, 0, 0], [$stats['sessions'], $stats['active'], $stats['invalid']]);
        $this->assertSame(2, $stats['checked']); // mulai & selesai
    }

    public function test_filters_and_normalization(): void
    {
        $this->impersonateAndAct();
        Livewire::test(RoleImpersonation::class)->set('tab', 'impersonasi')
            ->set('search', 'struk')->assertSeeText($this->kasir->name)
            ->set('search', 'tidak-ada-apa-apa')->assertSeeHtml('data-empty')
            ->call('resetFilters')->assertSet('search', '')
            ->set('tab', 'ngawur')->assertSet('tab', 'peran')
            ->set('period', 'abc')->assertSet('period', '30');
        $this->get('/audit/peran-impersonasi?tampil[]=x&periode=zz')->assertOk();
    }

    public function test_access_follows_audit_page_rules(): void
    {
        $this->actingAs(User::where('email', 'pengurus@koperasi.test')->first());
        $this->get('/audit/peran-impersonasi')->assertOk();
        $this->actingAs($this->kasir);
        $this->get('/audit/peran-impersonasi')->assertForbidden();
    }
}
