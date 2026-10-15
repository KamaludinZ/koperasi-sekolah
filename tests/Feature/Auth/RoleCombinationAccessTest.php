<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use App\Support\AccessPreview;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Uji akses per peran dan per kombinasi peran, terhadap SPESIFIKASI tertulis (bukan dihitung dari kode yang diuji):
 * setiap halaman petugas tanpa parameter dicoba lewat HTTP untuk ketujuh kombinasi peran (admin, kasir, pengurus
 * dan semua gabungannya). Kombinasi = gabungan: halaman terbuka bila salah satu perannya membuka halaman itu,
 * dan tidak ada peran yang "mengurangi" akses peran lain.
 */
class RoleCombinationAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Halaman yang boleh dibuka tiap peran TUNGGAL dengan hak menu bawaan (keputusan produk). */
    private const SPEC = [
        'kasir' => [
            '/kasir', '/pesanan', '/transaksi', '/transaksi/retur', '/transaksi/retur/riwayat',
            '/kas', '/kas/rekap', '/kas/shift',
            '/barang', '/barang/barcode', '/barang/barcode/label', '/barang/kategori', '/barang/riwayat-stok', '/barang/saran-restok',
            '/promo', '/promo/jadwal', '/anggota', '/anggota/piutang',
        ],
        'pengurus' => [
            '/pesanan', '/transaksi', '/transaksi/retur', '/transaksi/retur/riwayat',
            '/kas', '/kas/rekap', '/kas/shift',
            '/barang', '/barang/barcode', '/barang/barcode/label', '/barang/kategori', '/barang/riwayat-stok', '/barang/saran-restok',
            '/barang/impor', '/barang/impor/riwayat',
            '/pengadaan', '/pengadaan/hutang', '/pengadaan/hutang/bayar', '/pengadaan/penerimaan', '/pengadaan/pesanan',
            '/pengadaan/pesanan/baru', '/pengadaan/supplier', '/pengadaan/supplier/baru',
            '/promo', '/promo/jadwal', '/anggota', '/anggota/piutang',
            '/simpanan', '/simpanan/mutasi', '/simpanan/setoran-bulanan', '/simpanan/setoran-bulanan/rekap',
            '/pinjaman', '/pinjaman/angsuran', '/pinjaman/angsuran/riwayat', '/pinjaman/audit', '/pinjaman/pencairan',
            '/pinjaman/pengajuan', '/pinjaman/rekap', '/pinjaman/tunggakan',
            '/laporan', '/audit', '/audit/perubahan', '/audit/terhapus',
        ],
        'admin' => '*', // seluruh halaman petugas
    ];

    /** Hak aksi tiap peran tunggal (bawaan). */
    private const ABILITY_SPEC = [
        'kasir' => ['kelola-shift'],
        'pengurus' => ['setujui-retur', 'kelola-katalog', 'kelola-pengadaan', 'kelola-anggota', 'kelola-simpanan', 'kelola-pinjaman', 'lihat-rekap-pinjaman'],
        'admin' => '*',
    ];

    private const COMBINATIONS = [
        ['admin'], ['kasir'], ['pengurus'],
        ['admin', 'kasir'], ['admin', 'pengurus'], ['pengurus', 'kasir'],
        ['admin', 'pengurus', 'kasir'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    /** @return list<string> halaman petugas tanpa parameter rute */
    private function pages(): array
    {
        return collect((new AccessPreview)->pages())->pluck('uri')->reject(fn ($u) => str_contains($u, '{'))->values()->all();
    }

    private function user(array $roles): User
    {
        // peran utama sengaja yang prioritasnya PALING RENDAH agar tidak ada yang lolos hanya karena peran utama
        $roles = array_reverse(Role::sortNames($roles));
        $u = User::factory()->role($roles[0])->create();
        foreach (array_slice($roles, 1) as $r) {
            $u->roles()->attach(Role::named($r)->id);
        }

        return $u->fresh(['role', 'roles']);
    }

    private function expectedOpen(array $combo, string $page): bool
    {
        foreach ($combo as $role) {
            if (self::SPEC[$role] === '*' || in_array($page, self::SPEC[$role], true)) {
                return true;
            }
        }

        return false;
    }

    public function test_spec_covers_every_page_and_every_page_is_reachable_by_someone(): void
    {
        $pages = $this->pages();
        $this->assertGreaterThan(50, count($pages));
        foreach (['kasir', 'pengurus'] as $role) {
            $this->assertSame([], array_values(array_diff(self::SPEC[$role], $pages)), "SPEC {$role} memuat halaman yang tidak ada");
        }
    }

    public function test_every_role_and_combination_opens_exactly_the_specified_pages(): void
    {
        $pages = $this->pages();
        $mismatch = [];
        foreach (self::COMBINATIONS as $combo) {
            $this->actingAs($this->user($combo));
            foreach ($pages as $page) {
                $status = $this->get($page)->status();
                $expected = $this->expectedOpen($combo, $page);
                if (($expected && $status !== 200) || (! $expected && $status !== 403)) {
                    $mismatch[] = implode('+', $combo)." {$page}: dapat {$status}, harusnya ".($expected ? '200' : '403');
                }
            }
        }
        $this->assertSame([], $mismatch, implode("\n", $mismatch));
    }

    public function test_combination_never_has_less_access_than_any_of_its_roles(): void
    {
        $pages = $this->pages();
        $open = [];
        foreach (self::COMBINATIONS as $combo) {
            $this->actingAs($this->user($combo));
            $open[implode('+', $combo)] = array_values(array_filter($pages, fn ($p) => $this->get($p)->status() === 200));
        }
        foreach (self::COMBINATIONS as $combo) {
            foreach ($combo as $single) {
                $missing = array_diff($open[$single], $open[implode('+', $combo)]);
                $this->assertSame([], array_values($missing), implode('+', $combo)." kehilangan halaman dari {$single}");
            }
        }
    }

    public function test_abilities_per_role_and_combination(): void
    {
        foreach (self::COMBINATIONS as $combo) {
            $u = $this->user($combo);
            foreach (AccessPreview::ABILITIES as $ability => $_) {
                $expected = collect($combo)->contains(fn ($r) => self::ABILITY_SPEC[$r] === '*' || in_array($ability, self::ABILITY_SPEC[$r], true));
                $this->assertSame($expected, Gate::forUser($u)->allows($ability), implode('+', $combo)." · {$ability}");
            }
        }
    }

    public function test_page_open_only_through_the_combination(): void
    {
        // pengurus hanya punya Laporan; kasir tidak memegang peran pengelola → impor barang (menu Barang + peran
        // admin/pengurus) hanya terbuka bila keduanya digabung. Audit (menu tak dimiliki siapa pun) tetap 403.
        app(UserService::class)->updatePermissions(Role::named('pengurus'), ['laporan']);
        $this->actingAs($this->user(['kasir']));
        $this->get('/barang/impor')->assertForbidden();
        $this->actingAs($this->user(['pengurus']));
        $this->get('/barang/impor')->assertForbidden();

        $both = $this->user(['kasir', 'pengurus']);
        $this->actingAs($both);
        $this->get('/barang/impor')->assertOk();
        $this->get('/laporan')->assertOk();
        $this->get('/kasir')->assertOk();
        $this->get('/audit')->assertForbidden();
        $this->get('/pengadaan')->assertForbidden();
        $this->assertTrue(Gate::forUser($both)->allows('kelola-katalog'));  // peran pengurus + menu barang dari kasir
        $this->assertTrue(Gate::forUser($both)->allows('setujui-retur'));   // peran pengurus + menu transaksi dari kasir
    }

    public function test_simulator_agrees_with_real_http_access_for_every_combination(): void
    {
        $preview = new AccessPreview;
        foreach (self::COMBINATIONS as $combo) {
            $sim = collect($preview->simulate($combo)['pages'])->reject(fn ($p) => str_contains($p['uri'], '{'))->pluck('allowed', 'uri');
            $this->actingAs($this->user($combo));
            foreach ($sim as $uri => $allowed) {
                $this->assertSame($allowed ? 200 : 403, $this->get($uri)->status(), implode('+', $combo)." {$uri}");
            }
        }
    }
}
