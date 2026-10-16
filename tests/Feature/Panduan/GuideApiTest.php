<?php

namespace Tests\Feature\Panduan;

use App\Models\GuideEntry;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Services\GuideService;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuideApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
    }

    private function kasir(): User
    {
        return User::where('email', 'kasir@koperasi.test')->first();
    }

    public function test_list_contains_only_permitted_menus_for_each_role(): void
    {
        $this->actingAs($this->kasir());
        $res = $this->getJson('/panduan/data')->assertOk();
        $keys = array_column($res->json('data'), 'key');
        $this->assertEqualsCanonicalizing([...Role::named('kasir')->permissions, 'akun'], $keys);
        $this->assertSame(0, $res->json('meta.restricted'));
        $this->assertSame(14 - count(Role::named('kasir')->permissions), $res->json('meta.hidden_restricted'));
        $kasirGuide = collect($res->json('data'))->firstWhere('key', 'kasir');
        $this->assertSame([true, false, 'Layanan harian', 7], [$kasirGuide['allowed'], $kasirGuide['restricted'], $kasirGuide['group'], $kasirGuide['step_count']]);
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
    }

    public function test_restricted_entries_are_marked_and_stripped(): void
    {
        $this->actingAs($this->kasir());
        $data = collect($this->getJson('/panduan/data?terbatas=1')->assertOk()->json('data'))->keyBy('key');
        $this->assertCount(15, $data);
        $p = $data['pinjaman'];
        $this->assertTrue($p['restricted']);
        $this->assertSame(['admin', 'pengurus'], $p['required_roles']);
        $this->assertArrayNotHasKey('steps', $p);
        $this->assertArrayNotHasKey('tips', $p);
        $this->assertArrayNotHasKey('related', $p);
    }

    public function test_multi_role_union_without_duplicates(): void
    {
        $u = $this->kasir();
        $u->roles()->attach(Role::named('pengurus')->id);
        $this->actingAs($u->fresh(['role', 'roles']));
        $keys = array_column($this->getJson('/panduan/data')->json('data'), 'key');
        $this->assertSame(count($keys), count(array_unique($keys)));
        $this->assertContains('pinjaman', $keys);
        $this->assertContains('kasir', $keys);
        $this->assertNotContains('pengguna', $keys);
    }

    public function test_search_and_single_guide(): void
    {
        $this->actingAs($this->kasir());
        $this->assertSame(['anggota'], array_column($this->getJson('/panduan/data?q=piutang')->json('data'), 'key'));
        $this->getJson('/panduan/data/kasir')->assertOk()->assertJsonPath('data.title', 'Melayani penjualan di layar Kasir');
        $this->getJson('/panduan/data/pinjaman')->assertForbidden()->assertJsonPath('message', 'Menu Pinjaman hanya untuk peran Admin dan Pengurus. Bila Anda memerlukannya, minta admin koperasi menambahkan peran tersebut ke akun Anda.')
            ->assertJsonMissingPath('data.steps');
        $this->getJson('/panduan/data/tidak-ada')->assertNotFound();
        $this->getJson('/panduan/data/portal.belanja')->assertNotFound(); // panduan anggota bukan untuk petugas
        $this->getJson('/panduan/data?q='.str_repeat('x', 61))->assertUnprocessable();
    }

    public function test_admin_edits_appear_in_api_and_page(): void
    {
        $admin = User::where('email', 'admin@koperasi.test')->first();
        app(GuideService::class)->setOverride(GuideEntry::where('key', 'kasir')->first(), 'title', 'Judul kasir versi koperasi', $admin);
        $this->actingAs($this->kasir());
        $this->getJson('/panduan/data/kasir')->assertJsonPath('data.title', 'Judul kasir versi koperasi')->assertJsonPath('data.overridden', ['title']);
        $this->get('/panduan?menu=kasir')->assertSeeText('Judul kasir versi koperasi');
    }

    public function test_falls_back_to_builtin_catalog_when_table_empty(): void
    {
        GuideEntry::query()->delete();
        $this->actingAs($this->kasir());
        $this->assertContains('kasir', array_column($this->getJson('/panduan/data')->json('data'), 'key'));
    }

    public function test_portal_api_for_members_only(): void
    {
        $this->getJson('/portal/panduan/data')->assertUnauthorized();
        $this->actingAsMember(Member::factory()->create());
        $data = $this->getJson('/portal/panduan/data')->assertOk()->json('data');
        $this->assertSame(['portal.beranda', 'portal.simpanan', 'portal.pinjaman', 'portal.belanja', 'portal.riwayat'], array_column($data, 'key'));
        $this->assertTrue(collect($data)->every(fn ($g) => $g['allowed']));
    }

    public function test_staff_api_requires_staff_login(): void
    {
        auth()->logout();
        $this->getJson('/panduan/data')->assertUnauthorized();
    }
}
