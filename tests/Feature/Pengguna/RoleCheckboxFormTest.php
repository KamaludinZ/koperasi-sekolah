<?php

namespace Tests\Feature\Pengguna;

use App\Livewire\Pengguna\Index;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class RoleCheckboxFormTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs('admin');
        $this->kasir = User::factory()->role('kasir')->create(['name' => 'Bu Siti', 'password' => 'lama12345']);
    }

    public function test_form_shows_checkboxes_preselected_with_diff_preview(): void
    {
        Livewire::test(Index::class)->assertSeeHtml('data-edit-roles="'.$this->kasir->id.'"')
            ->call('editRoles', $this->kasir->id)->assertSeeHtml('data-role-form')->assertSet('roleChoice', ['kasir'])
            ->assertSeeHtml('data-role-choice="admin"')->assertSeeHtml('data-role-diff-none')
            ->set('roleChoice', ['kasir', 'pengurus'])->assertSeeHtml('data-role-diff')->assertSeeText('+ Pengurus')->assertSeeText('Menu bertambah:')
            ->set('roleChoice', ['pengurus'])->assertSeeText('− Kasir')
            ->set('roleChoice', [])->assertSeeHtml('data-role-choice-empty');
    }

    public function test_saving_changes_only_roles_and_is_audited(): void
    {
        $hash = $this->kasir->password;
        Livewire::test(Index::class)->call('editRoles', $this->kasir->id)->set('roleChoice', ['kasir', 'pengurus'])
            ->call('saveRoles')->assertHasNoErrors()->assertSet('roleUserId', null)
            ->assertSee('Peran Bu Siti diperbarui: + Pengurus.')->assertSeeHtml('data-user-roles="pengurus,kasir"');
        $u = $this->kasir->fresh();
        $this->assertSame(['pengurus', 'kasir'], $u->roleNames());
        $this->assertSame([$hash, 'Bu Siti'], [$u->password, $u->name]);
        $log = ActivityLog::where('event', 'user.role_changed')->sole();
        $this->assertSame([['pengurus'], []], [$log->properties['added'], $log->properties['removed']]);
        $this->assertSame(['roles' => 'kasir'], $log->old_values);
        $this->assertSame(['roles' => 'pengurus, kasir'], $log->new_values);

        Livewire::test(Index::class)->call('editRoles', $this->kasir->id)->call('saveRoles')->assertSee('Peran Bu Siti tidak berubah.');
        $this->assertSame(1, ActivityLog::where('event', 'user.role_changed')->count());
    }

    public function test_validation_and_admin_guard(): void
    {
        Livewire::test(Index::class)->call('editRoles', $this->kasir->id)->set('roleChoice', [])->call('saveRoles')->assertHasErrors('roleChoice')
            ->set('roleChoice', ['tamu'])->call('saveRoles')->assertHasErrors('roleChoice.0')
            ->set('roleChoice', ['kasir', 'kasir'])->call('saveRoles')->assertHasErrors('roleChoice.0');
        $this->assertSame(['kasir'], $this->kasir->fresh()->roleNames());

        $me = auth()->user();
        Livewire::test(Index::class)->call('editRoles', $me->id)->set('roleChoice', ['kasir'])->call('saveRoles')
            ->assertHasErrors('roleChoice')->assertSee('Anda tidak bisa mencabut peran admin dari akun sendiri.');

        $this->expectException(ValidationException::class);
        app(UserService::class)->updateRoles($this->kasir, ['root'], $me);
    }

    public function test_cancel_and_missing_user(): void
    {
        Livewire::test(Index::class)->call('editRoles', $this->kasir->id)->call('cancelRoles')->assertSet('roleUserId', null)->assertDontSeeHtml('data-role-form')
            ->call('editRoles', 999999)->assertSet('roleUserId', null);
    }

    public function test_non_admin_cannot_use_form(): void
    {
        $this->loginAs('pengurus');
        $this->get('/pengguna')->assertForbidden();
    }
}
