<?php

namespace Tests\Feature\Portal;

use App\Livewire\Portal\Login;
use App\Models\ActivityLog;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $loginAs = null;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = Member::factory()->create(['member_code' => 'S-2026-001', 'name' => 'Andi', 'email' => 'andi@siswa.test', 'password' => '246810', 'pin' => '135792']);
    }

    public function test_guest_is_sent_to_portal_login(): void
    {
        $this->get('/portal')->assertRedirect('/portal/masuk');
        $this->get('/portal/masuk')->assertOk()->assertSee('Masuk portal anggota')->assertSee('noindex', false);
    }

    public function test_login_with_code_or_email(): void
    {
        Livewire::test(Login::class)->set('identifier', 's-2026-001')->set('password', '246810')->call('login')->assertRedirect('/portal');
        $this->assertTrue(Auth::guard('member')->check());
        $this->assertFalse(Auth::guard('web')->check()); // bukan sesi petugas
        $this->assertNotNull($this->member->fresh()->portal_last_login_at);
        $this->assertSame('anggota', ActivityLog::where('event', 'portal.login')->value('actor_role'));

        $this->get('/portal')->assertOk()->assertSee('Andi')->assertSee('S-2026-001');
        $this->get('/portal/masuk')->assertRedirect('/portal');
        $this->get('/kasir')->assertRedirect('/masuk'); // anggota tidak bisa membuka layar petugas

        $this->post('/portal/keluar')->assertRedirect('/portal/masuk');
        $this->assertFalse(Auth::guard('member')->check());

        // Tujuan login petugas (/kasir) tidak dipakai portal.
        Livewire::test(Login::class)->set('identifier', 'ANDI@siswa.test')->set('password', '246810')->call('login')->assertRedirect('/portal');
        $this->assertSame(url('/kasir'), session('url.intended'));
    }

    public function test_redirects_back_to_intended_portal_page(): void
    {
        session(['url.intended' => url('/portal/simpanan')]);
        Livewire::test(Login::class)->set('identifier', 'S-2026-001')->set('password', '246810')->call('login')->assertRedirect('/portal/simpanan');
    }

    public function test_failures_are_generic_and_rate_limited(): void
    {
        $generic = 'Kode/email atau kata sandi salah, atau akses portal belum aktif.';
        Livewire::test(Login::class)->set('identifier', 'S-2026-001')->set('password', 'salah')->call('login')
            ->assertHasErrors(['identifier' => $generic])->assertSet('password', '');
        Livewire::test(Login::class)->set('identifier', 'tidak-ada')->set('password', 'x')->call('login')->assertHasErrors(['identifier' => $generic]);

        $noPortal = Member::factory()->create(['member_code' => 'G-01']);
        Livewire::test(Login::class)->set('identifier', 'G-01')->set('password', '')->call('login')->assertHasErrors('password');
        $this->member->update(['is_active' => false]);
        Livewire::test(Login::class)->set('identifier', 'S-2026-001')->set('password', '246810')->call('login')->assertHasErrors(['identifier' => $generic]);
        $this->member->update(['is_active' => true]);

        foreach (range(1, 4) as $i) {
            Livewire::test(Login::class)->set('identifier', 'S-2026-001')->set('password', 'salah')->call('login');
        }
        Livewire::test(Login::class)->set('identifier', 'S-2026-001')->set('password', '246810')->call('login')
            ->assertHasErrors('identifier')->assertSee('Terlalu banyak percobaan');
        $this->assertFalse(Auth::guard('member')->check());
        $this->assertGreaterThan(0, ActivityLog::where('event', 'portal.failed')->count());
        $this->assertNull($noPortal->fresh()->portal_last_login_at);
    }

    public function test_password_is_hashed_and_hidden(): void
    {
        $this->assertNotSame('246810', $this->member->getAttributes()['password']);
        $this->assertArrayNotHasKey('password', $this->member->toArray());
    }

    public function test_portal_does_not_switch_default_guard_and_kicks_deactivated_member(): void
    {
        $this->actingAsMember($this->member);
        $this->get('/portal')->assertOk();
        $this->assertNull(auth()->user()); // guard bawaan tetap milik petugas

        $this->member->update(['is_active' => false]);
        $this->get('/portal')->assertRedirect('/portal/masuk');
        $this->assertFalse(Auth::guard('member')->check());
    }
}
