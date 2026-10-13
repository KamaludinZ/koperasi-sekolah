<?php

namespace Tests\Feature\Auth;

use App\Livewire\Notifikasi\Bell;
use App\Models\ActivityLog;
use App\Services\SettingsService;
use App\Support\SessionPolicy;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SessionHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        config()->set('koperasi.idle_minutes', null);
    }

    public function test_idle_limit_follows_security_setting_and_env_override(): void
    {
        $this->assertSame(60, SessionPolicy::idleMinutes());
        app(SettingsService::class)->save('security', ['idle_minutes' => 15]);
        $this->assertSame(15, SessionPolicy::idleMinutes());
        config()->set('koperasi.idle_minutes', 0);
        $this->assertSame(0, SessionPolicy::idleMinutes());
    }

    public function test_setting_change_ends_idle_sessions_sooner(): void
    {
        app(SettingsService::class)->save('security', ['idle_minutes' => 10]);
        $this->get('/kasir')->assertOk();
        $this->travel(11)->minutes();
        $this->get('/kasir')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertStringContainsString('10 menit', ActivityLog::where('event', 'auth.timeout')->value('description'));
    }

    public function test_livewire_polling_does_not_count_as_activity(): void
    {
        $poll = fn (array $calls, array $updates = []) => Request::create('/livewire/update', 'POST',
            ['components' => [['snapshot' => '{}', 'updates' => $updates, 'calls' => $calls]]], server: ['HTTP_X_LIVEWIRE' => '1']);

        $this->assertTrue(SessionPolicy::isPassive($poll([['method' => '$refresh', 'params' => []]])));
        $this->assertTrue(SessionPolicy::isPassive($poll([])));
        $this->assertFalse(SessionPolicy::isPassive($poll([['method' => 'markAllRead', 'params' => []]])));
        $this->assertFalse(SessionPolicy::isPassive($poll([], ['search' => 'roti'])));
        $this->assertFalse(SessionPolicy::isPassive(Request::create('/kasir')));

        // Sesi dengan polling saja tetap berakhir setelah batas menganggur.
        $this->get('/kasir')->assertOk();
        $start = session('last_activity_at');
        $this->travel(30)->minutes();
        $this->withHeader('X-Livewire', '1')->post('/akun/sesi/aktif', ['components' => [['updates' => [], 'calls' => [['method' => '$refresh']]]]]);
        $this->assertSame($start, session('last_activity_at'));
        $this->travel(31)->minutes();
        $this->get('/kasir')->assertRedirect(route('login'));
    }

    public function test_ping_endpoint_keeps_session_alive(): void
    {
        $this->get('/kasir')->assertOk();
        $this->travel(50)->minutes();
        $this->postJson('/akun/sesi/aktif')->assertNoContent();
        $this->travel(50)->minutes();
        $this->get('/kasir')->assertOk();

        auth()->logout();
        $this->postJson('/akun/sesi/aktif')->assertUnauthorized();
    }

    public function test_layout_renders_idle_warning_with_limit(): void
    {
        app(SettingsService::class)->save('security', ['idle_minutes' => 20]);
        $this->get('/kasir')->assertOk()->assertSee('limit: 20 * 60000', false)->assertSee('Tetap masuk')->assertSee('akun\\/sesi\\/aktif', false);
        config()->set('koperasi.idle_minutes', 0);
        $this->get('/kasir')->assertDontSee('Sesi hampir berakhir');
        $this->get('/akun/sesi')->assertDontSee('menit tanpa aktivitas');
    }

    public function test_cookie_and_remember_hardening(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
        $this->assertSame(60 * 24 * 30, config('auth.guards.web.remember'));

        auth()->logout();
        $res = $this->post('/masuk', ['email' => 'kasir@koperasi.test', 'password' => 'password', 'remember' => '1']);
        $cookie = collect($res->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertLessThanOrEqual(now()->addDays(30)->addMinute()->timestamp, $cookie->getExpiresTime());
    }
}
