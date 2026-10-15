<?php

namespace Tests\Feature\Impersonasi;

use App\Models\Impersonation;
use App\Models\Member;
use App\Models\User;
use App\Services\ImpersonationService;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ReturnEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->kasir = User::where('email', 'kasir@koperasi.test')->first();
        $this->actingAs($this->admin);
    }

    private function startFrom(?string $referer, array $body = []): void
    {
        $headers = $referer ? ['Referer' => $referer] : [];
        $this->post(route('pengguna.impersonasi.petugas', $this->kasir), ['reason' => 'membantu kasir memeriksa struk', 'minutes' => 10, ...$body], $headers)->assertRedirect();
    }

    public function test_returns_admin_to_the_page_where_impersonation_started(): void
    {
        $this->startFrom(url('/pengguna?q=rina'));
        $this->post('/impersonasi/selesai')->assertRedirect('/pengguna?q=rina');
        $this->assertSame($this->admin->id, Auth::guard('web')->id());
    }

    public function test_member_impersonation_from_member_detail_returns_there(): void
    {
        $m = Member::factory()->create();
        $m->forceFill(['password' => 'rahasia-portal-1'])->save();
        $this->post(route('pengguna.impersonasi.anggota', $m), ['reason' => 'membantu anggota memakai portal', 'minutes' => 10], ['Referer' => url('/anggota/'.$m->id)]);
        $this->post('/impersonasi/selesai')->assertRedirect('/anggota/'.$m->id);
    }

    public function test_unsafe_or_unreachable_return_paths_fall_back_to_impersonation_list(): void
    {
        foreach (['https://evil.example/phish', '//evil.example/x', '/portal/simpanan', '/livewire/update', '/keluar', 'javascript:alert(1)'] as $bad) {
            $this->startFrom($bad);
            $this->post('/impersonasi/selesai')->assertRedirect(route('pengguna.impersonasi', absolute: false));
        }
        $this->startFrom(null, ['return_to' => '/halaman-tidak-ada']);
        $this->post('/impersonasi/selesai')->assertRedirect(route('pengguna.impersonasi', absolute: false));
    }

    public function test_safe_return_path_rules(): void
    {
        $this->assertSame('/anggota/5?tab=x', ImpersonationService::safeReturnPath(url('/anggota/5?tab=x')));
        $this->assertSame('/pengguna', ImpersonationService::safeReturnPath('/pengguna'));
        foreach (['', 'https://evil.example/a', '//evil.example', '/\\evil', '/portal', '/impersonasi/selesai', '/masuk', 'mailto:a@b.c'] as $bad) {
            $this->assertNull(ImpersonationService::safeReturnPath($bad), $bad);
        }
        $this->assertSame('/pengguna', ImpersonationService::safeReturnPath('/pengguna?'.str_repeat('a', 400)));
    }

    public function test_json_contract_and_idempotent_double_click(): void
    {
        $this->startFrom(url('/pengguna'));
        $this->postJson('/impersonasi/selesai')->assertOk()
            ->assertJson(['ok' => true, 'admin' => ['id' => $this->admin->id, 'name' => $this->admin->name], 'ended' => ['target_name' => $this->kasir->name], 'redirect' => '/pengguna']);
        $this->postJson('/impersonasi/selesai')->assertOk()->assertJson(['ok' => false, 'message' => 'Tidak ada sesi impersonasi yang aktif.']);
        $this->assertSame(1, Impersonation::whereNotNull('ended_at')->count());
        $this->assertSame($this->admin->id, Auth::guard('web')->id());
    }

    public function test_csrf_token_rotates_on_return(): void
    {
        $this->startFrom(null);
        $this->get('/kasir');
        $token = session()->token();
        $this->post('/impersonasi/selesai');
        $this->assertNotSame($token, session()->token());
    }

    public function test_end_reason_and_duration_recorded(): void
    {
        $this->freezeTime();
        $this->startFrom(null);
        $this->travel(4)->minutes();
        $this->post('/impersonasi/selesai');
        $rec = Impersonation::sole();
        $this->assertSame('manual', $rec->end_reason);
        $this->assertSame(4, $rec->durationMinutes());
    }
}
