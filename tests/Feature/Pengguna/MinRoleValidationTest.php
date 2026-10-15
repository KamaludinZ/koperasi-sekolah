<?php

namespace Tests\Feature\Pengguna;

use App\Livewire\Pengguna\Index;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MinRoleValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_form_shows_live_message_disables_submit_and_rejects_save(): void
    {
        $this->loginAs('admin');
        Livewire::test(Index::class)->call('create')
            ->assertDontSeeHtml('data-roles-empty')->assertDontSeeHtml('aria-invalid="true"')
            ->set('form.roles', [])
            ->assertSeeHtml('data-roles-empty')->assertSee(UserService::MSG_MIN_ROLE)
            ->assertSeeHtml('aria-invalid="true"')->assertSeeHtml('aria-live="polite"')
            ->assertSeeHtml('disabled data-user-submit')
            ->set('form.name', 'X')->set('form.email', 'x@koperasi.test')->set('form.password', 'rahasia123')->set('form.password_confirmation', 'rahasia123')
            ->call('save')->assertHasErrors(['form.roles'])->assertSeeHtml('data-roles-error')
            ->set('form.roles', ['kasir'])->assertDontSeeHtml('data-roles-empty')->call('save')->assertHasNoErrors();
    }

    public function test_role_form_message(): void
    {
        $this->loginAs('admin');
        $u = User::factory()->role('kasir')->create();
        Livewire::test(Index::class)->call('editRoles', $u->id)->set('roleChoice', [])
            ->assertSeeHtml('data-role-choice-empty')->assertSee(UserService::MSG_MIN_ROLE)->assertSeeHtml('aria-invalid="true"')
            ->call('saveRoles')->assertHasErrors('roleChoice')->assertSeeHtml('data-role-choice-error');
        $this->assertSame(['kasir'], $u->fresh()->roleNames());
    }

    public function test_json_endpoints_return_same_message(): void
    {
        $this->loginAs('admin');
        $body = ['name' => 'X', 'email' => 'x@koperasi.test', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123'];
        foreach ([['roles' => []], [], ['roles' => 'kasir'], ['roles' => [], 'role' => null]] as $extra) {
            $this->postJson('/pengguna', $body + $extra)->assertUnprocessable()->assertJsonPath('errors.roles.0', UserService::MSG_MIN_ROLE);
        }
        $u = User::factory()->role('kasir')->create();
        $this->putJson('/pengguna/'.$u->id, ['name' => $u->name, 'email' => $u->email, 'roles' => []])->assertUnprocessable()
            ->assertJsonPath('errors.roles.0', UserService::MSG_MIN_ROLE);
        $this->assertSame(['kasir'], $u->fresh()->roleNames());
    }
}
