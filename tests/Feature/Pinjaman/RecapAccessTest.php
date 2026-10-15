<?php

namespace Tests\Feature\Pinjaman;

use App\Livewire\Pinjaman\Recap;
use App\Models\Member;
use App\Models\ReportExport;
use App\Models\Role;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Rekap & ekspor pinjaman hanya untuk pengurus (dan admin) dengan menu Pinjaman aktif. */
class RecapAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{0: string, 1: string}> [metode, url] semua rute rekap (parameter export diisi) */
    private function routes(ReportExport $export): array
    {
        return collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'pinjaman.rekap'))
            ->map(fn ($r) => [in_array('GET', $r->methods(), true) ? 'GET' : 'POST', route($r->getName(), in_array('export', $r->parameterNames(), true) ? ['export' => $export->id] : [])])
            ->values()->all();
    }

    private function export(int $userId): ReportExport
    {
        Storage::fake('local');
        Storage::disk('local')->put('exports/pinjaman/x.csv', 'a;b');

        return ReportExport::create(['user_id' => $userId, 'type' => 'pinjaman_rekap', 'format' => 'csv', 'status' => 'done', 'file_path' => 'exports/pinjaman/x.csv',
            'file_name' => 'rekap.csv', 'params' => ['from' => '2026-10-01', 'to' => '2026-10-15']]);
    }

    public function test_every_recap_route_carries_the_gate(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'pinjaman.rekap'));
        $this->assertGreaterThanOrEqual(8, $routes->count());
        foreach ($routes as $r) {
            $this->assertContains('can:lihat-rekap-pinjaman', $r->gatherMiddleware(), $r->getName());
        }
    }

    public function test_pengurus_and_admin_allowed_kasir_and_guests_denied(): void
    {
        foreach (['pengurus', 'admin'] as $role) {
            $user = $this->loginAs($role);
            $this->assertTrue(Gate::allows('lihat-rekap-pinjaman'), $role);
            foreach ($this->routes($this->export($user->id)) as [$method, $url]) {
                $res = $method === 'GET' ? $this->getJson($url) : $this->postJson($url, ['bulan' => '2026-10']);
                $this->assertContains($res->baseResponse->getStatusCode(), [200, 202], "{$role} {$method} {$url}");
            }
            $this->get(route('pinjaman'))->assertSee(route('pinjaman.rekap'), false);
        }

        $kasir = $this->loginAs('kasir');
        $this->assertFalse(Gate::allows('lihat-rekap-pinjaman'));
        foreach ($this->routes($this->export($kasir->id)) as [$method, $url]) {
            ($method === 'GET' ? $this->getJson($url) : $this->postJson($url, []))->assertForbidden();
        }

        auth()->logout();
        foreach ($this->routes($this->export($kasir->id)) as [$method, $url]) {
            ($method === 'GET' ? $this->getJson($url) : $this->postJson($url, []))->assertUnauthorized();
        }
    }

    public function test_portal_member_session_cannot_open_recap(): void
    {
        $owner = $this->loginAs('pengurus');
        auth()->logout();
        $this->actingAs(Member::factory()->teacher()->create(), 'member');
        foreach ($this->routes($this->export($owner->id)) as [$method, $url]) {
            ($method === 'GET' ? $this->getJson($url) : $this->postJson($url, []))->assertUnauthorized();
        }
    }

    public function test_revoking_loan_menu_blocks_recap_downloads_and_livewire_export(): void
    {
        $pengurus = $this->loginAs('pengurus');
        $export = $this->export($pengurus->id);
        $component = Livewire::test(Recap::class)->assertOk();

        app(UserService::class)->updatePermissions(Role::named('pengurus'), array_values(array_diff(Role::named('pengurus')->permissions, ['pinjaman'])));
        $pengurus->refresh()->load('role');
        $this->actingAs($pengurus);

        $this->assertFalse(Gate::allows('lihat-rekap-pinjaman'));
        $this->get(route('pinjaman.rekap'))->assertForbidden();
        $this->get(route('pinjaman.rekap.ekspor.unduh', $export))->assertForbidden();
        $component->call('export', 'csv')->assertForbidden();
        $this->assertSame(1, ReportExport::count());
        $this->assertSame(0, $export->fresh()->download_count);
    }
}
