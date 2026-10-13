<?php

namespace Tests\Feature\Audit;

use App\Livewire\Audit\Changes;
use App\Support\MockData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ChangesPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 15:00:00');
    }

    public function test_admin_and_pengurus_can_open_but_kasir_cannot(): void
    {
        $this->get('/audit/perubahan')->assertOk()->assertSee('Perubahan data')->assertSee('ATK003 · Pensil 2B');
        $this->get('/audit')->assertOk()->assertSee(route('audit.perubahan'));
        $this->loginAs('pengurus@koperasi.test');
        $this->get('/audit/perubahan')->assertOk();
        $this->loginAs('kasir@koperasi.test');
        $this->get('/audit/perubahan')->assertForbidden();
    }

    public function test_period_entity_action_and_search_filters(): void
    {
        $c = Livewire::test(Changes::class);
        $all = count(MockData::masterChanges());
        $this->assertSame($all - 1, $c->instance()->summary['total']); // satu perubahan > 30 hari

        $c->call('setPeriod', 'semua');
        $this->assertCount($all, $c->instance()->filtered);

        $c->call('setEntity', 'barang');
        $this->assertNotEmpty($c->instance()->filtered);
        $this->assertSame(['barang'], array_values(array_unique(array_column($c->instance()->filtered, 'entity'))));

        $c->call('setAction', 'dihapus');
        $this->assertSame(['dihapus'], array_values(array_unique(array_column($c->instance()->filtered, 'action'))));
        $c->call('setAction', 'dihapus'); // klik lagi = lepas
        $this->assertSame('', $c->get('action'));

        $c->set('search', 'pensil');
        $this->assertSame(['ATK003 · Pensil 2B'], array_column($c->instance()->filtered, 'subject_label'));

        $c->call('resetFilters');
        $this->assertSame('30-hari', $c->get('period'));
        $this->assertSame('', $c->get('entity'));
    }

    public function test_sensitive_filter_subject_history_and_detail(): void
    {
        $c = Livewire::test(Changes::class)->set('sensitiveOnly', true);
        foreach ($c->instance()->filtered as $r) {
            $this->assertTrue(Changes::isSensitive($r));
        }

        $c->set('sensitiveOnly', false)->call('followSubject', 'barang', 5);
        $rows = $c->instance()->filtered;
        $this->assertSame(['dipulihkan', 'dihapus'], array_column($rows, 'action'));
        $c->assertSee('Riwayat')->assertSee('ATK005 · Penggaris 30 cm');

        $id = $rows[0]['id'];
        $c->call('show', $id)->assertSee('change-detail-title', false)->assertDontSee('Data sebelum dihapus');
        $c->call('show', $rows[1]['id'])->assertSee('Data sebelum dihapus')->assertSee('Rp3.500');
        $c->call('closeDetail')->assertSet('selectedId', null);
    }

    public function test_load_more_and_value_format(): void
    {
        $c = Livewire::test(Changes::class)->call('setPeriod', 'semua');
        $this->assertSame(Changes::PER_PAGE, collect($c->instance()->days)->flatten(1)->count());
        $c->assertSee('Tampilkan lebih banyak')->call('more');
        $this->assertSame(count(MockData::masterChanges()), collect($c->instance()->days)->flatten(1)->count());

        $this->assertSame('Rp25.000', Changes::format('price', 25000));
        $this->assertSame('Ya', Changes::format('is_active', true));
        $this->assertSame('—', Changes::format('phone', null));
        $this->assertSame('7C', Changes::format('class', '7C'));
    }
}
