<?php

namespace Tests\Feature\Auth;

use App\Livewire\Akun\Sessions;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\SessionService;
use Database\Seeders\ProductSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class SessionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $mockRole = null;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ProductSeeder::class, UserSeeder::class]);
        $this->kasir = User::firstWhere('email', 'kasir@koperasi.test');
    }

    private function otherSession(string $id, string $agent = 'Mozilla/5.0 (Linux; Android 14) Chrome/128 Mobile'): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $this->kasir->id, 'ip_address' => '10.0.0.7', 'user_agent' => $agent,
            'payload' => base64_encode('x'), 'last_activity' => now()->subMinutes(5)->timestamp,
        ]);
    }

    public function test_logout_json_and_audit(): void
    {
        $this->actingAs($this->kasir)->postJson('/keluar')->assertOk()->assertJsonPath('message', 'Anda sudah keluar.');
        $this->assertGuest();
        $this->assertSame('Bu Rina keluar', ActivityLog::where('event', 'auth.logout')->value('description'));
    }

    public function test_deactivated_account_is_logged_out_on_next_request(): void
    {
        $this->actingAs($this->kasir)->get('/kasir')->assertOk();

        $this->kasir->update(['is_active' => false]);
        $this->get('/kasir')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->get('/masuk')->assertSee('Akun Anda dinonaktifkan oleh admin.');
        $this->assertSame(1, ActivityLog::where('event', 'auth.forced_logout')->count());
    }

    public function test_idle_session_expires(): void
    {
        config()->set('koperasi.idle_minutes', 30);
        $this->actingAs($this->kasir)->get('/kasir')->assertOk();

        $this->travel(29)->minutes();
        $this->get('/transaksi')->assertOk(); // aktivitas memperpanjang sesi

        $this->travel(31)->minutes();
        $this->get('/transaksi')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(1, ActivityLog::where('event', 'auth.timeout')->count());

        $this->actingAs($this->kasir)->getJson('/laporan/hari-ini'); // sesi baru tanpa jejak waktu → tidak diputus
        $this->assertAuthenticated();
    }

    public function test_list_and_revoke_other_sessions(): void
    {
        $this->otherSession('sesi-lain-ponsel');
        $this->otherSession('sesi-lain-laptop', 'Mozilla/5.0 (Windows NT 10.0) Firefox/130');
        $this->actingAs($this->kasir);

        $this->getJson('/akun/sesi/daftar')
            ->assertOk()
            ->assertJsonFragment(['device' => 'Android', 'browser' => 'Chrome', 'current' => false])
            ->assertJsonFragment(['device' => 'Windows', 'browser' => 'Firefox'])
            ->assertJsonFragment(['current' => true]);

        $this->deleteJson('/akun/sesi/sesi-lain-ponsel')->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'sesi-lain-ponsel']);
        $this->deleteJson('/akun/sesi/tidak-ada')->assertNotFound();

        $this->postJson('/akun/sesi/keluarkan-lain', ['password' => 'salah'])->assertJsonValidationErrors('password');
        $this->postJson('/akun/sesi/keluarkan-lain', ['password' => 'password'])->assertOk()->assertJsonPath('revoked', 1);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->kasir->id)->count());
        $this->assertSame(1, ActivityLog::where('event', 'session.revoked_others')->count());
    }

    public function test_sessions_page(): void
    {
        $this->otherSession('sesi-lain-ponsel');
        $this->actingAs($this->kasir);

        $this->get('/akun/sesi')->assertOk()->assertSee('Perangkat ini')->assertSee('Chrome di Android')->assertSee('Sesi aktif');

        Livewire::test(Sessions::class)
            ->call('revoke', 'sesi-lain-ponsel')->assertSee('Sesi di perangkat itu sudah diakhiri.')
            ->set('password', 'salah')->call('revokeOthers')->assertHasErrors('password')
            ->set('password', 'password')->call('revokeOthers')->assertSee('Tidak ada sesi lain yang aktif.');
    }

    public function test_guest_cannot_use_session_endpoints(): void
    {
        $this->getJson('/akun/sesi/daftar')->assertUnauthorized();
        $this->get('/akun/sesi')->assertRedirect(route('login'));
    }

    public function test_user_agent_description(): void
    {
        $this->assertSame(['device' => 'iPhone/iPad', 'browser' => 'Safari'], SessionService::describe('Mozilla/5.0 (iPhone; CPU iPhone OS 17) Version/17 Safari/604'));
        $this->assertSame(['device' => 'Windows', 'browser' => 'Edge'], SessionService::describe('Mozilla/5.0 (Windows NT 10.0) Chrome/128 Edg/128'));
    }
}
