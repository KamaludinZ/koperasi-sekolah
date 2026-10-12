<?php

namespace Tests\Feature\Notifikasi;

use App\Livewire\Notifikasi\Bell;
use App\Support\MockData;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); // admin, 9 notifikasi
        $this->seed(ProductSeeder::class);
    }

    public function test_badge_and_preview(): void
    {
        Livewire::test(Bell::class)
            ->assertSee('9 belum dibaca', false)
            ->assertSee('9 baru')
            ->assertSee('Stok Penggaris 30 cm habis')
            ->assertSee('Lihat semua notifikasi')
            ->assertSee('wire:poll.60s', false);
    }

    public function test_badge_caps_at_nine_plus(): void
    {
        session(['mock.notifications.read' => []]);
        \App\Models\Product::where('code', 'ATK001')->update(['stock' => 0]);
        \App\Models\Product::where('code', 'MNM001')->update(['stock' => 0]);

        Livewire::test(Bell::class)->assertSee('9+');
    }

    public function test_mark_read_and_all(): void
    {
        $first = MockData::notifications()[0]['id'];

        Livewire::test(Bell::class)
            ->call('markRead', $first)
            ->assertDispatched('notifications-changed')
            ->assertSee('8 belum dibaca', false)
            ->call('markAllRead')
            ->assertDontSee('belum dibaca', false)
            ->assertDontSee('Tandai semua dibaca');
        $this->assertSame(0, MockData::unreadNotifications());
    }

    public function test_open_follows_link_and_marks_read(): void
    {
        $stock = collect(MockData::notifications())->firstWhere('type', 'stok');
        $note = collect(MockData::notifications())->firstWhere('type', 'pengumuman');

        Livewire::test(Bell::class)->call('open', $stock['id'])->assertRedirect($stock['url']);
        Livewire::test(Bell::class)->call('open', $note['id'])->assertRedirect(route('notifikasi'));
        $this->assertSame(7, MockData::unreadNotifications());
    }

    public function test_refreshes_when_page_marks_all(): void
    {
        $bell = Livewire::test(Bell::class)->assertSee('9 belum dibaca', false);
        MockData::markNotifications(array_column(MockData::notifications(), 'id'));

        $bell->dispatch('notifications-changed')->assertDontSee('belum dibaca', false);
    }
}
