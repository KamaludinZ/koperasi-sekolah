<?php

namespace Tests\Feature\Sistem;

use App\Livewire\Sistem\Backups;
use App\Models\Backup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BackupsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Backup::factory()->create(['started_at' => now()->subDays(1)->setTime(23, 0)]);
        Backup::factory()->failed('Ruang penyimpanan penuh saat menulis arsip.')->create(['started_at' => now()->subDays(2)->setTime(23, 0)]);
        Backup::factory()->create(['started_at' => now()->subDays(3)->setTime(23, 0), 'trigger' => 'manual', 'created_by' => auth()->id()]);
    }

    public function test_page_in_system_nav(): void
    {
        $this->get('/sistem')->assertSee(route('sistem.cadangan'));
        $this->get('/sistem/cadangan')->assertOk()->assertSee('Cadangan terakhir berhasil')->assertSee('Jadwal berikutnya')
            ->assertSee('Cadangkan sekarang')->assertSee('Harian pukul 23:00')->assertDontSee('Data contoh');
    }

    public function test_stats_filters_and_detail(): void
    {
        $c = Livewire::test(Backups::class);
        $this->assertSame([1, 2], [$c->instance()->stats['failed_30d'], $c->instance()->stats['count']]);

        $c->call('setFilter', 'gagal');
        $this->assertSame(['gagal'], array_values(array_unique(array_column($c->instance()->rows, 'status'))));
        $c->call('setFilter', 'manual');
        $this->assertSame(['manual'], array_values(array_unique(array_column($c->instance()->rows, 'trigger'))));
        $c->call('setFilter', 'aneh')->assertSet('filter', 'semua');

        $failed = Backup::where('status', 'gagal')->sole();
        $c->call('show', $failed->id)->assertSee($failed->name)->assertSee('Ruang penyimpanan penuh')->assertDontSee('Unduh cadangan');
        $ok = Backup::succeeded()->latestFirst()->first();
        $c->call('show', $ok->id)->assertSee('Unduh cadangan')->assertSee(route('sistem.cadangan.unduh', $ok->id))->call('closeDetail')->assertSet('selectedId', null);
    }

    public function test_create_backup_runs_real_backup(): void
    {
        $c = Livewire::test(Backups::class)->call('openCreate')->assertSet('creating', true)
            ->set('newType', 'aneh')->call('create')->assertHasErrors('newType')
            ->set('newType', 'basis data')->set('newNote', 'Sebelum tutup buku')->call('create')->assertHasNoErrors()
            ->assertSet('creating', false)->assertDispatched('toast', type: 'success');
        $b = Backup::latest('id')->first();
        $this->assertSame(['berhasil', 'manual', 'basis data', 'Sebelum tutup buku', auth()->id()], [$b->status, $b->trigger, $b->type, $b->note, $b->created_by]);
        $this->assertSame($b->id, $c->instance()->rows[0]['id']);
    }

    public function test_admin_only(): void
    {
        $this->loginAs('pengurus@koperasi.test');
        $this->get('/sistem/cadangan')->assertForbidden();
    }
}
