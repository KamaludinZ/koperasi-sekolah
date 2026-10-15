<?php

namespace Tests\Feature\Impersonasi;

use App\Livewire\Pengguna\Impersonation as ImpersonationPage;
use App\Models\Impersonation;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DialogAuditContextTest extends TestCase
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

    private function past(User $admin, string $reason, int $daysAgo, bool $ended = true): Impersonation
    {
        return Impersonation::create(['admin_id' => $admin->id, 'admin_name' => $admin->name, 'target_type' => 'petugas',
            'target_user_id' => $this->kasir->id, 'target_name' => $this->kasir->name, 'reason' => $reason,
            'started_at' => now()->subDays($daysAgo), 'expires_at' => now()->subDays($daysAgo)->addMinutes(30),
            'ended_at' => $ended ? now()->subDays($daysAgo)->addMinutes(5) : null, 'end_reason' => $ended ? 'manual' : null]);
    }

    public function test_dialog_shows_recent_impersonation_history_for_the_target(): void
    {
        $other = User::factory()->admin()->create(['name' => 'Admin Kedua']);
        $this->past($other, 'memeriksa struk ganda kemarin', 1);
        $this->past($this->admin, 'sesi paling lama sekali', 9);
        $this->past($this->admin, 'sesi lama dua', 5);
        $this->past($this->admin, 'sesi lama tiga', 3);

        Livewire::test(ImpersonationPage::class)->call('confirm', 'petugas', $this->kasir->id)
            ->assertSeeHtml('data-target-history')
            ->assertSeeText('Admin Kedua')
            ->assertSeeText('memeriksa struk ganda kemarin')
            ->assertSeeText('5 menit')
            ->assertDontSeeText('sesi paling lama sekali'); // hanya 3 terbaru
    }

    public function test_live_session_by_another_admin_is_flagged(): void
    {
        $other = User::factory()->admin()->create(['name' => 'Admin Kedua']);
        Impersonation::create(['admin_id' => $other->id, 'admin_name' => $other->name, 'target_type' => 'petugas',
            'target_user_id' => $this->kasir->id, 'target_name' => $this->kasir->name, 'reason' => 'sedang membantu sekarang',
            'started_at' => now()->subMinutes(2), 'expires_at' => now()->addMinutes(20)]);
        Livewire::test(ImpersonationPage::class)->call('confirm', 'petugas', $this->kasir->id)->assertSeeHtml('data-history-active');
    }

    public function test_no_history_block_for_never_impersonated_account_and_audit_note_links(): void
    {
        Livewire::test(ImpersonationPage::class)->call('confirm', 'petugas', $this->kasir->id)
            ->assertDontSeeHtml('data-target-history')
            ->assertSeeHtml('data-reason-audit-note')
            ->assertSeeHtml('href="'.route('audit.peran', ['tampil' => 'impersonasi']).'"');
    }

    public function test_history_is_per_target_type(): void
    {
        $m = Member::factory()->create();
        $m->forceFill(['password' => 'rahasia-portal-1'])->save();
        // id anggota bisa sama dengan id petugas; riwayat petugas tidak boleh tampil di dialog anggota
        $this->past($this->admin, 'riwayat milik petugas', 1);
        Livewire::test(ImpersonationPage::class)->set('tab', 'anggota')->call('confirm', 'anggota', $m->id)->assertDontSeeText('riwayat milik petugas');
    }
}
