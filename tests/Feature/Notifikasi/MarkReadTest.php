<?php

namespace Tests\Feature\Notifikasi;

use App\Livewire\Notifikasi\Index;
use App\Services\NotificationService;
use App\Support\MockData;
use Database\Seeders\NotificationSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MarkReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); // admin, 9 notifikasi belum dibaca
        $this->seed([ProductSeeder::class, NotificationSeeder::class]);
    }

    public function test_bulk_mark_selected_read_and_unread(): void
    {
        $ids = array_slice(array_column(MockData::notifications(), 'id'), 0, 3);

        $c = Livewire::test(Index::class)
            ->set('selected', $ids)
            ->assertSee('3 dipilih')
            ->call('markSelected', true)
            ->assertSet('selected', [])
            ->assertDispatched('toast', type: 'success', message: '3 notifikasi ditandai sudah dibaca.')
            ->assertDispatched('notifications-changed');
        $this->assertSame(6, app(NotificationService::class)->unreadCount(auth()->user()));

        $c->set('selected', [$ids[0], 99999])->call('markSelected', false); // id asing diabaikan
        $this->assertSame(7, app(NotificationService::class)->unreadCount(auth()->user()));
    }

    public function test_select_all_toggles_visible_items(): void
    {
        $c = Livewire::test(Index::class)->call('setFilter', 'stok')->call('toggleSelectAll');
        $this->assertCount(4, $c->get('selected'));

        $c->call('toggleSelectAll')->assertSet('selected', []);
        $c->call('toggleSelectAll')->call('setFilter', 'semua')->assertSet('selected', []); // ganti saringan mengosongkan pilihan
    }

    public function test_mark_all_read_can_be_undone(): void
    {
        $c = Livewire::test(Index::class)->call('markAllRead');
        $this->assertSame(0, app(NotificationService::class)->unreadCount(auth()->user()));

        $toast = collect($c->effects['dispatches'])->firstWhere('name', 'toast')['params'];
        $this->assertSame('Urungkan', $toast['action']['label']);
        $this->assertSame('undo-mark-read', $toast['action']['event']);

        $c->dispatch('undo-mark-read', ids: $toast['action']['params']['ids']);
        $this->assertSame(9, app(NotificationService::class)->unreadCount(auth()->user()));
    }

    public function test_noop_without_selection(): void
    {
        Livewire::test(Index::class)->call('markSelected', true)->assertNotDispatched('toast');
        $this->assertSame(9, app(NotificationService::class)->unreadCount(auth()->user()));
    }
}
