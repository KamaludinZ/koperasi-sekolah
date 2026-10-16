<?php

namespace Tests\Feature\Panduan;

use App\Models\GuideEntry;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthService;
use App\Support\Menu;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** API panduan terfilter per izin: urutan baku, saring per peran, pratinjau izin tiruan, penanda via & langkah terkunci. */
class GuideApiFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->first();
    }

    private function kasirPengurus(): User
    {
        $u = $this->user('kasir@koperasi.test');
        $u->roles()->syncWithoutDetaching([Role::named('pengurus')->id]);

        return $u->fresh();
    }

    public function test_order_matches_sidebar_regardless_of_position(): void
    {
        GuideEntry::query()->get()->each(fn ($e) => $e->update(['position' => random_int(1, 999)]));
        $this->actingAs($this->user('admin@koperasi.test'), 'web');
        $keys = array_column($this->getJson('/panduan/data')->assertOk()->json('data'), 'key');
        $this->assertSame([...array_keys(Menu::ITEMS), 'akun'], $keys);
        $this->actingAs($this->user('kasir@koperasi.test'), 'web');
        $keys = array_column($this->getJson('/panduan/data?terbatas=1')->json('data'), 'key');
        $kasir = Role::named('kasir')->permissions;
        $firstRestricted = collect($keys)->search(fn ($k) => $k !== 'akun' && ! in_array($k, $kasir, true));
        $this->assertSame('kasir', $keys[0]);
        $this->assertSame('akun', end($keys));
        $this->assertNotFalse($firstRestricted);
    }

    public function test_multi_role_meta_via_and_role_filter(): void
    {
        $this->actingAs($this->kasirPengurus(), 'web');
        $res = $this->getJson('/panduan/data')->assertOk();
        $this->assertSame(['pengurus', 'kasir'], $res->json('meta.roles'));
        $this->assertSame(count(Menu::sourcesFor(AuthService::toArray($this->kasirPengurus()))), $res->json('meta.menus'));
        $this->assertSame(count(Role::named('kasir')->permissions), $res->json('meta.role_counts.kasir'));
        $data = collect($res->json('data'))->keyBy('key');
        $this->assertSame(['kasir'], $data['kasir']['via']);
        $this->assertSame(['pengurus'], $data['pinjaman']['via']);
        $this->assertSame([], $data['transaksi']['via']);
        $this->assertSame(1, collect($res->json('data'))->where('key', 'transaksi')->count());

        $keys = array_column($this->getJson('/panduan/data?peran=kasir')->assertOk()->json('data'), 'key');
        $this->assertContains('kasir', $keys);
        $this->assertContains('akun', $keys);
        $this->assertNotContains('pinjaman', $keys);
        $this->getJson('/panduan/data?peran=admin')->assertStatus(422);
        $this->getJson('/panduan/data?peran=superuser')->assertStatus(422);
    }

    public function test_locked_steps_and_related_links_follow_permissions(): void
    {
        $this->actingAs($this->user('kasir@koperasi.test'), 'web');
        $g = $this->getJson('/panduan/data/barang')->assertOk()->json('data');
        $locked = collect($g['steps'])->firstWhere('route', 'barang.impor');
        $this->assertSame(['admin', 'pengurus'], $locked['locked_for']);
        $this->assertArrayNotHasKey('locked_for', $g['steps'][0]);
        $this->assertArrayNotHasKey('barang.impor', $g['related']);
        $this->assertArrayNotHasKey('pengadaan', $g['related']);

        $this->actingAs($this->kasirPengurus(), 'web');
        $g = $this->getJson('/panduan/data/barang')->assertOk()->json('data');
        $this->assertArrayNotHasKey('locked_for', collect($g['steps'])->firstWhere('route', 'barang.impor'));
    }

    public function test_admin_preview_with_mock_permissions(): void
    {
        $this->actingAs($this->user('admin@koperasi.test'), 'web');
        $res = $this->getJson('/panduan/data?sebagai=kasir')->assertOk();
        $this->assertTrue($res->json('meta.preview'));
        $this->assertEqualsCanonicalizing([...Role::named('kasir')->permissions, 'akun'], array_column($res->json('data'), 'key'));
        $this->getJson('/panduan/data/pinjaman?sebagai=kasir')->assertForbidden()->assertJsonPath('message', 'Menu Pinjaman tidak termasuk peran yang ditinjau.');
        $this->getJson('/panduan/data/pinjaman?sebagai=pengurus,kasir')->assertOk()->assertJsonPath('data.via', ['pengurus']);
        $this->getJson('/panduan/data?sebagai=superuser')->assertStatus(422);
        $this->assertFalse($this->getJson('/panduan/data')->json('meta.preview'));
    }

    public function test_non_admin_cannot_preview(): void
    {
        $this->actingAs($this->user('kasir@koperasi.test'), 'web');
        $this->getJson('/panduan/data?sebagai=admin')->assertForbidden();
        $this->getJson('/panduan/data/pengguna?sebagai=admin')->assertForbidden();
        $this->actingAs($this->user('pengurus@koperasi.test'), 'web');
        $this->getJson('/panduan/data?sebagai=admin')->assertForbidden();
    }

    public function test_portal_api_unchanged_shape(): void
    {
        $this->actingAsMember(Member::factory()->create());
        $g = $this->getJson('/portal/panduan/data')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('via', $g);
        $this->assertSame('portal.beranda', $g['key']);
    }
}
