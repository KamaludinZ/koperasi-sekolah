<?php

namespace Tests\Feature\Simpanan;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SimpananAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** Setiap rute menu Simpanan (termasuk rekap & setoran bulanan) wajib lewat menu:simpanan + peran:admin,pengurus. */
    public function test_every_simpanan_route_has_menu_and_role_middleware(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'simpanan'));
        $this->assertGreaterThan(10, $routes->count());
        foreach ($routes as $r) {
            $mw = $r->gatherMiddleware();
            $this->assertContains('menu:simpanan', $mw, $r->uri());
            $this->assertContains('peran:admin,pengurus', $mw, $r->uri());
        }
    }

    public function test_kasir_and_guest_blocked_on_recap_endpoints(): void
    {
        $urls = ['/simpanan/setoran-bulanan/rekap', '/simpanan/setoran-bulanan/rekap/data?jenis=2', '/simpanan/setoran-bulanan/rekap/unduh?jenis=2',
            '/simpanan/setoran-bulanan/data?bulan=2026-10&jenis=2', '/simpanan/setoran-bulanan/unduh?bulan=2026-10&jenis=2'];
        $this->loginAs('kasir');
        foreach ($urls as $u) {
            $this->get($u)->assertForbidden();
        }
        $due = \App\Models\SavingDue::create(['member_id' => \App\Models\Member::factory()->teacher()->create()->id,
            'saving_type_id' => 2, 'period' => '2026-10', 'amount' => 10000]);
        $this->post("/simpanan/setoran-bulanan/{$due->id}/koreksi", ['nominal' => 1000, 'alasan' => 'tes kasir'])->assertForbidden();
        auth()->logout();
        foreach ($urls as $u) {
            $this->get($u)->assertRedirect('/masuk');
        }
        $this->loginAs('pengurus');
        $this->get('/simpanan/setoran-bulanan/rekap/data?jenis=2')->assertOk();
    }
}
