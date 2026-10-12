<?php

namespace Tests\Feature\Notifikasi;

use App\Livewire\Notifikasi\Bell;
use App\Livewire\Notifikasi\Index;
use App\Support\MockData;
use Database\Seeders\NotificationSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); // admin
        $this->seed([ProductSeeder::class, NotificationSeeder::class]); // 1 habis + 3 menipis
    }

    public function test_page_lists_stock_alerts_and_announcements(): void
    {
        $this->get('/notifikasi')
            ->assertOk()
            ->assertSee('Notifikasi')
            ->assertSee('Stok Penggaris 30 cm habis')
            ->assertSee('Libur Maulid Nabi')
            ->assertSee('Tandai semua dibaca');

        $counts = Livewire::test(Index::class)->instance()->counts;
        $this->assertSame(['semua' => 9, 'belum' => 9, 'stok' => 4, 'pengumuman' => 3], $counts);
    }

    public function test_filters_and_read_state(): void
    {
        $c = Livewire::test(Index::class)->call('setFilter', 'stok');
        $this->assertSame(['stok'], array_values(array_unique(array_column($c->instance()->items, 'type'))));

        $id = $c->instance()->items[0]['id'];
        $c->call('toggleRead', $id)->assertDispatched('notifications-changed');
        $this->assertSame(8, MockData::unreadNotifications());

        $c->call('setFilter', 'belum');
        $this->assertNotContains($id, array_column($c->instance()->items, 'id'));

        $c->call('markAllRead')->assertDispatched('toast', type: 'success', message: '8 notifikasi ditandai sudah dibaca.')
            ->assertSee('Semua notifikasi sudah dibaca');
        $this->assertSame(0, MockData::unreadNotifications());

        $c->call('toggleRead', $id);
        $this->assertSame(1, MockData::unreadNotifications());
    }

    public function test_open_marks_read_and_goes_to_filtered_products(): void
    {
        $n = collect(MockData::notifications())->firstWhere('title', 'Stok Penggaris 30 cm habis');

        Livewire::test(Index::class)->call('open', $n['id'])->assertRedirect(route('barang', ['status' => 'habis']));
        $this->assertTrue(collect(MockData::notifications())->firstWhere('id', $n['id'])['is_read']);
    }

    public function test_bell_badge_and_role_targeting(): void
    {
        Livewire::test(Bell::class)->assertSee('9 belum dibaca', false)->assertDontSee('9+');
        $this->get('/kasir')->assertSee(route('notifikasi'), false);

        $this->loginAs('kasir'); // peringatan stok untuk pengurus tidak tampil
        $types = array_column(MockData::notifications(), 'target');
        $this->assertNotContains('pengurus', $types);
        $this->assertContains('kasir', $types);
    }

    public function test_requires_login(): void
    {
        auth()->logout();
        $this->get('/notifikasi')->assertRedirect(route('login'));
    }
}
