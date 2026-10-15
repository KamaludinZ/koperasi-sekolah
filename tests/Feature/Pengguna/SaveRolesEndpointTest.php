<?php

namespace Tests\Feature\Pengguna;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaveRolesEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $u;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs('admin');
        $this->u = User::factory()->role('kasir')->create(['name' => 'Bu Siti']);
    }

    public function test_put_replaces_all_roles_and_reports_changes(): void
    {
        $this->putJson(route('pengguna.peran.save', $this->u), ['roles' => ['kasir', 'pengurus']])->assertOk()
            ->assertJsonPath('data.roles', ['pengurus', 'kasir'])->assertJsonPath('data.primary_role', 'pengurus')
            ->assertJsonPath('added', ['pengurus'])->assertJsonPath('removed', [])->assertJsonPath('changed', true);
        $this->putJson(route('pengguna.peran.save', $this->u), ['roles' => ['pengurus', 'kasir']])->assertOk()
            ->assertJsonPath('changed', false)->assertJsonPath('message', 'Peran tidak berubah.');
        $this->putJson(route('pengguna.peran.save', $this->u), ['roles' => ['admin']])->assertOk()
            ->assertJsonPath('added', ['admin'])->assertJsonPath('removed', ['pengurus', 'kasir']);
        $this->assertSame(['admin'], $this->u->fresh()->roleNames());
        $this->assertSame(2, ActivityLog::where('event', 'user.role_changed')->count());
    }

    public function test_validation(): void
    {
        $url = route('pengguna.peran.save', $this->u);
        $this->putJson($url, ['roles' => []])->assertUnprocessable()->assertJsonPath('errors.roles.0', UserService::MSG_MIN_ROLE);
        $this->putJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('roles');
        $this->putJson($url, ['roles' => 'kasir'])->assertUnprocessable()->assertJsonValidationErrors('roles');
        $this->putJson($url, ['roles' => ['kasir', 'tamu']])->assertUnprocessable()->assertJsonValidationErrors('roles.1');
        $this->putJson($url, ['roles' => ['kasir', 'kasir']])->assertUnprocessable()->assertJsonValidationErrors('roles.0');
        $this->putJson('/pengguna/999999/peran', ['roles' => ['kasir']])->assertNotFound();
        $this->assertSame(['kasir'], $this->u->fresh()->roleNames());
    }

    public function test_optimistic_concurrency_with_expected_roles(): void
    {
        $url = route('pengguna.peran.save', $this->u);
        $this->putJson($url, ['roles' => ['kasir', 'pengurus'], 'expected_roles' => ['kasir']])->assertOk();
        // klien lain masih memegang data lama ["kasir"]
        $this->putJson($url, ['roles' => ['admin'], 'expected_roles' => ['kasir']])->assertStatus(409)
            ->assertJsonPath('data.roles', ['pengurus', 'kasir']);
        $this->assertSame(['pengurus', 'kasir'], $this->u->fresh()->roleNames());
        $this->putJson($url, ['roles' => ['admin'], 'expected_roles' => ['kasir', 'pengurus']])->assertOk(); // urutan bebas
    }

    public function test_add_and_remove_single_role(): void
    {
        $this->postJson(route('pengguna.peran.add', [$this->u, 'pengurus']))->assertOk()->assertJsonPath('data.roles', ['pengurus', 'kasir']);
        $this->postJson(route('pengguna.peran.add', [$this->u, 'pengurus']))->assertOk()->assertJsonPath('changed', false);
        $this->deleteJson(route('pengguna.peran.remove', [$this->u, 'kasir']))->assertOk()->assertJsonPath('removed', ['kasir']);
        $this->deleteJson(route('pengguna.peran.remove', [$this->u, 'pengurus']))->assertUnprocessable()->assertJsonValidationErrors('roles');
        $this->deleteJson(route('pengguna.peran.remove', [$this->u, 'admin']))->assertUnprocessable();
        $this->postJson('/pengguna/'.$this->u->id.'/peran/tamu')->assertNotFound();
        $this->assertSame(['pengurus'], $this->u->fresh()->roleNames());
    }

    public function test_admin_guards_apply(): void
    {
        $me = auth()->user();
        $this->putJson(route('pengguna.peran.save', $me), ['roles' => ['kasir']])->assertUnprocessable()->assertJsonValidationErrors('roles');
        $this->deleteJson(route('pengguna.peran.remove', [$me, 'admin']))->assertUnprocessable();
        $this->postJson(route('pengguna.peran.add', [$me, 'kasir']))->assertOk();
        $this->assertSame(['admin', 'kasir'], $me->fresh()->roleNames());
    }

    public function test_only_admin_and_rate_limited_group(): void
    {
        $this->loginAs('pengurus');
        $this->putJson(route('pengguna.peran.save', $this->u), ['roles' => ['admin']])->assertForbidden();
        $this->postJson(route('pengguna.peran.add', [$this->u, 'admin']))->assertForbidden();
        auth()->logout();
        $this->putJson(route('pengguna.peran.save', $this->u), ['roles' => ['admin']])->assertUnauthorized();
        $this->assertSame(['kasir'], $this->u->fresh()->roleNames());
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->first(fn ($r) => $r->getName() === 'pengguna.peran.save');
        $this->assertContains('throttle:30,1', $route->gatherMiddleware());
    }
}
