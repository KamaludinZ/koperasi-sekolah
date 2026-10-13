<?php

namespace Tests\Feature\Sistem;

use App\Livewire\Sistem\Backups;
use App\Support\MockData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BackupsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_in_system_nav(): void
    {
        $this->get('/sistem')->assertSee(route('sistem.cadangan'));
        $this->get('/sistem/cadangan')->assertOk()->assertSee('Cadangan terakhir berhasil')->assertSee('Jadwal berikutnya')
            ->assertSee('Cadangkan sekarang')->assertSee('Harian pukul 23:00');
    }

    public function test_stats_filters_and_detail(): void
    {
        $all = MockData::backups();
        $c = Livewire::test(Backups::class);
        $this->assertSame(1, $c->instance()->stats['failed_30d']);
        $this->assertSame(count($all) - 1, $c->instance()->stats['count']);
        $this->assertSame('manual', $c->instance()->stats['last']['trigger']);

        $c->call('setFilter', 'gagal');
        $this->assertSame(['gagal'], array_values(array_unique(array_column($c->instance()->rows, 'status'))));
        $c->call('setFilter', 'manual');
        $this->assertSame(['manual'], array_values(array_unique(array_column($c->instance()->rows, 'trigger'))));
        $c->call('setFilter', 'aneh')->assertSet('filter', 'semua');

        $failed = collect($all)->firstWhere('status', 'gagal');
        $c->call('show', $failed['id'])->assertSee($failed['name'])->assertSee('Ruang penyimpanan penuh')->assertDontSee('Unduh cadangan');
        $c->call('show', $all[0]['id'])->assertSee('Unduh cadangan')->call('closeDetail')->assertSet('selectedId', null);
    }

    public function test_create_backup_simulated(): void
    {
        $c = Livewire::test(Backups::class)->call('openCreate')->assertSet('creating', true)
            ->set('newType', 'aneh')->call('create')->assertHasErrors('newType')
            ->set('newType', 'basis data')->set('newNote', 'Sebelum tutup buku')->call('create')->assertHasNoErrors()
            ->assertSet('creating', false)->assertDispatched('toast');
        $first = $c->instance()->rows[0];
        $this->assertSame(['manual', 'basis data', 'Sebelum tutup buku', 'Admin Koperasi'], [$first['trigger'], $first['type'], $first['note'], $first['by']]);
    }

    public function test_admin_only(): void
    {
        $this->loginAs('pengurus@koperasi.test');
        $this->get('/sistem/cadangan')->assertForbidden();
    }
}
