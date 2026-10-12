<?php

namespace Tests\Feature\Notifikasi;

use App\Models\Notification;
use Database\Seeders\NotificationSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkReadEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); // admin, 9 notifikasi
        $this->seed([ProductSeeder::class, NotificationSeeder::class]);
    }

    private function id(string $title): int
    {
        return Notification::where('title', $title)->value('id');
    }

    public function test_mark_read_and_unread(): void
    {
        $ids = [$this->id('Libur Maulid Nabi'), $this->id('Seragam baru datang')];

        $this->postJson('/notifikasi/tandai', ['ids' => $ids])
            ->assertOk()->assertJsonPath('data.read', true)->assertJsonPath('meta.unread', 7);
        $this->assertSame(7, $this->getJson('/notifikasi/terbaru')->json('meta.unread'));

        $this->postJson('/notifikasi/tandai', ['ids' => [$ids[0]], 'dibaca' => false])
            ->assertOk()->assertJsonPath('data.ids', [$ids[0]])->assertJsonPath('meta.unread', 8);
    }

    public function test_mark_all_with_optional_filter(): void
    {
        $this->postJson('/notifikasi/tandai-semua', ['tampil' => 'stok'])->assertOk()->assertJsonCount(4, 'data.ids')->assertJsonPath('meta.unread', 5);
        $this->postJson('/notifikasi/tandai-semua')->assertOk()->assertJsonCount(5, 'data.ids')->assertJsonPath('meta.unread', 0);
        $this->postJson('/notifikasi/tandai-semua')->assertOk()->assertJsonCount(0, 'data.ids');
        $this->postJson('/notifikasi/tandai-semua', ['tampil' => 'belum'])->assertJsonValidationErrors('tampil');
    }

    public function test_ids_outside_own_inbox_are_ignored(): void
    {
        $this->loginAs('kasir');
        $stock = Notification::where('type', 'stok')->value('id'); // untuk pengurus

        $this->postJson('/notifikasi/tandai', ['ids' => [$stock, 999999]])->assertOk()->assertJsonPath('data.ids', []);
        $this->assertDatabaseCount('notification_reads', 0);
    }

    public function test_validation(): void
    {
        $this->postJson('/notifikasi/tandai', [])->assertJsonValidationErrors(['ids' => 'Pilih notifikasi yang akan ditandai.']);
        $this->postJson('/notifikasi/tandai', ['ids' => ['abc']])->assertJsonValidationErrors('ids.0');
        $this->postJson('/notifikasi/tandai', ['ids' => range(1, 201)])->assertJsonValidationErrors('ids');
    }

    public function test_dismiss_hides_for_self_but_not_stock_alerts(): void
    {
        $libur = $this->id('Libur Maulid Nabi');
        $stock = Notification::where('type', 'stok')->value('id');

        $this->postJson('/notifikasi/sembunyikan', ['ids' => [$libur, $stock]])->assertOk()->assertJsonPath('data.ids', [$libur]);

        $titles = array_column($this->getJson('/notifikasi/data')->json('data'), 'title');
        $this->assertNotContains('Libur Maulid Nabi', $titles);
        $this->assertCount(8, $titles);

        $this->loginAs('kasir');
        $this->assertContains('Libur Maulid Nabi', array_column($this->getJson('/notifikasi/data')->json('data'), 'title'));
    }

    public function test_requires_login_and_csrf_protected_routes(): void
    {
        auth()->logout();
        $this->postJson('/notifikasi/tandai', ['ids' => [1]])->assertUnauthorized();
        $this->postJson('/notifikasi/tandai-semua')->assertUnauthorized();
    }
}
