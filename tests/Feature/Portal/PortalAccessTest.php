<?php

namespace Tests\Feature\Portal;

use App\Livewire\Anggota\PortalAccess;
use App\Livewire\Portal\Login;
use App\Models\ActivityLog;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PortalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_pengurus_enables_access_and_member_can_login(): void
    {
        $m = Member::factory()->create(['member_code' => 'G-2026-001']);
        $this->loginAs('pengurus');
        $c = Livewire::test(PortalAccess::class, ['memberId' => $m->id])->assertSee('Belum aktif')
            ->set('email', 'Guru@Sekolah.Test')->call('enable')->assertHasNoErrors()->assertSee('data-issued', false);
        $issued = $c->get('issued');
        $this->assertMatchesRegularExpression('/^[a-z2-9]{10}$/', $issued['password']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $issued['pin']);
        $this->assertSame('guru@sekolah.test', $m->fresh()->email);
        $this->assertTrue($m->fresh()->hasPortalAccess());
        $this->assertStringNotContainsString($issued['password'], json_encode(ActivityLog::where('event', 'portal.enabled')->first()->toArray()));

        $c->call('dismiss')->assertSet('issued', null)->assertDontSee($issued['password']);

        auth()->logout();
        Livewire::test(Login::class)->set('identifier', 'g-2026-001')->set('password', $issued['password'])->call('login')->assertRedirect('/portal');
    }

    public function test_reset_pin_disable_and_validation(): void
    {
        $m = Member::factory()->create(['password' => 'rahasia123', 'pin' => '246810']);
        Member::factory()->create(['email' => 'pakai@sekolah.test']);
        $c = Livewire::test(PortalAccess::class, ['memberId' => $m->id])->assertSee('Aktif');
        $c->call('resetPin');
        $this->assertNotSame('246810', $c->get('issued.pin'));
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($c->get('issued.pin'), $m->fresh()->getAttributes()['pin']));

        $c->set('editing', true)->set('email', 'pakai@sekolah.test')->call('enable')->assertHasErrors('email');
        $c->call('disable');
        $this->assertFalse($m->fresh()->hasPortalAccess());
        $c->call('resetPin');
        $this->assertSame(2, ActivityLog::whereIn('event', ['portal.pin_reset', 'portal.disabled'])->count()); // reset PIN setelah dicabut ditolak
    }

    public function test_kasir_does_not_see_card(): void
    {
        $m = Member::factory()->create();
        $this->get('/anggota/'.$m->id)->assertSee('data-portal-access', false);
        $this->loginAs('kasir');
        $this->get('/anggota/'.$m->id)->assertDontSee('data-portal-access', false);
        Livewire::test(PortalAccess::class, ['memberId' => $m->id])->call('enable')->assertForbidden();
    }
}
