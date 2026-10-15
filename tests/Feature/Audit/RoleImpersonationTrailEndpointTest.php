<?php

namespace Tests\Feature\Audit;

use App\Models\ActivityLog;
use App\Models\Impersonation;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\UserService;
use App\Support\AuditGuard;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoleImpersonationTrailEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $kasir;

    private Impersonation $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->kasir = User::where('email', 'kasir@koperasi.test')->first();
        $this->actingAs($this->admin);

        app(UserService::class)->updateRoles($this->kasir, ['kasir', 'pengurus'], $this->admin, 'merangkap bendahara');
        $this->post(route('pengguna.impersonasi.petugas', $this->kasir->fresh()), ['reason' => 'membantu kasir memeriksa struk', 'minutes' => 10]);
        AuditLog::record('uji.aksi', 'kas', 'aksi saat impersonasi', null);
        $this->post('/impersonasi/selesai');
        $this->session = Impersonation::sole();
    }

    public function test_role_changes_list(): void
    {
        $res = $this->getJson('/audit/peran-impersonasi/data')->assertOk();
        $row = $res->json('data.0');
        $this->assertSame('user.role_changed', $row['event']);
        $this->assertSame(['pengurus'], $row['diff']['added']);
        $this->assertSame('merangkap bendahara', $row['reason']);
        $this->assertTrue($row['valid']);
        $this->assertArrayHasKey('ip_address', $row); // admin
        $res->assertJsonPath('meta.counts.impersonasi', 1)->assertJsonPath('meta.counts.aksi', 1)->assertJsonPath('meta.tab', 'peran');
    }

    public function test_sessions_list_with_chain_and_action_count(): void
    {
        $row = $this->getJson('/audit/peran-impersonasi/data?tampil=impersonasi')->assertOk()->json('data.0');
        $this->assertSame([$this->session->id, 'manual', 1, true], [$row['id'], $row['end_reason'], $row['actions_recorded'], $row['chain']['ok']]);
        $this->assertSame(['type' => 'petugas', 'id' => $this->kasir->id, 'name' => $this->kasir->name, 'label' => 'Pengurus · Kasir'], $row['target']);
    }

    public function test_actions_list_marks_on_behalf(): void
    {
        $row = $this->getJson('/audit/peran-impersonasi/data?tampil=aksi')->assertOk()->json('data.0');
        $this->assertSame(['uji.aksi', $this->admin->id], [$row['event'], $row['actor']['id']]);
        $this->assertSame(['id' => $this->session->id, 'on_behalf' => ['type' => 'petugas', 'id' => $this->kasir->id, 'name' => $this->kasir->name]], $row['impersonation']);
    }

    public function test_session_detail_lists_actions(): void
    {
        $this->getJson('/audit/peran-impersonasi/sesi/'.$this->session->id)->assertOk()
            ->assertJsonPath('data.reason', 'membantu kasir memeriksa struk')
            ->assertJsonPath('data.chain.linked', true)
            ->assertJsonPath('data.actions.0.event', 'uji.aksi')
            ->assertJsonPath('data.actions.0.valid', true);
        $this->getJson('/audit/peran-impersonasi/sesi/aaaaaaaa-0000-0000-0000-000000000000')->assertNotFound();
        $this->getJson('/audit/peran-impersonasi/sesi/bukan-uuid')->assertNotFound();
    }

    public function test_role_change_detail(): void
    {
        $id = ActivityLog::where('event', 'user.role_changed')->value('id');
        $this->getJson('/audit/peran-impersonasi/catatan/'.$id)->assertOk()
            ->assertJsonPath('data.added', ['pengurus'])->assertJsonPath('data.valid', true)->assertJsonPath('data.actor.ip', '127.0.0.1');
        $this->getJson('/audit/peran-impersonasi/catatan/'.ActivityLog::where('event', 'uji.aksi')->value('id'))->assertNotFound();
    }

    public function test_filters_validation_and_tampered_filter(): void
    {
        $this->getJson('/audit/peran-impersonasi/data?tampil=x')->assertUnprocessable();
        $this->getJson('/audit/peran-impersonasi/data?periode=99')->assertUnprocessable();
        $this->getJson('/audit/peran-impersonasi/data?per_halaman=500')->assertUnprocessable();
        $this->getJson('/audit/peran-impersonasi/data?tampil=aksi&kategori=barang')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/audit/peran-impersonasi/data?jenis=menu')->assertOk()->assertJsonCount(0, 'data');

        $id = ActivityLog::where('event', 'user.role_changed')->value('id');
        AuditGuard::suspend(fn () => DB::table('activity_logs')->where('id', $id)->update(['reason' => 'dipalsukan']));
        $this->getJson('/audit/peran-impersonasi/data')->assertOk()->assertJsonPath('data.0.valid', false);
    }

    public function test_pengurus_sees_without_ip_and_kasir_is_denied(): void
    {
        $this->actingAs(User::where('email', 'pengurus@koperasi.test')->first());
        $row = $this->getJson('/audit/peran-impersonasi/data')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('ip_address', $row);
        $this->assertArrayNotHasKey('ip_address', $this->getJson('/audit/peran-impersonasi/sesi/'.$this->session->id)->json('data'));
        $id = ActivityLog::where('event', 'user.role_changed')->value('id');
        $this->assertArrayNotHasKey('ip', $this->getJson('/audit/peran-impersonasi/catatan/'.$id)->json('data.actor'));

        $this->actingAs(User::factory()->role('kasir')->create());
        $this->getJson('/audit/peran-impersonasi/data')->assertForbidden();
    }
}
