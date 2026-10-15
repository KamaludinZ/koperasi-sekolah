<?php

namespace Tests\Feature\Pengguna;

use App\Livewire\Pengguna\Index;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SavedRolesCheckedTest extends TestCase
{
    use RefreshDatabase;

    private User $u;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs('admin');
        $this->u = User::factory()->role('pengurus')->create(['name' => 'Bu Dua Peran']);
        $this->u->roles()->syncWithoutDetaching([Role::named('kasir')->id => ['created_at' => now()]]);
    }

    public function test_both_forms_check_saved_roles_and_mark_them(): void
    {
        Livewire::test(Index::class)->call('editRoles', $this->u->id)
            ->assertSet('roleChoice', ['pengurus', 'kasir'])
            ->assertSeeHtml('data-saved-role="pengurus"')->assertSeeHtml('data-saved-role="kasir"')->assertDontSeeHtml('data-saved-role="admin"');

        Livewire::test(Index::class)->call('edit', $this->u->id)
            ->assertSet('form.roles', ['pengurus', 'kasir'])->assertSet('form.rolesSnapshot', ['pengurus', 'kasir'])
            ->assertSeeHtml('data-saved-role="kasir"');

        Livewire::test(Index::class)->call('create')->assertSet('form.rolesSnapshot', [])->assertDontSeeHtml('data-saved-role=');
    }

    public function test_reopening_after_save_shows_new_saved_state(): void
    {
        Livewire::test(Index::class)->call('editRoles', $this->u->id)->set('roleChoice', ['admin'])->call('saveRoles')->assertHasNoErrors()
            ->call('editRoles', $this->u->id)->assertSet('roleChoice', ['admin'])
            ->assertSeeHtml('data-saved-role="admin"')->assertDontSeeHtml('data-saved-role="kasir"');
        $this->assertSame(['admin'], $this->u->fresh()->roleNames());
    }

    public function test_change_by_another_admin_while_form_open_is_not_overwritten(): void
    {
        $c = Livewire::test(Index::class)->call('editRoles', $this->u->id)->set('roleChoice', ['pengurus']);
        // admin lain menambah peran admin sementara form ini terbuka
        $this->u->roles()->syncWithoutDetaching([Role::named('admin')->id => ['created_at' => now()]]);

        $c->call('saveRoles')->assertHasErrors('roleChoice')->assertSee('baru saja diubah petugas lain')
            ->assertSet('roleChoice', ['admin', 'pengurus', 'kasir'])->assertSet('roleUserId', $this->u->id);
        $this->assertSame(['admin', 'pengurus', 'kasir'], $this->u->fresh()->roleNames()); // tidak tertimpa

        $c->set('roleChoice', ['admin', 'pengurus'])->call('saveRoles')->assertHasNoErrors(); // simpan ulang setelah dimuat
        $this->assertSame(['admin', 'pengurus'], $this->u->fresh()->roleNames());
    }

    public function test_full_account_form_also_detects_concurrent_role_change(): void
    {
        $c = Livewire::test(Index::class)->call('edit', $this->u->id)->set('form.roles', ['kasir']);
        $this->u->roles()->detach(Role::named('kasir')->id);

        $c->call('save')->assertHasErrors('form.roles')->assertSet('form.roles', ['pengurus']);
        $this->assertSame(['pengurus'], $this->u->fresh()->roleNames());
        $c->call('save')->assertHasNoErrors();
    }

    public function test_snapshot_cannot_be_tampered_from_browser(): void
    {
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test(Index::class)->call('editRoles', $this->u->id)->set('roleSnapshot', ['admin']);
    }
}
