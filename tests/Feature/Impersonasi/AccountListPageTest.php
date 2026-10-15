<?php

namespace Tests\Feature\Impersonasi;

use App\Livewire\Pengguna\Impersonation;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Support\ImpersonationPolicy;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class AccountListPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->actingAs($this->admin);
    }

    private function portalMember(array $attrs = []): Member
    {
        $m = Member::factory()->create($attrs);
        $m->forceFill(['password' => 'rahasia-portal-1'])->save();

        return $m->fresh();
    }

    public function test_page_lists_staff_with_eligibility_reasons(): void
    {
        $kasir = User::where('email', 'kasir@koperasi.test')->first();
        $otherAdmin = User::factory()->admin()->create(['name' => 'Admin Kedua']);
        $inactive = User::where('email', 'nonaktif@koperasi.test')->first();

        $this->get('/pengguna/impersonasi')->assertOk()
            ->assertSeeText('Masuk sebagai akun')
            ->assertSeeText('Sesi dibatasi '.ImpersonationPolicy::MAX_MINUTES.' menit')
            ->assertSeeHtmlInOrder(['data-account="petugas-'.$kasir->id.'"', 'Masuk sebagai'])
            ->assertSeeHtmlInOrder(['data-account="petugas-'.$otherAdmin->id.'"', 'Akun Admin tidak bisa dimasuki.'])
            ->assertSeeHtmlInOrder(['data-account="petugas-'.$this->admin->id.'"', 'Ini akun Anda sendiri.'])
            ->assertSeeHtmlInOrder(['data-account="petugas-'.$inactive->id.'"', 'Akun nonaktif.']);
    }

    public function test_multi_role_target_holding_admin_is_not_eligible(): void
    {
        $u = User::factory()->role('kasir')->create();
        $u->roles()->attach(Role::named('admin')->id);
        $this->assertSame('Akun Admin tidak bisa dimasuki.', ImpersonationPolicy::staffDenial($u->fresh(), $this->admin));
        $this->assertNull(ImpersonationPolicy::staffDenial(User::where('email', 'pengurus@koperasi.test')->first(), $this->admin));
    }

    public function test_member_tab_lists_only_portal_accounts(): void
    {
        $withPortal = $this->portalMember(['name' => 'Siti Portal']);
        $noPortal = Member::factory()->create(['name' => 'Budi Tanpa Portal']);
        $inactive = $this->portalMember(['name' => 'Rina Nonaktif', 'is_active' => false]);

        Livewire::test(Impersonation::class)->set('tab', 'anggota')
            ->assertSeeText('Siti Portal')
            ->assertDontSeeText('Budi Tanpa Portal')
            ->assertSeeHtmlInOrder(['data-account="anggota-'.$inactive->id.'"', 'Anggota nonaktif.'])
            ->set('search', $withPortal->member_code)
            ->assertSeeText('Siti Portal')->assertDontSeeText('Rina Nonaktif');
        $this->assertSame('Akun portal belum dibuat.', ImpersonationPolicy::memberDenial($noPortal));
    }

    public function test_confirm_requires_reason_and_acknowledgement(): void
    {
        $kasir = User::where('email', 'kasir@koperasi.test')->first();
        Livewire::test(Impersonation::class)
            ->call('confirm', 'petugas', $kasir->id)
            ->assertSet('target', ['type' => 'petugas', 'id' => $kasir->id])
            ->assertSeeText('Masuk sebagai '.$kasir->name.'?')
            ->call('start')->assertHasErrors(['reason' => 'required', 'acknowledged' => 'accepted'])
            ->set('reason', 'cek')->call('start')->assertHasErrors(['reason' => 'min'])
            ->set('reason', str_repeat('x', 201))->call('start')->assertHasErrors(['reason' => 'max'])
            ->call('cancel')->assertSet('target', null);
    }

    public function test_ineligible_targets_cannot_be_confirmed_even_by_crafted_calls(): void
    {
        $otherAdmin = User::factory()->admin()->create();
        Livewire::test(Impersonation::class)
            ->call('confirm', 'petugas', $otherAdmin->id)->assertSet('target', null)->assertDispatched('toast', type: 'error', message: 'Akun Admin tidak bisa dimasuki.')
            ->call('confirm', 'petugas', $this->admin->id)->assertSet('target', null)
            ->call('confirm', 'petugas', 999999)->assertSet('target', null)->assertDispatched('toast', type: 'error', message: 'Akun tidak ditemukan.')
            ->call('confirm', 'bukan-jenis', 1)->assertSet('target', null);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(Impersonation::class)->set('target', ['type' => 'petugas', 'id' => $otherAdmin->id]);
    }

    public function test_target_that_becomes_ineligible_before_start_is_rejected(): void
    {
        $kasir = User::where('email', 'kasir@koperasi.test')->first();
        $c = Livewire::test(Impersonation::class)->call('confirm', 'petugas', $kasir->id);
        $kasir->forceFill(['is_active' => false])->save();
        $c->set('reason', 'membantu kasir memeriksa struk')->set('acknowledged', true)->call('start')
            ->assertSet('target', null)->assertDispatched('toast', type: 'error', message: 'Akun nonaktif.');
    }

    public function test_only_admins_can_open_the_page(): void
    {
        foreach (['kasir@koperasi.test', 'pengurus@koperasi.test'] as $email) {
            $this->actingAs(User::where('email', $email)->first());
            $this->get('/pengguna/impersonasi')->assertForbidden();
        }
        auth()->logout();
        $this->get('/pengguna/impersonasi')->assertRedirect(route('login'));
    }

    public function test_url_inputs_are_normalized(): void
    {
        $this->get('/pengguna/impersonasi?jenis=zzz&q[]=x')->assertOk();
        Livewire::withQueryParams(['jenis' => 'anggota'])->test(Impersonation::class)->assertSet('tab', 'anggota');
        Livewire::test(Impersonation::class)->set('tab', 'ngawur')->assertSet('tab', 'petugas');
    }
}
