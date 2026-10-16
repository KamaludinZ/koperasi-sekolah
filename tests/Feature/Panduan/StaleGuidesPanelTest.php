<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Panduan\Index;
use App\Livewire\Panduan\Updates;
use App\Models\ActivityLog;
use App\Models\GuideEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\GuideGenerator;
use App\Services\GuideService;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Panel penanda panduan usang: daftar, perubahan draf, tandai ditinjau/perlu ditinjau (audit), catatan bagi pembaca. */
class StaleGuidesPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
        $this->actingAs($this->admin(), 'web');
    }

    private function admin(): User
    {
        return User::where('email', 'admin@koperasi.test')->first();
    }

    private function stale(string $key, string $reason = 'Halaman baru ditambahkan'): GuideEntry
    {
        $e = GuideEntry::where('key', $key)->first();
        app(GuideService::class)->markStale($e, $reason, $this->admin());

        return $e->fresh();
    }

    public function test_mark_stale_validates_and_audits(): void
    {
        $e = $this->stale('kas', '  Alur   tutup shift berubah ');
        $this->assertTrue($e->is_stale);
        $this->assertSame('Alur tutup shift berubah', $e->stale_reason);
        $this->assertNotNull($e->stale_at);
        $log = ActivityLog::where('event', 'guide.stale_marked')->first();
        $this->assertSame([$this->admin()->id, 'Alur tutup shift berubah'], [$log->actor_id, $log->reason]);
        $this->expectException(ValidationException::class);
        app(GuideService::class)->markStale($e, 'abc');
    }

    public function test_tab_lists_stale_with_pending_draft_changes(): void
    {
        GuideEntry::where('key', 'barang')->update(['title' => 'Judul lama']);
        $this->stale('barang');
        $this->stale('kas', 'Prosedur berubah');
        GuideEntry::where('key', 'pengaturan')->update(['source' => 'manual']);
        $this->stale('pengaturan', 'Kebijakan pajak baru');
        Livewire::withQueryParams(['tab' => 'usang'])->test(Updates::class)
            ->assertSeeHtml('data-stale-row="barang"')->assertSeeHtml('data-stale-row="kas"')
            ->assertSeeText('Draf terbaru akan mengubah: judul')->assertSeeHtml('data-open-draft="barang"')
            ->assertSeeText('Isi otomatis sudah sesuai sistem')->assertDontSeeHtml('data-open-draft="kas"')
            ->assertSeeText('Panduan manual — tinjau')->assertSeeHtml('data-stale-count');
    }

    public function test_mark_reviewed_and_all_reviewed_are_audited(): void
    {
        $kas = $this->stale('kas');
        $this->stale('barang');
        $this->stale('promo');
        $c = Livewire::withQueryParams(['tab' => 'usang'])->test(Updates::class)->call('markReviewed', $kas->id)
            ->assertDontSeeHtml('data-stale-row="kas"')->assertSeeHtml('data-stale-row="barang"');
        $this->assertNull($kas->fresh()->stale_at);
        $c->call('markAllReviewed')->assertSeeHtml('data-stale-empty');
        $this->assertSame(0, GuideEntry::where('is_stale', true)->count());
        $this->assertSame(3, ActivityLog::where('event', 'guide.reviewed')->count());
        $this->assertFalse(app(GuideService::class)->markReviewed($kas->fresh()));
    }

    public function test_mark_stale_form(): void
    {
        Livewire::withQueryParams(['tab' => 'usang'])->test(Updates::class)
            ->set('staleKey', 'kas')->set('staleReason', 'abc')->call('markStale')->assertHasErrors('staleReason')
            ->set('staleKey', 'tidak-ada')->set('staleReason', 'Alasan cukup panjang')->call('markStale')->assertHasErrors('staleKey')
            ->set('staleKey', 'kas')->call('markStale')->assertHasNoErrors()->assertSet('staleKey', '')
            ->assertSeeHtml('data-stale-row="kas"');
        $this->assertTrue(GuideEntry::where('key', 'kas')->value('is_stale'));
    }

    public function test_resync_clears_stale_marker(): void
    {
        GuideEntry::where('key', 'kas')->update(['title' => 'Judul lama']);
        $this->stale('kas');
        app(GuideGenerator::class)->sync();
        $e = GuideEntry::where('key', 'kas')->first();
        $this->assertFalse($e->is_stale);
        $this->assertNull($e->stale_at);
    }

    public function test_readers_see_review_note_and_managers_see_badge(): void
    {
        $this->stale('kas');
        Livewire::test(Index::class)->assertSeeHtml('data-guide-stale-badge="kas"')
            ->call('open', 'kas')->assertSeeHtml('data-guide-stale-note')->assertSeeText('Tinjau di panel pembaruan');
        $this->actingAs(User::where('email', 'kasir@koperasi.test')->first(), 'web');
        Livewire::test(Index::class)->assertDontSeeHtml('data-guide-stale-badge')
            ->call('open', 'kas')->assertSeeHtml('data-guide-stale-note')->assertDontSeeText('Tinjau di panel pembaruan');
    }

    public function test_actions_require_manager(): void
    {
        $kas = $this->stale('kas');
        $c = Livewire::test(Updates::class);
        $this->admin()->roles()->detach();
        $this->admin()->update(['role_id' => Role::named('kasir')->id]);
        $this->actingAs($this->admin()->fresh(), 'web');
        $c->call('markReviewed', $kas->id)->assertForbidden();
        $this->assertTrue($kas->fresh()->is_stale);
    }
}
