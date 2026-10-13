<?php

namespace Tests\Feature\Audit;

use App\Livewire\Audit\Trash;
use App\Support\MockData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TrashPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 15:00:00');
    }

    private function idOf(string $label): int
    {
        return collect(MockData::trashed())->firstWhere('label', $label)['id'];
    }

    public function test_admin_and_pengurus_can_open_but_kasir_cannot(): void
    {
        $this->get('/audit/terhapus')->assertOk()->assertSee('Data terhapus')->assertSee('MKN004 · Wafer Stroberi')->assertSee('Pulihkan');
        $this->get('/audit')->assertSee(route('audit.terhapus'));
        $this->loginAs('pengurus@koperasi.test');
        $this->get('/audit/terhapus')->assertOk()->assertSee('Pemulihan hanya dapat dilakukan Admin');
        $this->loginAs('kasir@koperasi.test');
        $this->get('/audit/terhapus')->assertForbidden();
    }

    public function test_summary_filter_and_retention(): void
    {
        $c = Livewire::test(Trash::class);
        $s = $c->instance()->summary;
        $this->assertSame(8, $s['total']);
        $this->assertSame(['barang' => 4, 'kategori' => 1, 'anggota' => 3], $s['by_entity']);
        $this->assertSame(2, $s['purge_soon']); // dihapus 25 & 27 hari lalu
        $this->assertSame(1, $s['conflicts']);

        $row = collect(MockData::trashed())->firstWhere('label', 'SRG004 · Bet Lokasi');
        $this->assertSame(3, Trash::daysLeft($row));

        $c->call('setEntity', 'anggota');
        $this->assertSame(['anggota'], array_values(array_unique(array_column($c->instance()->items, 'entity'))));
        $c->call('setEntity', '')->set('search', 'wafer');
        $this->assertSame(['MKN004 · Wafer Stroberi'], array_column($c->instance()->items, 'label'));
    }

    public function test_admin_restores_one_after_confirmation(): void
    {
        $id = $this->idOf('MKN004 · Wafer Stroberi');
        $c = Livewire::test(Trash::class)
            ->call('confirmRestore', $id)->assertSet('confirming', [$id])->assertSee('Pulihkan data ini?')
            ->call('cancelRestore')->assertSet('confirming', [])
            ->call('confirmRestore', $id)->call('restore')
            ->assertDispatched('toast', type: 'success', message: 'MKN004 · Wafer Stroberi dipulihkan.');

        $this->assertNotContains($id, array_column($c->instance()->items, 'id'));
        $this->assertSame(7, $c->instance()->summary['total']);
    }

    public function test_bulk_restore_skips_conflicts(): void
    {
        $conflict = $this->idOf('ATK006 · Spidol Papan Tulis');
        $c = Livewire::test(Trash::class)->call('toggleAll');
        $this->assertCount(7, $c->get('selected'));
        $this->assertNotContains($conflict, $c->get('selected'));

        $c->call('confirmRestore')->assertSee('Pulihkan 7 data?')->call('restore')
            ->assertDispatched('toast', type: 'success', message: '7 data dipulihkan.')
            ->assertSet('selected', []);
        $this->assertSame([$conflict], array_column($c->instance()->items, 'id'));

        // Data yang bentrok ditolak beserta alasannya.
        $c->call('confirmRestore', $conflict)->call('restore')
            ->assertDispatched('toast', type: 'error');
        $this->assertCount(1, MockData::trashed());
    }

    public function test_pengurus_cannot_restore(): void
    {
        $this->loginAs('pengurus@koperasi.test');
        $id = $this->idOf('MKN004 · Wafer Stroberi');
        Livewire::test(Trash::class)
            ->assertDontSee('Pulihkan terpilih')
            ->call('confirmRestore', $id)->assertSet('confirming', [])
            ->assertDispatched('toast', type: 'error')
            ->set('confirming', [$id])->call('restore');
        $this->assertCount(8, MockData::trashed());
    }
}
