<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnsureMenuAccess;
use App\Http\Middleware\EnsureRole;
use App\Models\Role;
use App\Models\User;
use App\Support\Menu;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Sapuan seluruh rute: petugas yang berada DI LUAR gabungan izin semua perannya selalu mendapat 403 —
 * untuk setiap metode (GET/POST/PUT/DELETE), termasuk rute ber-parameter dengan data yang tidak ada
 * (penjaga berjalan sebelum pengikatan model, sehingga 404 tidak membocorkan ada/tidaknya data).
 */
class ForbiddenOutsideUnionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        // batas laju bukan bagian yang diuji (ratusan permintaan berturut-turut); 429 juga tidak membocorkan data
        $this->withoutMiddleware([ThrottleRequests::class, ThrottleRequestsWithRedis::class]);
    }

    /** @return list<array{route: RoutingRoute, menu: ?string, roles: list<string>, all: bool}> */
    private function guardedRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $menu = null;
            $roles = [];
            $all = false;
            foreach ($route->gatherMiddleware() as $mw) {
                if (! is_string($mw)) {
                    continue;
                }
                [$name, $args] = array_pad(explode(':', $mw, 2), 2, '');
                if (in_array($name, ['menu', EnsureMenuAccess::class], true) && $args !== '') {
                    $menu = $args;
                } elseif (in_array($name, ['peran', EnsureRole::class], true)) {
                    ['roles' => $roles, 'all' => $all] = EnsureRole::parse(explode(',', $args));
                }
            }
            if ($menu !== null || $roles !== []) {
                $out[] = compact('route', 'menu', 'roles', 'all');
            }
        }

        return $out;
    }

    /** URI contoh yang benar-benar cocok dengan rute ini, dengan parameter yang menunjuk data yang tidak ada. */
    private function sampleUri(RoutingRoute $route, string $method): ?string
    {
        $uri = $route->uri();
        foreach ($route->parameterNames() as $p) {
            $pattern = $route->wheres[$p] ?? null;
            $value = collect(['999999', 'zzzz', 'aaaaaaaa-0000-0000-0000-000000000000', 'zzzz.png', '2026-01'])
                ->first(fn ($v) => $pattern === null || preg_match('#^(?:'.$pattern.')$#', $v))
                // pola pilihan tetap (mis. png|svg) → pakai pilihan pertamanya
                ?? (preg_match('/^\(?([A-Za-z0-9_-]+)/', (string) $pattern, $m) && preg_match('#^(?:'.$pattern.')$#', $m[1]) ? $m[1] : null);
            if ($value === null) {
                return null;
            }
            $uri = preg_replace('/\{'.$p.'(:[^}]*)?\??\}/', $value, $uri);
        }
        try {
            $matched = Route::getRoutes()->match(Request::create('/'.$uri, $method));
        } catch (\Throwable) {
            return null;
        }

        return $matched->uri() === $route->uri() ? '/'.$uri : null;
    }

    /** Akun memegang semua peran yang lolos `peran:`, tapi tak satu pun perannya memberi menu ini. */
    private function userWithout(string $menu): User
    {
        foreach (Role::all() as $role) {
            $role->forceFill(['permissions' => array_values(array_diff($role->permissions ?? [], [$menu]))])->save();
        }
        $u = User::factory()->role('admin')->create();
        foreach (['pengurus', 'kasir'] as $r) {
            $u->roles()->attach(Role::named($r)->id);
        }

        return $u->fresh(['role', 'roles']);
    }

    public function test_every_menu_guarded_route_returns_403_for_users_outside_the_union(): void
    {
        $byMenu = collect($this->guardedRoutes())->filter(fn ($r) => $r['menu'] !== null)->groupBy('menu');
        $this->assertGreaterThanOrEqual(count(Menu::ITEMS) - 1, $byMenu->count());
        $checked = 0;
        $skipped = [];

        foreach ($byMenu as $menu => $routes) {
            $this->refreshApplicationRoles();
            $this->actingAs($this->userWithout($menu));
            foreach ($routes as $r) {
                foreach (array_diff($r['route']->methods(), ['HEAD']) as $method) {
                    $uri = $this->sampleUri($r['route'], $method);
                    if ($uri === null) {
                        $skipped[] = $method.' '.$r['route']->uri();

                        continue;
                    }
                    $status = $this->json($method, $uri)->status();
                    $this->assertSame(403, $status, "{$method} {$uri} (menu {$menu}) seharusnya 403, dapat {$status}");
                    $checked++;
                }
            }
        }
        $this->assertGreaterThan(150, $checked);
        $this->assertSame([], $skipped, 'rute yang tidak bisa dicoba: '.implode(', ', $skipped));
    }

    public function test_every_role_guarded_route_returns_403_for_users_without_the_role(): void
    {
        $checked = 0;
        foreach ($this->guardedRoutes() as $r) {
            if ($r['roles'] === []) {
                continue;
            }
            // semua menu dibuka, tapi peran yang dipegang tidak memenuhi syarat peran rute
            $lacking = array_values(array_diff(array_keys(Role::LABELS), $r['roles']));
            if ($lacking === []) {
                continue;
            }
            foreach (Role::all() as $role) {
                $role->forceFill(['permissions' => array_keys(Menu::ITEMS)])->save();
            }
            $u = User::factory()->role($lacking[0])->create();
            foreach (array_slice($lacking, 1) as $extra) {
                $u->roles()->attach(Role::named($extra)->id);
            }
            $this->actingAs($u->fresh(['role', 'roles']));
            foreach (array_diff($r['route']->methods(), ['HEAD']) as $method) {
                $uri = $this->sampleUri($r['route'], $method);
                $this->assertNotNull($uri, $r['route']->uri());
                $status = $this->json($method, $uri)->status();
                $this->assertSame(403, $status, "{$method} {$uri} (peran ".implode(',', $r['roles']).") seharusnya 403, dapat {$status}");
                $checked++;
            }
        }
        $this->assertGreaterThan(20, $checked);
    }

    public function test_union_member_gets_404_for_missing_data_but_outsider_gets_403(): void
    {
        $outsider = User::factory()->role('kasir')->create(); // kasir tak punya menu Pinjaman
        $this->actingAs($outsider->fresh(['role', 'roles']));
        $this->getJson('/pinjaman/999999')->assertForbidden();
        $this->getJson('/pengguna/999999/data')->assertForbidden();

        $insider = User::factory()->role('kasir')->create();
        $insider->roles()->attach(Role::named('pengurus')->id); // pinjaman dari pengurus
        $this->actingAs($insider->fresh(['role', 'roles']));
        $this->getJson('/pinjaman/999999')->assertNotFound();
    }

    public function test_403_json_carries_reason(): void
    {
        $this->actingAs(User::factory()->role('kasir')->create());
        $this->getJson('/laporan/hari-ini')->assertForbidden()->assertJsonPath('message', 'Menu Laporan hanya untuk peran Admin dan Pengurus.');
        $this->getJson('/pengguna/daftar')->assertForbidden()->assertJsonPath('message', 'Menu Pengguna hanya untuk peran Admin.');
    }

    private function refreshApplicationRoles(): void
    {
        foreach (Menu::DEFAULT_PERMISSIONS as $name => $perms) {
            Role::named($name)->forceFill(['permissions' => $perms])->save();
        }
    }
}
