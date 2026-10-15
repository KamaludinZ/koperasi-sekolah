<?php

namespace Tests\Feature\Impersonasi;

use App\Livewire\Pengguna\Impersonation;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Support\ImpersonationPolicy;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Concerns\FakesImpersonation;
use Tests\TestCase;

class AdminOnlyButtonsTest extends TestCase
{
    use FakesImpersonation, RefreshDatabase;

    private User $admin;

    private User $kasir;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->kasir = User::where('email', 'kasir@koperasi.test')->first();
        $this->member = Member::factory()->create(['name' => 'Siti Portal']);
        $this->member->forceFill(['password' => 'rahasia-portal-1'])->save();
    }

    private function memberImpersonation(): array
    {
        return $this->impersonationRecord([
            'id' => 'aaaaaaaa-0000-0000-0000-00000000000a', 'admin_id' => $this->admin->id, 'admin_name' => $this->admin->name, 'target_type' => 'anggota',
            'target_id' => $this->member->id, 'target_name' => 'Siti Portal', 'reason' => 'membantu anggota portal',
            'started_at' => now()->timestamp, 'expires_at' => now()->addMinutes(30)->timestamp,
        ]);
    }

    public function test_gate_allows_only_active_admins_including_multi_role_admins(): void
    {
        $this->assertTrue(Gate::forUser($this->admin)->allows('impersonasi'));
        $this->assertFalse(Gate::forUser($this->kasir)->allows('impersonasi'));
        $this->assertFalse(Gate::forUser(User::where('email', 'pengurus@koperasi.test')->first())->allows('impersonasi'));

        $multi = User::factory()->role('kasir')->create();
        $multi->roles()->attach(Role::named('admin')->id);
        $this->assertTrue(Gate::forUser($multi->fresh())->allows('impersonasi'));

        $this->admin->forceFill(['is_active' => false])->save();
        $this->assertFalse(Gate::forUser($this->admin->fresh())->allows('impersonasi'));
    }

    public function test_admin_sees_entry_points_others_do_not(): void
    {
        $this->actingAs($this->admin);
        $this->get('/pengguna')->assertOk()
            ->assertSeeHtml('data-impersonate-link="'.$this->kasir->id.'"')
            ->assertDontSeeHtml('data-impersonate-link="'.$this->admin->id.'"'); // bukan diri sendiri
        $this->get('/anggota/'.$this->member->id)->assertOk()->assertSeeHtml('data-impersonate-member');

        $this->actingAs(User::where('email', 'pengurus@koperasi.test')->first());
        $this->get('/anggota/'.$this->member->id)->assertOk()->assertDontSeeHtml('data-impersonate-member');
        $this->get('/pengguna/impersonasi')->assertForbidden();
    }

    public function test_member_without_portal_has_no_entry_point(): void
    {
        $noPortal = Member::factory()->create();
        $this->actingAs($this->admin);
        $this->get('/anggota/'.$noPortal->id)->assertOk()->assertDontSeeHtml('data-impersonate-member');
    }

    public function test_deep_link_opens_dialog_for_eligible_account_only(): void
    {
        $this->actingAs($this->admin);
        Livewire::withQueryParams(['buka' => 'petugas-'.$this->kasir->id])->test(Impersonation::class)
            ->assertSet('target', ['type' => 'petugas', 'id' => $this->kasir->id])->assertSet('open', '');
        Livewire::withQueryParams(['buka' => 'anggota-'.$this->member->id])->test(Impersonation::class)
            ->assertSet('tab', 'anggota')->assertSet('target', ['type' => 'anggota', 'id' => $this->member->id]);

        $otherAdmin = User::factory()->admin()->create();
        Livewire::withQueryParams(['buka' => 'petugas-'.$otherAdmin->id])->test(Impersonation::class)->assertSet('target', null);
        Livewire::withQueryParams(['buka' => 'petugas-abc'])->test(Impersonation::class)->assertSet('target', null);
        $this->get('/pengguna/impersonasi?buka[]=x')->assertOk();
    }

    public function test_no_nested_impersonation_while_already_impersonating(): void
    {
        // guard anggota dulu, lalu admin: guard bawaan tetap `web` seperti permintaan nyata
        $this->actingAs($this->member->fresh(), 'member')->actingAs($this->admin, 'web')->withSession($this->memberImpersonation());
        session($this->memberImpersonation());
        $this->assertFalse(Gate::forUser($this->admin)->allows('impersonasi'));
        $this->assertStringContainsString('Selesaikan dulu', ImpersonationPolicy::actorDenial($this->admin));

        $this->get('/pengguna/impersonasi')->assertOk()->assertSeeHtml('data-actor-denied')->assertDontSeeHtml('wire:click="confirm(');
        $this->get('/pengguna')->assertOk()->assertDontSeeHtml('data-impersonate-link=');
        Livewire::test(Impersonation::class)->call('confirm', 'petugas', $this->kasir->id)->assertSet('target', null)
            ->assertDispatched('toast', type: 'error');
    }
}
