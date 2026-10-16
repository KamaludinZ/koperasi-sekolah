<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Panduan\Updates;
use App\Models\ActivityLog;
use App\Models\GuideEntry;
use App\Models\GuideVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\GuideGenerator;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Panel pembaruan panduan otomatis: akses, ringkasan, pratinjau tanpa menyimpan, terapkan + audit, panduan usang. */
class GuideUpdatesPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@koperasi.test')->first();
    }

    public function test_only_guide_managers_can_open_panel(): void
    {
        foreach (['kasir@koperasi.test', 'pengurus@koperasi.test'] as $email) {
            $this->actingAs(User::where('email', $email)->first(), 'web');
            $this->get('/panduan/pembaruan')->assertForbidden();
            $this->get('/panduan')->assertOk()->assertDontSeeHtml('data-guide-updates-link');
        }
        $this->actingAs($this->admin(), 'web');
        $this->get('/panduan')->assertOk()->assertSeeHtml('data-guide-updates-link');
        $this->get('/panduan/pembaruan')->assertOk()->assertSeeText('Pembaruan Panduan')
            ->assertSeeHtml('data-stat="total"')->assertSeeHtml('data-guide-generate-history');
        auth('web')->logout();
        $this->get('/panduan/pembaruan')->assertRedirect();
    }

    public function test_stats_reflect_entries(): void
    {
        GuideEntry::where('key', 'kas')->update(['source' => 'manual']);
        GuideEntry::where('key', 'barang')->update(['is_stale' => true, 'stale_reason' => 'Halaman Impor barang ditambahkan']);
        $this->actingAs($this->admin(), 'web');
        $s = Livewire::test(Updates::class)->instance()->stats;
        $total = GuideEntry::count();
        $this->assertSame([$total, $total - 1, 1, 0, 1], [$s['total'], $s['auto'], $s['manual'], $s['overridden'], $s['stale']]);
        Livewire::test(Updates::class)->assertSeeHtml('data-stale-item="barang"')->assertSeeText('Halaman Impor barang ditambahkan')
            ->call('markReviewed', GuideEntry::where('key', 'barang')->value('id'))
            ->assertSeeHtml('data-stale-empty');
        $this->assertFalse(GuideEntry::where('key', 'barang')->first()->is_stale);
    }

    public function test_check_previews_without_saving_then_apply_records_versions_and_audit(): void
    {
        GuideEntry::where('key', 'kas')->update(['title' => 'Judul lama', 'summary' => 'Ringkasan lama yang kedaluwarsa']);
        GuideEntry::where('key', 'promo')->delete();
        GuideEntry::where('key', 'pengaturan')->update(['source' => 'manual', 'title' => 'Pengaturan versi manual']);
        $before = GuideVersion::count();
        $this->actingAs($this->admin(), 'web');

        $c = Livewire::test(Updates::class)->call('check')
            ->assertSeeHtml('data-plan-created')->assertSeeHtml('data-plan-updated-item="kas"')->assertSeeText('berubah: judul, ringkasan')
            ->assertSeeHtml('data-plan-manual')->assertSeeText('Terapkan 2 perubahan');
        $this->assertSame('Judul lama', GuideEntry::where('key', 'kas')->value('title'));    // pratinjau tidak menyimpan
        $this->assertNull(GuideEntry::where('key', 'promo')->first());
        $this->assertSame($before, GuideVersion::count());

        $c->set('note', 'Uji panel')->call('apply')->assertSeeHtml('data-guide-update-result')->assertSet('plan', null);
        $this->assertNotSame('Judul lama', GuideEntry::where('key', 'kas')->value('title'));
        $this->assertNotNull(GuideEntry::where('key', 'promo')->first());
        $this->assertSame('Pengaturan versi manual', GuideEntry::where('key', 'pengaturan')->value('title'));
        $this->assertSame('Uji panel', GuideVersion::where('change_type', 'generate')->latest('id')->value('change_note'));
        $log = ActivityLog::where('event', 'guide.synced')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->admin()->id, $log->actor_id);
        $this->assertSame('Uji panel', $log->reason);

        Livewire::test(Updates::class)->call('check')->assertSeeHtml('data-guide-plan-empty');
    }

    public function test_plan_lists_orphaned_auto_guides(): void
    {
        GuideEntry::create(['key' => 'menu-lama', 'menu_key' => null, 'audience' => 'petugas', 'title' => 'Menu lama', 'summary' => 'Menu yang sudah dihapus dari sistem', 'steps' => [['text' => 'x']], 'tips' => [], 'related' => [], 'required_permissions' => [], 'source' => 'auto', 'position' => 99]);
        $this->assertSame(['menu-lama'], app(GuideGenerator::class)->plan()['orphaned']);
        $this->actingAs($this->admin(), 'web');
        Livewire::test(Updates::class)->call('check')->assertSeeHtml('data-plan-orphaned')->assertSeeText('Menu lama');
    }

    public function test_actions_reauthorize(): void
    {
        $this->actingAs($this->admin(), 'web');
        $c = Livewire::test(Updates::class);
        $this->admin()->roles()->detach();
        $this->admin()->update(['role_id' => Role::named('kasir')->id]);
        $this->actingAs($this->admin()->fresh(), 'web');
        $c->call('apply')->assertForbidden();
    }

    public function test_note_is_validated(): void
    {
        GuideEntry::where('key', 'kas')->update(['title' => 'Judul lama']);
        $this->actingAs($this->admin(), 'web');
        Livewire::test(Updates::class)->call('check')->set('note', str_repeat('a', 201))->call('apply')->assertHasErrors('note');
        $this->assertSame('Judul lama', GuideEntry::where('key', 'kas')->value('title'));
    }
}
