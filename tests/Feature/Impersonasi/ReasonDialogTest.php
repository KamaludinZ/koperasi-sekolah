<?php

namespace Tests\Feature\Impersonasi;

use App\Livewire\Pengguna\Impersonation;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Support\ImpersonationPolicy;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReasonDialogTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->actingAs(User::where('email', 'admin@koperasi.test')->first());
        $this->kasir = User::where('email', 'kasir@koperasi.test')->first();
    }

    public function test_dialog_shows_target_details_and_the_menus_that_will_be_visible(): void
    {
        $c = Livewire::test(Impersonation::class)->call('confirm', 'petugas', $this->kasir->id);
        $c->assertSeeHtml('data-impersonate-dialog')
            ->assertSeeText('Petugas · Kasir · '.$this->kasir->email)
            ->assertSeeText('Menu yang akan Anda lihat ('.count(Role::named('kasir')->permissions).')')
            ->assertSeeText('Kasir · Transaksi')
            ->assertSeeText('10 menit')->assertSeeText('30 menit')
            ->assertSet('minutes', ImpersonationPolicy::MAX_MINUTES);
        foreach (ImpersonationPolicy::REASON_PRESETS['petugas'] as $preset) {
            $c->assertSeeText($preset);
        }
    }

    public function test_member_dialog_uses_member_presets_and_portal_preview(): void
    {
        $m = Member::factory()->create(['name' => 'Siti Portal']);
        $m->forceFill(['password' => 'rahasia-portal-1'])->save();

        Livewire::test(Impersonation::class)->set('tab', 'anggota')->call('confirm', 'anggota', $m->id)
            ->assertSeeText('Portal anggota · '.$m->member_code)
            ->assertSeeText('Yang akan Anda lihat di portal')
            ->assertSeeText(ImpersonationPolicy::REASON_PRESETS['anggota'][0])
            ->assertSeeText('tidak akan mengubah PIN/kata sandi anggota')
            ->call('usePreset', 1)->assertSet('reason', ImpersonationPolicy::REASON_PRESETS['anggota'][1]);
    }

    public function test_preset_fills_reason_and_invalid_preset_is_ignored(): void
    {
        Livewire::test(Impersonation::class)->call('confirm', 'petugas', $this->kasir->id)
            ->call('usePreset', 0)->assertSet('reason', ImpersonationPolicy::REASON_PRESETS['petugas'][0])
            ->call('usePreset', 99)->assertSet('reason', ImpersonationPolicy::REASON_PRESETS['petugas'][0]);
        Livewire::test(Impersonation::class)->call('usePreset', 0)->assertSet('reason', ''); // tanpa target
    }

    public function test_duration_must_be_one_of_the_offered_choices(): void
    {
        $c = Livewire::test(Impersonation::class)->call('confirm', 'petugas', $this->kasir->id)
            ->set('reason', 'membantu kasir memeriksa struk')->set('acknowledged', true);
        foreach ([5, 45, 999, 'abc', ''] as $bad) {
            $c->set('minutes', $bad)->call('start')->assertHasErrors('minutes');
        }
        $c->set('minutes', 15)->call('start')->assertHasNoErrors();
    }

    public function test_reason_whitespace_is_collapsed_before_validation(): void
    {
        Livewire::test(Impersonation::class)->call('confirm', 'petugas', $this->kasir->id)
            ->set('acknowledged', true)
            ->set('reason', "   a  \n  b   ")->call('start')->assertHasErrors(['reason' => 'min'])
            ->set('reason', "  membantu   kasir\n\nmemeriksa  ")->call('start')->assertHasNoErrors()
            ->assertSet('reason', 'membantu kasir memeriksa');
    }

    public function test_reopening_dialog_resets_previous_input(): void
    {
        Livewire::test(Impersonation::class)->call('confirm', 'petugas', $this->kasir->id)
            ->set('reason', 'sesuatu yang lama sekali')->set('minutes', 10)->set('acknowledged', true)
            ->call('cancel')
            ->call('confirm', 'petugas', $this->kasir->id)
            ->assertSet('reason', '')->assertSet('minutes', ImpersonationPolicy::MAX_MINUTES)->assertSet('acknowledged', false);
    }
}
