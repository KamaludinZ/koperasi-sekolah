<?php

namespace Tests\Feature\Notifikasi;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationModelTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_inbox_follows_role_expiry_and_order(): void
    {
        Notification::factory()->create(['title' => 'Untuk kasir', 'target' => 'kasir', 'created_at' => now()->subHours(3)]);
        Notification::factory()->create(['title' => 'Untuk semua', 'target' => 'semua', 'created_at' => now()->subHour()]);
        Notification::factory()->stock()->create(['title' => 'Stok habis', 'created_at' => now()->subHours(2)]);
        Notification::factory()->create(['title' => 'Kedaluwarsa', 'expires_at' => now()->subDay()]);

        $kasir = $this->user('kasir@koperasi.test');
        $this->assertSame(['Untuk semua', 'Untuk kasir'], $kasir->inbox()->pluck('title')->all());

        $pengurus = $this->user('pengurus@koperasi.test');
        $this->assertSame(['Untuk semua', 'Stok habis'], $pengurus->inbox()->pluck('title')->all());

        $this->assertSame(['Untuk semua', 'Stok habis', 'Untuk kasir'], $this->user('admin@koperasi.test')->inbox()->pluck('title')->all());
    }

    public function test_read_state_is_tracked_per_user(): void
    {
        $n = Notification::factory()->create();
        $other = Notification::factory()->create();
        $kasir = $this->user('kasir@koperasi.test');
        $admin = $this->user('admin@koperasi.test');

        Notification::markFor($kasir, [$n->id]);

        $this->assertTrue($kasir->inbox()->find($n->id)->is_read);
        $this->assertFalse($kasir->inbox()->find($other->id)->is_read);
        $this->assertFalse($admin->inbox()->find($n->id)->is_read);
        $this->assertSame(1, $kasir->inbox()->unread($kasir)->count());
        $this->assertSame(2, $admin->inbox()->unread($admin)->count());

        Notification::markFor($kasir, [$n->id], false);
        $this->assertFalse($kasir->inbox()->find($n->id)->is_read);
        Notification::markFor($kasir, [$n->id, $n->id, 999999]); // duplikat & id tak dikenal diabaikan
        $this->assertSame(1, $kasir->notificationReads()->count());
    }

    public function test_dismiss_hides_only_for_that_user(): void
    {
        $n = Notification::factory()->create();
        $kasir = $this->user('kasir@koperasi.test');
        $admin = $this->user('admin@koperasi.test');

        Notification::dismissFor($kasir, [$n->id]);

        $this->assertNull($kasir->inbox()->find($n->id));
        $this->assertNotNull($admin->inbox()->find($n->id));
    }

    public function test_author_relations_and_labels(): void
    {
        $pengurus = $this->user('pengurus@koperasi.test');
        $n = $pengurus->authoredNotifications()->create(['type' => 'pengumuman', 'title' => 'Rapat', 'message' => 'Rapat anggota Sabtu.', 'target' => 'anggota']);

        $this->assertTrue($n->author->is($pengurus));
        $this->assertSame('Pengumuman', $n->type_label);
        $this->assertSame('Anggota (disampaikan petugas)', $n->target_label);
    }

    public function test_user_still_receives_routed_notifications(): void
    {
        $this->assertTrue(method_exists(User::class, 'notify'));
        $this->assertSame('admin@koperasi.test', $this->user('admin@koperasi.test')->routeNotificationFor('mail'));
    }
}
