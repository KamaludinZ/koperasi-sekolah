<?php

namespace Tests\Feature\Auth;

use App\Livewire\Auth\Login;
use App\Models\ActivityLog;
use App\Models\LoginLockout;
use App\Models\Notification;
use App\Services\LoginLockoutService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoginLockoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        auth()->logout();
        $this->travelTo('2026-10-14 08:00:00');
    }

    private function failLogin(int $times, string $email = 'kasir@koperasi.test'): void
    {
        foreach (range(1, $times) as $i) {
            $this->postJson('/masuk', ['email' => $email, 'password' => 'salah']);
        }
    }

    public function test_tiers_escalate_from_one_minute_to_fifteen_to_manual(): void
    {
        $this->failLogin(4);
        $this->assertSame(1, app(LoginLockoutService::class)->status('kasir@koperasi.test')['attempts_left']);

        $this->postJson('/masuk', ['email' => 'kasir@koperasi.test', 'password' => 'salah'])
            ->assertStatus(429)->assertJsonPath('errors.email.0', 'Terlalu banyak percobaan. Coba lagi dalam 60 detik.');
        $row = LoginLockout::sole();
        $this->assertSame([1, 5], [$row->level, $row->failed_count]);

        // Selama terkunci, kata sandi benar pun ditolak & tidak menambah hitungan.
        $this->postJson('/masuk', ['email' => 'kasir@koperasi.test', 'password' => 'password'])->assertStatus(429);
        $this->assertSame(5, $row->fresh()->failed_count);
        $this->assertGuest();

        $this->travel(61)->seconds();
        $this->failLogin(5);
        $row->refresh();
        $this->assertSame(2, $row->level);
        $this->assertSame(15 * 60, app(LoginLockoutService::class)->status('kasir@koperasi.test')['seconds']);

        $this->travel(16)->minutes();
        $this->failLogin(5);
        $status = app(LoginLockoutService::class)->status('KASIR@koperasi.test ');
        $this->assertTrue($status['locked'] && $status['manual']);
        $this->assertNull($status['seconds']);
        $this->postJson('/masuk', ['email' => 'kasir@koperasi.test', 'password' => 'password'])
            ->assertStatus(429)->assertJsonPath('errors.email.0', 'Akun dikunci karena terlalu banyak percobaan masuk. Hubungi admin koperasi.');

        // Kunci manual tidak terbuka sendiri.
        $this->travel(3)->days();
        $this->assertTrue(app(LoginLockoutService::class)->status('kasir@koperasi.test')['manual']);

        $this->assertSame(3, ActivityLog::where('event', 'auth.lockout')->count());
        $n = Notification::where('target', 'admin')->where('type', 'sistem')->sole();
        $this->assertStringContainsString('Bu Rina', $n->title);
    }

    public function test_success_and_unlock_clear_counts_and_stale_counts_reset(): void
    {
        $this->failLogin(3);
        $this->postJson('/masuk', ['email' => 'kasir@koperasi.test', 'password' => 'password'])->assertOk();
        $this->assertSame(0, LoginLockout::count());

        auth()->logout();
        $this->failLogin(3);
        $this->travel(LoginLockoutService::RESET_AFTER_HOURS + 1)->hours();
        $this->assertNull(app(LoginLockoutService::class)->status('kasir@koperasi.test')['attempts_left']);
        $this->assertSame(0, LoginLockout::count());

        foreach ([5, 5, 5] as $batch) {
            $this->failLogin($batch);
            $this->travel(16)->minutes();
        }
        $row = LoginLockout::sole();
        $this->assertTrue($row->isManual());
        app(LoginLockoutService::class)->unlock($row, 'Sudah dikonfirmasi lewat telepon');
        $this->assertSame(0, LoginLockout::count());
        $log = ActivityLog::where('event', 'auth.unlocked')->sole();
        $this->assertSame('Sudah dikonfirmasi lewat telepon', $log->reason);
        $this->postJson('/masuk', ['email' => 'kasir@koperasi.test', 'password' => 'password'])->assertOk();
    }

    public function test_threshold_follows_security_setting_and_unknown_emails_are_tracked(): void
    {
        app(SettingsService::class)->save('security', ['max_attempts' => 3]);
        $this->assertSame([3, 6, 9], array_column(LoginLockoutService::tiers(), 'failed'));

        $this->failLogin(3, 'bendahara@koperasi.test');
        $row = LoginLockout::sole();
        $this->assertSame(['bendahara@koperasi.test', null, 1], [$row->email, $row->user_id, $row->level]);
        $this->assertSame(['127.0.0.1'], $row->ip_addresses);
    }

    public function test_login_screen_shows_manual_lock_panel(): void
    {
        LoginLockout::create(['email' => 'kasir@koperasi.test', 'failed_count' => 15, 'level' => 3, 'locked_at' => now()]);
        Livewire::test(Login::class)->set('email', 'kasir@koperasi.test')->set('password', 'password')->call('login')
            ->assertSet('lock', ['seconds' => null, 'manual' => true])
            ->assertSee('hanya bisa dibuka oleh admin koperasi');
        $this->assertGuest();
    }
}
