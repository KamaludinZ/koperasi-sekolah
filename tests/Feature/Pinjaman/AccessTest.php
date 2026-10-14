<?php

namespace Tests\Feature\Pinjaman;

use App\Models\Loan;
use App\Models\Member;
use App\Models\Role;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Konsol pinjaman hanya untuk Pengurus (dan Admin); kasir, tamu, dan portal anggota tertolak di semua rute pinjaman. */
class AccessTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> URL GET semua rute bernama pinjaman* (parameter diisi pinjaman nyata). */
    private function urls(Loan $loan): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'pinjaman') && in_array('GET', $r->methods(), true))
            ->map(fn ($r) => route($r->getName(), in_array('loan', $r->parameterNames(), true) ? ['loan' => $loan->id] : []))
            ->values()->all();
    }

    public function test_route_sweep_by_role(): void
    {
        $loan = Loan::factory()->create();
        $urls = $this->urls($loan);
        $this->assertGreaterThanOrEqual(4, count($urls));

        foreach (['admin', 'pengurus'] as $role) {
            $this->loginAs($role);
            foreach ($urls as $url) {
                $this->get($url)->assertOk();
            }
        }

        $this->loginAs('kasir');
        foreach ($urls as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/kasir')->assertOk()->assertDontSee(route('pinjaman'), false); // menu tidak tampil

        auth()->logout();
        foreach ($urls as $url) {
            $this->getJson($url)->assertUnauthorized();
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_portal_member_session_does_not_open_staff_console(): void
    {
        $loan = Loan::factory()->create();
        auth()->logout();
        $member = Member::factory()->teacher()->create();
        $this->actingAs($member, 'member');

        foreach ($this->urls($loan) as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }

    public function test_revoking_menu_from_pengurus_blocks_pages_and_livewire_actions(): void
    {
        $pengurus = $this->loginAs('pengurus');
        $html = $this->get('/pinjaman')->assertOk()->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $m);
        $snapshot = collect($m[1])->map(fn ($raw) => html_entity_decode($raw, ENT_QUOTES))
            ->first(fn ($json) => array_key_exists('status', json_decode($json, true)['data'] ?? [])); // komponen halaman konsol (punya filter status)
        $this->assertNotNull($snapshot);
        $update = fn () => $this->withHeader('X-Livewire', '1')->postJson(app('livewire')->getUpdateUri(),
            ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => 'filterStatus', 'params' => ['lunas']]]]]]);

        $update()->assertOk(); // aksi Livewire nyata (POST /livewire/update) masih diizinkan

        app(UserService::class)->updatePermissions(Role::named('pengurus'), array_values(array_diff(Role::named('pengurus')->permissions, ['pinjaman'])));
        $this->actingAs($pengurus->fresh());

        $this->get('/pinjaman')->assertForbidden();
        $this->getJson('/pinjaman/daftar')->assertForbidden();
        $update()->assertForbidden(); // middleware rute asal ikut dicek ulang pada aksi Livewire
    }

    public function test_kasir_cannot_be_granted_loan_menu(): void
    {
        $this->expectException(ValidationException::class);
        app(UserService::class)->updatePermissions(Role::named('kasir'), [...Role::named('kasir')->permissions, 'pinjaman']);
    }
}
