<?php

namespace Tests\Feature\Impersonasi;

use App\Models\Impersonation;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\RoleImpersonationTrail;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BannerAuditContextTest extends TestCase
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
        $this->post(route('pengguna.impersonasi.petugas', $this->kasir), ['reason' => 'membantu kasir memeriksa struk', 'minutes' => 10]);
    }

    public function test_banner_counts_actions_recorded_in_this_session(): void
    {
        $this->get('/kasir')->assertSeeHtml('data-recorded-actions')->assertSeeText('0 aksi tercatat');
        AuditLog::record('uji.satu', 'kas', 'aksi satu', null);
        AuditLog::record('uji.dua', 'kas', 'aksi dua', null);
        $this->get('/kasir')->assertSeeText('2 aksi tercatat');
        $this->assertSame(2, RoleImpersonationTrail::actionCount(Impersonation::sole()->id));
    }

    public function test_counts_are_per_session(): void
    {
        AuditLog::record('uji.satu', 'kas', 'aksi satu', null);
        $first = Impersonation::sole()->id;
        $this->post('/impersonasi/selesai');
        $this->post(route('pengguna.impersonasi.petugas', $this->kasir), ['reason' => 'membantu kasir lagi hari ini', 'minutes' => 10]);
        $second = Impersonation::where('id', '!=', $first)->value('id');
        $this->assertSame([1, 0], [RoleImpersonationTrail::actionCount($first), RoleImpersonationTrail::actionCount($second)]);
        $this->assertSame(0, RoleImpersonationTrail::actionCount('bukan-uuid%'));
    }

    public function test_return_toast_summarises_recorded_actions(): void
    {
        AuditLog::record('uji.satu', 'kas', 'aksi satu', null);
        $this->post('/impersonasi/selesai')->assertSessionHas('toast', fn ($t) => str_contains($t['message'], '1 aksi tercatat atas nama Anda'));
    }

    public function test_return_toast_without_actions_has_no_count(): void
    {
        $this->post('/impersonasi/selesai')->assertSessionHas('toast', fn ($t) => ! str_contains($t['message'], 'aksi tercatat'));
    }

    public function test_banner_has_expiry_warning_hook_and_exit_button(): void
    {
        $this->get('/kasir')->assertSeeHtml('get soon()')->assertSeeText('Segera berakhir')->assertSeeHtml('data-impersonation-exit');
    }
}
