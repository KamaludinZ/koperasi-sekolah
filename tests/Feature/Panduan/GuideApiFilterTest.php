<?php

namespace Tests\Feature\Panduan;

use App\Models\GuideEntry;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthService;
use App\Services\GuideService;
use App\Support\Menu;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->getJson('/panduan/data/pinjaman?sebagai=kasir')->assertForbidden()->assertJsonPath('message', 'Menu Pinjaman hanya untuk peran Admin dan Pengurus. Menu ini tidak termasuk peran yang ditinjau.')->assertJsonPath('reason', 'peran');
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

    public function test_restricted_marker_has_reason_message_and_role_labels(): void
    {
        $this->actingAs($this->user('kasir@koperasi.test'), 'web');
        $data = collect($this->getJson('/panduan/data?terbatas=1')->assertOk()->json('data'))->keyBy('key');
        $p = $data['pinjaman'];
        $this->assertTrue($p['restricted']);
        $this->assertSame(['reason' => 'peran', 'message' => 'Menu Pinjaman hanya untuk peran Admin dan Pengurus. Bila Anda memerlukannya, minta admin koperasi menambahkan peran tersebut ke akun Anda.',
            'required_roles' => ['admin', 'pengurus'], 'required_role_labels' => ['Admin', 'Pengurus']], $p['restriction']);
        foreach (['steps', 'tips', 'related', 'via', 'locked_steps'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $p);
        }
        $this->assertArrayNotHasKey('restriction', $data['kasir']);
        $this->assertSame(1, $data['barang']['locked_steps']);                                // impor khusus Admin/Pengurus
        $this->assertSame(0, $data['kasir']['locked_steps']);
        $this->getJson('/panduan/data/pinjaman')->assertForbidden()->assertJsonPath('reason', 'peran')
            ->assertJsonPath('data.restriction.required_role_labels', ['Admin', 'Pengurus']);
    }

    public function test_restricted_reason_for_unassigned_menu(): void
    {
        Role::query()->get()->each(fn ($r) => $r->update(['permissions' => array_values(array_diff($r->permissions, ['promo']))]));
        $this->actingAs($this->user('kasir@koperasi.test'), 'web');
        $this->getJson('/panduan/data/promo')->assertForbidden()->assertJsonPath('reason', 'belum_diberikan')
            ->assertJsonPath('message', 'Menu Diskon & Promo belum diberikan ke peran mana pun.')->assertJsonPath('data.restriction.required_roles', []);
    }

    public function test_restricted_reason_for_inactive_viewer(): void
    {
        $service = app(GuideService::class);
        $r = $service->restriction(['role' => 'kasir', 'roles' => ['kasir'], 'permissions' => ['kasir'], 'is_active' => false], 'kasir', ['admin', 'kasir']);
        $this->assertSame('akun_nonaktif', $r['reason']);
        $this->assertSame(['Admin', 'Kasir'], $r['required_role_labels']);
    }

    public function test_restricted_list_uses_constant_number_of_role_queries(): void
    {
        $this->actingAs($this->user('kasir@koperasi.test'), 'web');
        DB::enableQueryLog();
        $this->getJson('/panduan/data?terbatas=1')->assertOk();
        $roleQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "roles"') && str_contains($q['query'], '"permissions"'))->count();
        $this->assertLessThanOrEqual(3, $roleQueries);
    }
}
