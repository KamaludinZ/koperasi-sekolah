<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnsureRole;
use App\Models\Role;
use App\Models\User;
use App\Support\Menu;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EnsureRoleAllRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        Route::middleware(['web', 'peran:admin,pengurus'])->get('/_uji/salah-satu', fn () => 'ok')->name('uji.salah-satu');
        Route::middleware(['web', 'peran:semua,kasir,pengurus'])->get('/_uji/semua', fn () => 'ok')->name('uji.semua');
        Route::getRoutes()->refreshNameLookups();
    }

    private function user(string ...$roles): User
    {
        $u = User::factory()->role($roles[0])->create();
        foreach (array_slice($roles, 1) as $r) {
            $u->roles()->attach(Role::named($r)->id);
        }

        return $u->fresh(['role', 'roles']);
    }

    public function test_any_mode_checks_every_held_role_not_just_primary(): void
    {
        $this->actingAs($this->user('kasir', 'pengurus')); // peran utama kasir, pengurus hanya di pivot
        $this->get('/_uji/salah-satu')->assertOk();
        $this->actingAs($this->user('kasir'));
        $this->get('/_uji/salah-satu')->assertForbidden()->assertSeeText('Halaman ini hanya untuk peran Admin dan Pengurus.');
    }

    public function test_all_mode_requires_every_listed_role(): void
    {
        $this->actingAs($this->user('kasir'));
        $this->get('/_uji/semua')->assertForbidden()->assertSeeText('hanya untuk akun yang memegang peran Kasir sekaligus Pengurus');
        $this->actingAs($this->user('pengurus'));
        $this->get('/_uji/semua')->assertForbidden();
        $this->actingAs($this->user('pengurus', 'kasir'));
        $this->get('/_uji/semua')->assertOk();
        $this->actingAs($this->user('admin', 'kasir', 'pengurus'));
        $this->get('/_uji/semua')->assertOk();
    }

    public function test_revoked_role_takes_effect_on_the_next_request(): void
    {
        $u = $this->user('kasir', 'pengurus');
        $this->actingAs($u);
        $this->get('/_uji/semua')->assertOk();
        $u->roles()->detach(Role::named('pengurus')->id);
        $this->actingAs($u->fresh(['role', 'roles']));
        $this->get('/_uji/semua')->assertForbidden();
        $this->get('/_uji/salah-satu')->assertForbidden();
    }

    public function test_inactive_account_is_denied_even_with_matching_role(): void
    {
        $mw = new EnsureRole;
        $u = $this->user('admin');
        $u->forceFill(['is_active' => false])->save();
        Auth::guard('web')->setUser($u->fresh(['role', 'roles']));
        try {
            $mw->handle(request(), fn () => response('ok'), 'admin');
            $this->fail('akun nonaktif harus ditolak');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('Akun Anda nonaktif.', $e->getMessage());
        }
    }

    public function test_unknown_role_name_on_a_route_is_a_loud_error(): void
    {
        Route::middleware(['web', 'peran:admni'])->get('/_uji/typo', fn () => 'ok');
        $this->actingAs($this->user('admin'));
        $this->withoutExceptionHandling();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('peran tak dikenal: admni');
        $this->get('/_uji/typo');
    }

    public function test_parse_and_allows_helpers(): void
    {
        $this->assertSame(['roles' => ['admin', 'pengurus'], 'all' => false, 'unknown' => []], EnsureRole::parse(['admin', ' pengurus', '', 'admin']));
        $this->assertSame(['roles' => ['kasir'], 'all' => true, 'unknown' => []], EnsureRole::parse(['semua', 'kasir']));
        $this->assertSame(['ratu'], EnsureRole::parse(['ratu'])['unknown']);
        $this->assertTrue(EnsureRole::allows(['kasir'], []));
        $this->assertTrue(EnsureRole::allows(['kasir', 'pengurus'], ['admin', 'pengurus']));
        $this->assertFalse(EnsureRole::allows(['kasir'], ['admin', 'pengurus']));
        $this->assertTrue(EnsureRole::allows(['pengurus', 'kasir'], ['semua', 'kasir', 'pengurus']));
        $this->assertFalse(EnsureRole::allows(['kasir'], ['semua', 'kasir', 'pengurus']));
        $this->assertFalse(EnsureRole::allows(['admin'], ['ratu'])); // salah tulis tidak pernah membuka
    }

    public function test_link_helpers_follow_the_same_rule(): void
    {
        $this->actingAs($this->user('kasir'));
        $this->assertFalse(Menu::canReach('uji.semua'));
        $this->actingAs($this->user('kasir', 'pengurus'));
        $this->assertTrue(Menu::canReach('uji.semua'));
        $this->assertTrue(Menu::canReach('uji.salah-satu'));
    }

    public function test_every_role_guard_in_routes_uses_known_roles(): void
    {
        $checked = 0;
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $mw) {
                if (is_string($mw) && (str_starts_with($mw, 'peran:') || str_starts_with($mw, EnsureRole::class.':'))) {
                    $parsed = EnsureRole::parse(explode(',', explode(':', $mw, 2)[1]));
                    if (! str_starts_with($route->uri(), '_uji/typo')) {
                        $this->assertSame([], $parsed['unknown'], $route->uri());
                        $this->assertNotSame([], $parsed['roles'], $route->uri());
                    }
                    $checked++;
                }
            }
        }
        $this->assertGreaterThan(10, $checked);
    }
}
