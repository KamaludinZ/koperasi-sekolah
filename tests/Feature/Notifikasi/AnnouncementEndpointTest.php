<?php

namespace Tests\Feature\Notifikasi;

use App\Models\ActivityLog;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $loginAs = 'pengurus';

    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Pembagian buku tabungan',
            'message' => 'Buku tabungan baru dibagikan di koperasi mulai Senin depan.',
            'target' => 'anggota',
            'show_public' => true,
            'expires_at' => now()->addWeek()->toDateString(),
            ...$overrides,
        ];
    }

    public function test_pengurus_publishes_announcement_for_members(): void
    {
        $res = $this->postJson('/notifikasi/pengumuman', $this->payload(['title' => '  Pembagian buku tabungan  ']))
            ->assertCreated()
            ->assertJsonPath('data.title', 'Pembagian buku tabungan')
            ->assertJsonPath('data.target', 'anggota')
            ->assertJsonPath('data.show_public', true)
            ->assertJsonPath('data.author', 'Pak Dedi')
            ->assertJsonPath('data.is_active', true);

        $id = $res->json('data.id');
        $this->assertSame('pengumuman', Notification::find($id)->type);

        // Penulis tidak mendapat badge untuk tulisannya sendiri; petugas lain ya.
        $this->getJson('/notifikasi/terbaru')->assertJsonPath('meta.unread', 0);
        $this->loginAs('kasir');
        $this->getJson('/notifikasi/terbaru')->assertJsonPath('meta.unread', 1)->assertJsonPath('data.0.title', 'Pembagian buku tabungan');

        // Tampil di beranda publik tanpa login.
        auth()->logout();
        $this->get('/')->assertSee('Pembagian buku tabungan');

        $log = ActivityLog::where('event', 'announcement.created')->first();
        $this->assertSame('pengumuman', $log->category);
        $this->assertSame('Pak Dedi', $log->actor_name);
    }

    public function test_staff_only_announcement_is_never_public(): void
    {
        $this->postJson('/notifikasi/pengumuman', $this->payload(['target' => 'kasir', 'show_public' => true]))
            ->assertCreated()->assertJsonPath('data.show_public', false);

        auth()->logout();
        $this->get('/')->assertDontSee('Pembagian buku tabungan');
    }

    public function test_validation(): void
    {
        $this->postJson('/notifikasi/pengumuman', ['title' => 'abc', 'message' => 'pendek', 'target' => 'tamu', 'expires_at' => now()->subDay()->toDateString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'title' => 'Judul minimal 5 karakter.',
                'message' => 'Isi minimal 10 karakter.',
                'target' => 'Pilih sasaran pengumuman.',
                'expires_at' => 'Tanggal berakhir tidak boleh sebelum hari ini.',
            ]);
        $this->postJson('/notifikasi/pengumuman', $this->payload(['message' => str_repeat('a', 501)]))->assertJsonValidationErrors('message');
    }

    public function test_list_and_update_own_announcement(): void
    {
        $id = $this->postJson('/notifikasi/pengumuman', $this->payload())->json('data.id');

        $this->getJson('/notifikasi/pengumuman/data')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonStructure(['meta' => ['targets', 'public_targets']]);

        $this->putJson("/notifikasi/pengumuman/{$id}", $this->payload(['title' => 'Jadwal pembagian buku', 'show_public' => false]))
            ->assertOk()->assertJsonPath('data.title', 'Jadwal pembagian buku')->assertJsonPath('data.show_public', false);

        $log = ActivityLog::where('event', 'announcement.updated')->first();
        $this->assertSame(['from' => 'Pembagian buku tabungan', 'to' => 'Jadwal pembagian buku'], $log->properties['changes']['title']);
    }

    public function test_pengurus_cannot_change_others_but_admin_can(): void
    {
        $this->loginAs('admin');
        $id = $this->postJson('/notifikasi/pengumuman', $this->payload())->json('data.id');

        $this->loginAs('pengurus');
        $this->putJson("/notifikasi/pengumuman/{$id}", $this->payload(['title' => 'Diubah pengurus']))->assertForbidden();
        $this->deleteJson("/notifikasi/pengumuman/{$id}")->assertForbidden();

        $own = $this->postJson('/notifikasi/pengumuman', $this->payload(['title' => 'Milik pengurus']))->json('data.id');
        $this->loginAs('admin');
        $this->deleteJson("/notifikasi/pengumuman/{$own}")->assertOk()->assertJsonPath('data.deleted', true);
        $this->assertNull(Notification::find($own));
        $this->assertSame(1, ActivityLog::where('event', 'announcement.deleted')->count());
    }

    public function test_only_announcements_can_be_managed(): void
    {
        $stock = Notification::factory()->stock()->create();

        $this->putJson("/notifikasi/pengumuman/{$stock->id}", $this->payload())->assertNotFound();
        $this->deleteJson("/notifikasi/pengumuman/{$stock->id}")->assertNotFound();
    }

    public function test_kasir_and_guest_cannot_publish(): void
    {
        $this->loginAs('kasir');
        $this->postJson('/notifikasi/pengumuman', $this->payload())->assertForbidden();
        $this->getJson('/notifikasi/pengumuman/data')->assertForbidden();

        auth()->logout();
        $this->postJson('/notifikasi/pengumuman', $this->payload())->assertUnauthorized();
        $this->assertSame(0, Notification::count());
    }
}
