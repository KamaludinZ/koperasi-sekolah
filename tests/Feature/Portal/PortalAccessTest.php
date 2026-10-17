<?php

namespace Tests\Feature\Portal;

use App\Livewire\Anggota\PortalAccess;
use App\Livewire\Portal\Login;
use App\Models\ActivityLog;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class PortalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_pengurus_enables_access_with_temporary_password_only(): void
    {
        $m = Member::factory()->create(['member_code' => 'G-2026-001']);
        $this->loginAs('pengurus');
        $c = Livewire::test(PortalAccess::class, ['memberId' => $m->id])->assertSee('Belum aktif')
            ->set('email', 'Guru@Sekolah.Test')->call('enable')->assertHasNoErrors()->assertSee('data-issued', false)
            ->assertSee('Kata sandi sementara')->assertDontSee('data-issued-pin', false);
        $issued = $c->get('issued');
        $this->assertMatchesRegularExpression('/^[a-z2-9]{10}$/', $issued['password']);
        $this->assertArrayNotHasKey('pin', $issued);
        $m->refresh();
        $this->assertSame('guru@sekolah.test', $m->email);
        $this->assertTrue($m->hasPortalAccess());
        $this->assertFalse($m->hasPin()); // PIN dibuat anggota sendiri
        $this->assertNull($m->portal_password_changed_at);
        $this->assertTrue($m->needsPortalSetup());
        $this->assertStringNotContainsString($issued['password'], json_encode(ActivityLog::where('event', 'portal.enabled')->first()->toArray()));

        $c->call('dismiss')->assertSet('issued', null)->assertDontSee($issued['password'])
            ->assertSee('sementara (diganti anggota saat masuk)')->assertSee('belum dibuat (dibuat anggota saat masuk)');

        auth()->logout();
        Livewire::test(Login::class)->set('identifier', 'g-2026-001')->set('password', $issued['password'])->call('login')->assertRedirect('/portal');
        $this->get('/portal')->assertRedirect(route('portal.mulai'));
    }

    public function test_reset_password_keeps_pin_and_reset_pin_clears_it(): void
    {
        $m = Member::factory()->create(['password' => 'rahasia123', 'pin' => '246810']);
        $c = Livewire::test(PortalAccess::class, ['memberId' => $m->id])->assertSee('Aktif')->assertSee('dibuat anggota');

        $c->call('enable')->assertSee('data-issued-password', false);
        $m->refresh();
        $this->assertTrue(Hash::check($c->get('issued.password'), $m->getAttributes()['password']));
        $this->assertTrue(Hash::check('246810', $m->getAttributes()['pin'])); // PIN anggota tetap
        $this->assertNull($m->portal_password_changed_at);
        $this->assertTrue(ActivityLog::where('event', 'portal.password_reset')->exists());

        $c->call('dismiss')->call('resetPin')->assertDispatched('toast', type: 'success')->assertSet('issued', null);
        $this->assertFalse($m->fresh()->hasPin());
        $this->assertTrue(ActivityLog::where('event', 'portal.pin_reset')->exists());
    }

    public function test_change_email_and_disable(): void
    {
        $m = Member::factory()->create(['password' => 'rahasia123', 'pin' => '246810', 'email' => 'lama@sekolah.test']);
        Member::factory()->create(['email' => 'pakai@sekolah.test']);
        $c = Livewire::test(PortalAccess::class, ['memberId' => $m->id])->set('editing', true)
            ->set('email', 'pakai@sekolah.test')->call('saveEmail')->assertHasErrors('email');
        $c->set('email', ' Baru@Sekolah.Test ')->call('saveEmail')->assertHasNoErrors()->assertSet('editing', false);
        $this->assertSame('baru@sekolah.test', $m->fresh()->email);
        $log = ActivityLog::where('event', 'portal.email_changed')->sole();
        $this->assertSame(['email' => 'lama@sekolah.test'], $log->old_values);
        $c->set('editing', true)->set('email', '')->call('saveEmail');
        $this->assertNull($m->fresh()->email);

        $c->call('disable');
        $this->assertFalse($m->fresh()->hasPortalAccess());
        $c->call('resetPin')->assertDispatched('toast', type: 'error'); // setelah dicabut ditolak
    }

    public function test_kasir_does_not_see_card(): void
    {
        $m = Member::factory()->create();
        $this->get('/anggota/'.$m->id)->assertSee('data-portal-access', false);
        $this->loginAs('kasir');
        $this->get('/anggota/'.$m->id)->assertDontSee('data-portal-access', false);
        Livewire::test(PortalAccess::class, ['memberId' => $m->id])->call('enable')->assertForbidden();
        Livewire::test(PortalAccess::class, ['memberId' => $m->id])->call('saveEmail')->assertForbidden();
    }
}
