<?php

namespace Tests\Feature\Panduan;

use App\Models\GuideChange;
use App\Models\GuideEntry;
use App\Models\User;
use App\Services\GuideService;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** API riwayat perubahan panduan untuk audit: daftar & saringan, rincian, ekspor CSV aman, akses. */
class GuideHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $kas = GuideEntry::where('key', 'kas')->first();
        app(GuideService::class)->setOverride($kas, 'summary', '=HYPERLINK("http://jahat") ringkasan', $this->admin, 'Uji formula');
        app(GuideService::class)->setOverride($kas, 'tips', ['Tips satu', 'Tips dua'], $this->admin);
        app(GuideService::class)->markStale(GuideEntry::where('key', 'barang')->first(), 'Halaman baru ditambahkan', $this->admin);
    }

    public function test_list_filters_and_pagination(): void
    {
        $this->actingAs($this->admin, 'web');
        $res = $this->getJson('/panduan/riwayat?per=5')->assertOk();
        $this->assertSame(GuideChange::count(), $res->json('meta.total'));
        $this->assertSame('stale', $res->json('data.0.action'));                                   // terbaru dulu
        $over = $this->getJson('/panduan/riwayat?aksi=override')->json('data');
        $this->assertSame(['tips', 'summary'], array_column($over, 'field'));
        $this->assertSame([$this->admin->name, 'Disunting manual', 'Tips'], [$over[0]['by'], $over[0]['action_label'], $over[0]['field_label']]);
        $this->assertSame('Tips satu | Tips dua', $over[0]['after_preview']);
        $this->assertSame(1, $this->getJson('/panduan/riwayat?panduan=barang&aksi=stale')->json('meta.total'));
        $this->assertSame(1, $this->getJson('/panduan/riwayat?bagian=summary')->json('meta.total'));
        $this->assertSame(3, $this->getJson('/panduan/riwayat?oleh='.$this->admin->id)->json('meta.total'));
        $this->assertSame(0, $this->getJson('/panduan/riwayat?dari=2099-01-01')->json('meta.total'));
        $this->getJson('/panduan/riwayat?aksi=hapus')->assertUnprocessable();
        $this->getJson('/panduan/riwayat?dari=2026-10-10&sampai=2026-10-01')->assertUnprocessable();
        $this->getJson('/panduan/riwayat?panduan=../x')->assertUnprocessable();
    }

    public function test_detail_has_full_values(): void
    {
        $this->actingAs($this->admin, 'web');
        $id = GuideChange::where('field', 'tips')->value('id');
        $this->getJson('/panduan/riwayat/'.$id)->assertOk()->assertJsonPath('data.after', ['Tips satu', 'Tips dua'])
            ->assertJsonPath('data.guide.key', 'kas')->assertJsonPath('data.after_lines', ['Tips satu', 'Tips dua']);
        $this->getJson('/panduan/riwayat/999999')->assertNotFound();
    }

    public function test_csv_export_neutralizes_formulas(): void
    {
        $this->actingAs($this->admin, 'web');
        $res = $this->get('/panduan/riwayat/ekspor?aksi=override')->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('Content-Type'));
        $csv = $res->streamedContent();
        $this->assertStringContainsString('Waktu;Panduan;Kunci;Bagian;Perubahan', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(';=HYPERLINK', $csv);
        $this->assertStringContainsString('Uji formula', $csv);
    }

    public function test_access_for_managers_and_audit_holders_only(): void
    {
        $this->actingAs(User::where('email', 'pengurus@koperasi.test')->first(), 'web');   // pengurus punya menu Audit
        $this->getJson('/panduan/riwayat')->assertOk();
        $this->get('/panduan/riwayat/ekspor')->assertOk();
        $this->actingAs(User::where('email', 'kasir@koperasi.test')->first(), 'web');
        $this->getJson('/panduan/riwayat')->assertForbidden();
        $this->getJson('/panduan/riwayat/1')->assertForbidden();
        $this->get('/panduan/riwayat/ekspor')->assertForbidden();
    }
}
