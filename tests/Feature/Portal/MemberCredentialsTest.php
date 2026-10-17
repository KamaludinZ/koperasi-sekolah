<?php

namespace Tests\Feature\Portal;

use App\Livewire\Portal\Account;
use App\Livewire\Portal\Setup;
use App\Models\ActivityLog;
use App\Models\Member;
use App\Models\User;
use App\Services\PortalPinService;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Concerns\FakesImpersonation;
use Tests\TestCase;

class MemberCredentialsTest extends TestCase
{
    use FakesImpersonation, RefreshDatabase;

    private Member $andi;

    protected function setUp(): void
    {
        parent::setUp();
        auth('web')->logout();
        $this->andi = Member::factory()->create(['name' => 'Andi', 'member_code' => 'S-2026-009']);
    }

    /** Anggota yang baru diaktifkan pengurus: kata sandi sementara, belum ada PIN. */
    private function fresh(): Member
    {
        $this->andi->forceFill(['password' => 'sementara9x', 'pin' => null, 'portal_password_changed_at' => null])->save();
        $this->app['auth']->guard('member')->setUser($this->andi);

        return $this->andi;
    }

    public function test_first_login_must_create_own_password_and_pin(): void
    {
        $this->fresh();
        $this->get('/portal')->assertRedirect(route('portal.mulai'));
        $this->get('/portal/belanja')->assertRedirect(route('portal.mulai'));
        $this->getJson('/portal/simpanan/data')->assertStatus(409);
        $this->get('/portal/panduan')->assertOk(); // panduan tetap bisa dibaca
        $this->get('/portal/mulai')->assertOk()->assertSee('Siapkan akun Anda')->assertSee('Kata sandi baru')->assertSee('PIN transaksi')
            ->assertDontSee('data-portal-account-button', false);

        Livewire::test(Setup::class)->set('password', 'sementara9x')->set('password_confirmation', 'sementara9x')
            ->set('pin', '480913')->set('pin_confirmation', '480913')->call('save')
            ->assertHasErrors(['password' => 'Kata sandi baru harus berbeda dari kata sandi sementara dari pengurus.']);
        Livewire::test(Setup::class)->set('password', 'BukuTulis88')->set('password_confirmation', 'BukuTulis88')
            ->set('pin', '111111')->set('pin_confirmation', '111111')->call('save')->assertHasErrors('pin');
        Livewire::test(Setup::class)->set('password', 'abc')->set('pin', '12a456')->call('save')->assertHasErrors(['password' => 'Minimal 8 karakter.', 'pin' => 'PIN harus 6 angka.']);
        Livewire::test(Setup::class)->set('password', 'BukuTulis88')->set('password_confirmation', 'BukuTulis88')
            ->set('pin', '123456')->set('pin_confirmation', '123456')->call('save')->assertHasErrors('pin'); // berurutan
        Livewire::test(Setup::class)->set('password', 'BukuTulis88')->set('password_confirmation', 'BukuTulis88')
            ->set('pin', '480913')->set('pin_confirmation', '480913')->call('save')->assertHasNoErrors()->assertRedirect(route('portal'));

        $m = $this->andi->fresh();
        $this->assertTrue(Hash::check('BukuTulis88', $m->getAttributes()['password']));
        $this->assertTrue(Hash::check('480913', $m->getAttributes()['pin']));
        $this->assertNotNull($m->portal_password_changed_at);
        $this->assertFalse($m->needsPortalSetup());
        $log = ActivityLog::where('event', 'portal.setup_completed')->sole();
        $this->assertStringNotContainsString('480913', json_encode($log->toArray()));
        $this->assertStringNotContainsString('BukuTulis88', json_encode($log->toArray()));

        $this->app['auth']->guard('member')->setUser($m);
        $this->get('/portal')->assertOk();
        $this->get('/portal/mulai')->assertRedirect(route('portal'));
    }

    public function test_pin_reset_by_pengurus_asks_only_for_new_pin(): void
    {
        $this->andi->forceFill(['password' => 'BukuTulis88', 'pin' => null])->save();
        $this->actingAsMemberWithoutPin();
        $this->get('/portal/mulai')->assertOk()->assertSee('PIN transaksi')->assertDontSee('Kata sandi baru');
        Livewire::test(Setup::class)->set('pin', '480913')->set('pin_confirmation', '480913')->call('save')->assertHasNoErrors();
        $this->assertTrue(Hash::check('480913', $this->andi->fresh()->getAttributes()['pin']));
        $this->assertTrue(Hash::check('BukuTulis88', $this->andi->fresh()->getAttributes()['password']));
    }

    public function test_member_changes_password_and_other_devices_are_signed_out(): void
    {
        $this->andi->forceFill(['password' => 'BukuTulis88', 'pin' => '480913'])->save();
        $this->actingAsMember($this->andi);
        $this->get('/portal/akun')->assertOk()->assertSee('Ganti kata sandi')->assertSee('S-2026-009');

        Livewire::test(Account::class)->set('current_password', 'salah')->set('password', 'PensilBaru77')->set('password_confirmation', 'PensilBaru77')
            ->call('changePassword')->assertHasErrors('current_password')->assertSet('current_password', '');
        Livewire::test(Account::class)->set('current_password', 'BukuTulis88')->set('password', 'BukuTulis88')->set('password_confirmation', 'BukuTulis88')
            ->call('changePassword')->assertHasErrors(['password' => 'Kata sandi baru harus berbeda dari yang sekarang.']);
        Livewire::test(Account::class)->set('current_password', 'BukuTulis88')->set('password', 'PensilBaru77')->set('password_confirmation', 'PensilBaru77')
            ->call('changePassword')->assertHasNoErrors()->assertSet('saved', 'password')->assertDispatched('toast', type: 'success');
        $this->assertTrue(Hash::check('PensilBaru77', $this->andi->fresh()->getAttributes()['password']));
        $this->assertTrue(ActivityLog::where('event', 'portal.password_changed')->exists());
        $this->get('/portal')->assertOk(); // perangkat ini tetap masuk

        // Perangkat lain: sesinya masih menyimpan sidik kata sandi lama → dikeluarkan.
        $this->andi->forceFill(['password' => 'Diganti99'])->save();
        $this->get('/portal')->assertRedirect(route('portal.login'));
        $this->assertFalse(auth('member')->check());
    }

    public function test_member_changes_pin_with_password_and_lockout(): void
    {
        $this->andi->forceFill(['password' => 'BukuTulis88', 'pin' => '480913'])->save();
        $this->actingAsMember($this->andi);
        RateLimiter::hit('portal-pin:'.$this->andi->id, PortalPinService::DECAY_SECONDS);

        Livewire::test(Account::class)->set('pin', '480913')->set('pin_confirmation', '480913')->set('pin_password', 'BukuTulis88')
            ->call('changePin')->assertHasErrors('pin'); // sama dengan PIN lama
        Livewire::test(Account::class)->set('pin', '592047')->set('pin_confirmation', '592047')->set('pin_password', 'BukuTulis88')
            ->call('changePin')->assertHasNoErrors()->assertSet('saved', 'pin');
        $this->assertTrue(Hash::check('592047', $this->andi->fresh()->getAttributes()['pin']));
        $this->assertSame(3, app(PortalPinService::class)->attempts($this->andi->fresh())['left']); // kunci PIN lama dilepas

        $c = Livewire::test(Account::class);
        for ($i = 0; $i < 5; $i++) {
            $c->set('pin', '602938')->set('pin_confirmation', '602938')->set('pin_password', 'salah'.$i)->call('changePin');
        }
        $c->assertSee('dikunci, coba lagi dalam');
        $c->set('pin', '602938')->set('pin_confirmation', '602938')->set('pin_password', 'BukuTulis88')->call('changePin')
            ->assertHasErrors('pin_password');
        $this->assertTrue(Hash::check('592047', $this->andi->fresh()->getAttributes()['pin']));
        $this->assertSame(5, ActivityLog::where('event', 'portal.credential_change_failed')->count());
    }

    public function test_impersonating_admin_cannot_change_credentials_and_skips_setup(): void
    {
        $this->seed(UserSeeder::class);
        $this->fresh();
        $admin = User::where('email', 'admin@koperasi.test')->first();
        $this->actingAs($admin, 'web');
        session($this->impersonationRecord([
            'id' => 'aaaaaaaa-1111-2222-3333-444444444445', 'admin_id' => $admin->id, 'admin_name' => $admin->name,
            'target_type' => 'anggota', 'target_id' => $this->andi->id, 'target_name' => 'Andi', 'target_label' => $this->andi->member_code,
            'reason' => 'Membantu anggota yang tidak bisa memakai portal', 'started_at' => now()->timestamp, 'expires_at' => now()->addMinutes(30)->timestamp,
        ]));
        $this->get('/portal')->assertOk(); // tidak dipaksa ke Siapkan akun
        $this->get('/portal/akun')->assertOk()->assertSee('data-read-only', false);
        Livewire::test(Account::class)->set('current_password', 'sementara9x')->set('password', 'PensilBaru77')->set('password_confirmation', 'PensilBaru77')
            ->call('changePassword')->assertDispatched('toast', type: 'error');
        Livewire::test(Setup::class)->set('password', 'PensilBaru77')->set('password_confirmation', 'PensilBaru77')
            ->set('pin', '480913')->set('pin_confirmation', '480913')->call('save')->assertDispatched('toast', type: 'error');
        $this->assertTrue(Hash::check('sementara9x', $this->andi->fresh()->getAttributes()['password']));
        $this->assertFalse($this->andi->fresh()->hasPin());
    }

    private function actingAsMemberWithoutPin(): void
    {
        $this->app['auth']->guard('member')->setUser($this->andi->fresh());
    }
}
