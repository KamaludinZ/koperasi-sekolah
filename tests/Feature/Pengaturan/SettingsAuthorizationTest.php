<?php

namespace Tests\Feature\Pengaturan;

use App\Livewire\Pengaturan\Profile;
use App\Livewire\Pengaturan\Receipt;
use App\Livewire\Pengaturan\Tax;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** Admin membagikan menu Pengaturan ke Pengurus (boleh melihat, tidak boleh mengubah). */
    private function shareMenuWithPengurus(): void
    {
        $role = Role::named('pengurus');
        $role->update(['permissions' => [...$role->permissions, 'pengaturan']]);
        $this->loginAs('pengurus');
    }

    public function test_only_admin_passes_the_edit_gate(): void
    {
        $this->assertTrue(Gate::allows('ubah-pengaturan'));

        $this->shareMenuWithPengurus();
        $this->assertTrue(Gate::allows('menu', 'pengaturan'));
        $this->assertFalse(Gate::allows('ubah-pengaturan'));

        $this->loginAs('kasir');
        $this->assertFalse(Gate::allows('ubah-pengaturan'));
    }

    public function test_shared_menu_can_read_but_not_write_through_api(): void
    {
        $this->shareMenuWithPengurus();

        $this->getJson('/pengaturan/data')->assertOk();
        $this->putJson('/pengaturan/data/tax', ['enabled' => true, 'rate' => 11])->assertForbidden();
        $this->postJson('/pengaturan/data/receipt/reset')->assertForbidden();
        $this->deleteJson('/pengaturan/data/profile/logo')->assertForbidden();

        $this->assertSame(0, Setting::count());
    }

    public function test_livewire_forms_are_read_only_for_non_admin(): void
    {
        $this->shareMenuWithPengurus();

        $this->get('/pengaturan/profil')->assertOk()->assertSee('Mode baca saja');

        Livewire::test(Profile::class)->assertSet('canEdit', false)
            ->set('name', 'Diubah Pengurus')->call('save')
            ->assertSet('saved', false)
            ->assertDispatched('toast', type: 'error', message: 'Hanya Admin yang dapat mengubah pengaturan koperasi.');
        Livewire::test(Receipt::class)->set('paper', '80mm')->call('save')->assertSet('saved', false);
        Livewire::test(Tax::class)->set('enabled', true)->set('rate', '11')->call('save')->assertSet('saved', false);

        $this->assertSame(0, Setting::count());
        $this->assertSame(SettingsService::DEFAULTS['profile']['name'], app(SettingsService::class)->profile()['name']);
        $this->assertSame(3, ActivityLog::where('event', 'setting.denied')->count());
        $this->assertSame('Pak Dedi', ActivityLog::where('event', 'setting.denied')->first()->actor_name);
    }

    public function test_admin_sees_editable_form(): void
    {
        $this->get('/pengaturan/profil')->assertOk()->assertDontSee('Mode baca saja');
        Livewire::test(Tax::class)->assertSet('canEdit', true)->set('enabled', true)->set('rate', '11')->call('save')->assertSet('saved', true);
    }

    public function test_inactive_admin_cannot_edit(): void
    {
        $admin = \App\Models\User::where('email', 'admin@koperasi.test')->first();
        $admin->update(['is_active' => false]);

        $this->assertFalse(Gate::forUser($admin->fresh())->allows('ubah-pengaturan'));
    }

    public function test_admin_role_must_keep_settings_menu(): void
    {
        $this->expectException(ValidationException::class);
        app(UserService::class)->updatePermissions(Role::named('admin'), ['kasir', 'pengguna']);
    }
}
