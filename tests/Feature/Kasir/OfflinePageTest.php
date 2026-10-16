<?php

namespace Tests\Feature\Kasir;

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Halaman kasir offline, token sesi, penjaga offline di /kasir, service worker & pembersihan katalog di halaman masuk. */
class OfflinePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
    }

    public function test_offline_page_for_cashier(): void
    {
        $kasir = User::where('email', 'kasir@koperasi.test')->first();
        $this->actingAs($kasir, 'web');
        $res = $this->get('/kasir/offline')->assertOk()
            ->assertSee('x-data="offlinePos('.$kasir->id.')"', false)
            ->assertSee('name="csrf-token"', false)->assertSeeText('Kasir offline')->assertSeeText($kasir->name)
            ->assertSee('data-offline-pay', false)->assertSee('id="struk-offline"', false);
        $this->assertStringContainsString('no-cache', $res->headers->get('Cache-Control'));
        $this->getJson('/kasir/offline/sesi')->assertOk()->assertJsonStructure(['csrf', 'user_id'])->assertJsonPath('user_id', $kasir->id);
    }

    public function test_offline_routes_require_cashier_menu(): void
    {
        auth('web')->logout();
        $this->get('/kasir/offline')->assertRedirect(route('login'));
        $this->actingAs(User::where('email', 'pengurus@koperasi.test')->first(), 'web');
        $this->get('/kasir/offline')->assertForbidden();
        $this->getJson('/kasir/offline/sesi')->assertForbidden();
    }

    public function test_cashier_screen_has_offline_watcher(): void
    {
        $kasir = User::where('email', 'kasir@koperasi.test')->first();
        $this->actingAs($kasir, 'web');
        $this->get('/kasir')->assertOk()->assertSee('offlineWatch('.$kasir->id.')', false)
            ->assertSee('data-offline-banner', false)->assertSee(route('kasir.offline'), false);
    }

    public function test_service_worker_only_caches_offline_page_and_assets(): void
    {
        $sw = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString("const PAGE = '/kasir/offline';", $sw);
        $this->assertStringContainsString("'/build/assets/'", $sw);
        $this->assertStringNotContainsString('/kasir/offline/katalog', $sw);   // data katalog tidak disimpan service worker
        $this->assertStringContainsString("event.request.method !== 'GET'", $sw);
    }

    public function test_login_page_clears_offline_catalog(): void
    {
        auth('web')->logout();
        $this->get('/masuk')->assertOk()->assertSee('data-clear-offline', false);
    }
}
