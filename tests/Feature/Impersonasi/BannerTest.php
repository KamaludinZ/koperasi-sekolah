<?php

namespace Tests\Feature\Impersonasi;

use App\Models\Member;
use App\Models\User;
use App\Support\ImpersonationSession;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BannerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
    }

    private function state(array $override = []): array
    {
        return [ImpersonationSession::KEY => [
            'id' => 'aaaaaaaa-1111-2222-3333-444444444444', 'admin_id' => $this->admin->id, 'admin_name' => $this->admin->name,
            'target_type' => 'petugas', 'target_id' => 2, 'target_name' => 'Bu Rina', 'target_label' => 'Kasir',
            'reason' => 'Membantu kasir: struk/transaksi bermasalah', 'started_at' => now()->timestamp, 'expires_at' => now()->addMinutes(30)->timestamp,
            ...$override,
        ]];
    }

    public function test_banner_shows_admin_target_reason_and_countdown_on_staff_pages(): void
    {
        $this->freezeTime(); // hitung mundur pasti 30:00 (tidak bergeser sedetik saat CI lambat)
        $this->actingAs(User::where('email', 'kasir@koperasi.test')->first())->withSession($this->state());
        $this->get('/kasir')->assertOk()
            ->assertSeeHtml('data-impersonation-banner')
            ->assertSeeText('Mode impersonasi')
            ->assertSeeText($this->admin->name.' sedang masuk sebagai Bu Rina · Kasir')
            ->assertSeeText('Alasan: Membantu kasir: struk/transaksi bermasalah')
            ->assertSeeText('semua aksi tercatat atas nama '.$this->admin->name)
            ->assertSeeText('30:00');
    }

    public function test_banner_on_portal_pages_for_member_impersonation(): void
    {
        $m = Member::factory()->create(['name' => 'Siti Portal']);
        $m->forceFill(['password' => 'rahasia-portal-1'])->save();
        $this->actingAs($m->fresh(), 'member')->withSession($this->state([
            'target_type' => 'anggota', 'target_id' => $m->id, 'target_name' => 'Siti Portal', 'target_label' => $m->member_code,
            'reason' => 'Membantu anggota yang tidak bisa memakai portal',
        ]));
        $this->get('/portal')->assertOk()
            ->assertSeeHtml('data-impersonation-banner')
            ->assertSeeText('sedang masuk sebagai Siti Portal · '.$m->member_code)
            ->assertSeeText('(portal anggota)');
    }

    public function test_no_banner_without_valid_impersonation_state(): void
    {
        $this->actingAs($this->admin);
        $this->get('/pengguna')->assertOk()->assertDontSeeHtml('data-impersonation-banner');

        // keadaan rusak / tidak lengkap tidak pernah dipercaya
        foreach ([['target_type' => 'ratu'], ['reason' => null]] as $bad) {
            $state = $this->state($bad);
            $state[ImpersonationSession::KEY] = array_filter($state[ImpersonationSession::KEY], fn ($v) => $v !== null);
            $this->withSession($state)->get('/pengguna')->assertDontSeeHtml('data-impersonation-banner');
        }
        $this->withSession([ImpersonationSession::KEY => 'bukan-array'])->get('/pengguna')->assertDontSeeHtml('data-impersonation-banner');
    }

    public function test_session_helper_remaining_and_expired(): void
    {
        $this->freezeTime();
        $this->actingAs($this->admin);
        session($this->state(['expires_at' => now()->addSeconds(90)->timestamp]));
        $this->assertTrue(ImpersonationSession::active());
        $this->assertSame(90, ImpersonationSession::remainingSeconds());
        $this->assertFalse(ImpersonationSession::expired());

        session($this->state(['expires_at' => now()->subSecond()->timestamp]));
        $this->assertSame(0, ImpersonationSession::remainingSeconds());
        $this->assertTrue(ImpersonationSession::expired());

        session()->forget(ImpersonationSession::KEY);
        $this->assertFalse(ImpersonationSession::active());
        $this->assertNull(ImpersonationSession::current());
    }

    public function test_reason_is_escaped_in_banner(): void
    {
        $this->actingAs(User::where('email', 'kasir@koperasi.test')->first())->withSession($this->state(['reason' => '<script>alert(1)</script> periksa']));
        $this->get('/kasir')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }
}
