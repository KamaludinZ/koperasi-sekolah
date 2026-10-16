<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Panduan\Index;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Support\Menu;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kontrak respons API panduan (kunci tepat per jenis panduan, meta) dan deduplikasi menu untuk SEMUA kombinasi peran:
 * gabungan izin tanpa menu ganda, urutan seperti menu samping, penanda via/terbatas konsisten, halaman = API.
 */
class GuideResponseContractTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = ['key', 'menu', 'menu_label', 'group', 'title', 'summary', 'allowed', 'restricted', 'overridden', 'stale'];

    private const OPEN = [...self::BASE, 'via', 'steps', 'tips', 'related', 'step_count', 'locked_steps'];

    private const LOCKED = [...self::BASE, 'required_roles', 'restriction'];

    private const MEMBER = [...self::BASE, 'steps', 'tips', 'related', 'step_count', 'locked_steps', 'notices'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
    }

    /** @return array<string, list<string>> semua kombinasi peran tak kosong */
    public static function combos(): array
    {
        $out = [];
        $roles = ['admin', 'kasir', 'pengurus'];
        for ($mask = 1; $mask < 8; $mask++) {
            $set = array_values(array_filter($roles, fn ($r, $i) => $mask & (1 << $i), ARRAY_FILTER_USE_BOTH));
            $out[implode('+', $set)] = [$set];
        }

        return $out;
    }

    private function userWith(array $roles): User
    {
        $u = User::factory()->create(['role_id' => Role::named(Role::sortNames($roles)[0])->id, 'is_active' => true]);
        $u->roles()->sync(Role::whereIn('name', $roles)->pluck('id'));

        return $u->fresh();
    }

    private function assertKeys(array $expected, array $guide, string $ctx): void
    {
        $this->assertEqualsCanonicalizing($expected, array_keys($guide), "{$ctx}: kunci respons {$guide['key']}");
    }

    #[DataProvider('combos')]
    public function test_union_dedup_order_and_contract_for_role_combination(array $roles): void
    {
        $this->actingAs($this->userWith($roles), 'web');
        $ctx = implode('+', $roles);
        $union = array_values(array_unique(array_merge(...array_map(fn ($r) => Role::named($r)->permissions, $roles))));
        $expectedOrder = [...array_values(array_filter(array_keys(Menu::ITEMS), fn ($m) => in_array($m, $union, true))), 'akun'];

        $res = $this->getJson('/panduan/data')->assertOk();
        $data = $res->json('data');
        $keys = array_column($data, 'key');
        $this->assertSame($expectedOrder, $keys, "{$ctx}: gabungan & urutan");
        $this->assertSame(array_values(array_unique($keys)), $keys, "{$ctx}: tanpa duplikat");
        foreach ($data as $g) {
            $this->assertKeys(self::OPEN, $g, $ctx);
            $this->assertTrue($g['allowed']);
            $this->assertFalse($g['restricted']);
            $this->assertSame(count($g['steps']), $g['step_count']);
            $this->assertSame([], array_diff($g['via'], $roles), "{$ctx}: via hanya peran milik sendiri");
            if (count($roles) === 1) {
                $this->assertSame([], $g['via']);
            }
            $this->assertSame([], array_intersect_key($g['related'], array_flip(array_filter(array_column($g['steps'], 'route')))), "{$ctx}: tautan ganda");
            $this->assertSame(count(array_filter($g['steps'], fn ($s) => isset($s['locked_for']))), $g['locked_steps']);
        }
        $meta = $res->json('meta');
        $this->assertEqualsCanonicalizing(['total', 'restricted', 'hidden_restricted', 'roles', 'role', 'role_counts', 'menus', 'shared', 'duplicates_merged', 'preview'], array_keys($meta));
        $this->assertSame([count($data), 0, 14 - count($union), Role::sortNames($roles), count($union), false],
            [$meta['total'], $meta['restricted'], $meta['hidden_restricted'], $meta['roles'], $meta['menus'], $meta['preview']]);
        $perRole = array_sum(array_map(fn ($r) => count(Role::named($r)->permissions), $roles));
        $this->assertSame($perRole - count($union), $meta['duplicates_merged'], "{$ctx}: salinan yang digabung");

        // dengan menu terbatas: seluruh menu sistem tepat sekali, terbatas berkontrak sendiri
        $all = $this->getJson('/panduan/data?terbatas=1')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([...array_keys(Menu::ITEMS), 'akun'], array_column($all, 'key'));
        foreach ($all as $g) {
            if ($g['restricted']) {
                $this->assertKeys(self::LOCKED, $g, $ctx);
                $this->assertNotContains($g['menu'], $union);
                $this->assertSame([], array_intersect($g['required_roles'], $roles), "{$ctx}: peran yang dibutuhkan bukan milik sendiri");
                $this->assertSame(['reason', 'message', 'required_roles', 'required_role_labels'], array_keys($g['restriction']));
            }
        }

        // halaman Pusat Panduan menampilkan urutan yang sama dengan API
        preg_match_all('/data-guide-item="([^"]+)"/', Livewire::test(Index::class)->html(), $m);
        $this->assertSame($expectedOrder, $m[1], "{$ctx}: halaman = API");
    }

    /** @return array<string, list<string>> kombinasi multi-peran saja */
    public static function multiRoleCombos(): array
    {
        return array_filter(self::combos(), fn ($c) => count($c[0]) > 1);
    }

    #[DataProvider('multiRoleCombos')]
    public function test_role_filter_partitions_menus_without_loss(array $roles): void
    {
        $this->actingAs($this->userWith($roles), 'web');
        $seen = [];
        foreach ($roles as $r) {
            $keys = array_column($this->getJson('/panduan/data?peran='.$r)->assertOk()->json('data'), 'key');
            $this->assertEqualsCanonicalizing([...Role::named($r)->permissions, 'akun'], $keys, "saring {$r}");
            $seen = [...$seen, ...$keys];
        }
        $this->assertEqualsCanonicalizing(array_column($this->getJson('/panduan/data')->json('data'), 'key'), array_values(array_unique($seen)));
    }

    public function test_single_guide_contracts(): void
    {
        $this->actingAs($this->userWith(['kasir']), 'web');
        $this->assertKeys(self::OPEN, $this->getJson('/panduan/data/kasir')->assertOk()->json('data'), 'show');
        $forbidden = $this->getJson('/panduan/data/pinjaman')->assertForbidden()->json();
        $this->assertSame(['message', 'reason', 'data'], array_keys($forbidden));
        $this->assertKeys(self::LOCKED, $forbidden['data'], 'show 403');
        $this->getJson('/panduan/data/tidak-ada')->assertNotFound();
    }

    public function test_member_contracts(): void
    {
        $this->actingAsMember(Member::factory()->create());
        $res = $this->getJson('/portal/panduan/data')->assertOk()->json();
        $this->assertSame(['data', 'meta'], array_keys($res));
        $this->assertEqualsCanonicalizing(['total', 'pin', 'overdue_installments', 'read_only'], array_keys($res['meta']));
        $this->assertSame(['has_pin', 'locked', 'minutes', 'attempts_left'], array_keys($res['meta']['pin']));
        foreach ($res['data'] as $g) {
            $this->assertKeys(self::MEMBER, $g, 'portal');
            $this->assertNull($g['menu']);
            $this->assertSame('Portal', $g['menu_label']);
        }
        $one = $this->getJson('/portal/panduan/data/portal.simpanan')->assertOk()->json();
        $this->assertSame(['data', 'meta'], array_keys($one));
        $this->assertKeys(self::MEMBER, $one['data'], 'portal show');
    }
}
