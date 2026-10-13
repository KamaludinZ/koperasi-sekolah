<?php

namespace Tests\Feature\Notifikasi;

use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicAnnouncementsPageTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $loginAs = null;

    private function announce(string $title, bool $public = true, ?string $expires = null): void
    {
        Notification::create(['type' => 'pengumuman', 'title' => $title, 'message' => "Isi {$title}", 'target' => 'semua', 'show_public' => $public, 'expires_at' => $expires]);
    }

    public function test_guest_sees_all_active_public_announcements(): void
    {
        foreach (range(1, 6) as $i) {
            $this->announce("Info {$i}");
        }
        $this->announce('Rahasia internal', public: false);
        $this->announce('Sudah kedaluwarsa', expires: now()->subDay()->toDateString());

        $this->get('/pengumuman')->assertOk()->assertSee('Info 1')->assertSee('Info 6')
            ->assertDontSee('Rahasia internal')->assertDontSee('Sudah kedaluwarsa');
        $this->get('/')->assertOk()->assertSee(route('pengumuman.publik'))->assertSee('Lihat semua (6)');
    }

    public function test_empty_state(): void
    {
        $this->get('/pengumuman')->assertOk()->assertSee('Belum ada pengumuman saat ini.');
    }
}
