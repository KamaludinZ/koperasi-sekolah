<?php

namespace Tests\Feature\Notifikasi;

use App\Livewire\Notifikasi\Announcements;
use App\Services\AnnouncementService;
use App\Support\MockData;
use Database\Seeders\NotificationSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ProductSeeder::class, NotificationSeeder::class]);
        $this->loginAs('pengurus');
    }

    public function test_pengurus_publishes_announcement_visible_in_notifications_and_public_home(): void
    {
        $this->get('/notifikasi')->assertSee('Buat pengumuman');
        $this->get('/notifikasi/pengumuman')->assertOk()->assertSee('Pratinjau');

        Livewire::test(Announcements::class)
            ->set('title', 'Koperasi tutup saat ujian')
            ->set('message', 'Selama ujian semester tanggal 1–5, koperasi hanya buka saat istirahat.')
            ->set('target', 'anggota')
            ->set('expires_at', now()->addDays(7)->toDateString())
            ->assertSee('Koperasi tutup saat ujian') // pratinjau
            ->call('publish')
            ->assertHasNoErrors()
            ->assertSet('title', '')
            ->assertDispatched('toast', type: 'success', message: 'Pengumuman diterbitkan.')
            ->assertDispatched('notifications-changed');

        $a = MockData::announcements()[0];
        $this->assertSame(['anggota', true, 'Pak Dedi'], [$a['target'], $a['show_public'], $a['author']]);
        $this->assertTrue(collect(MockData::notifications())->contains('title', 'Koperasi tutup saat ujian'));

        auth()->logout();
        $this->get('/')->assertSee('Pengumuman')->assertSee('Koperasi tutup saat ujian');
    }

    public function test_staff_only_announcement_is_not_public_and_targets_role(): void
    {
        Livewire::test(Announcements::class)
            ->set('target', 'kasir')
            ->assertSet('show_public', false)
            ->set('title', 'Cek harga seragam')
            ->set('message', 'Pastikan harga seragam di kasir sesuai daftar terbaru.')
            ->set('show_public', true) // dipaksa, tetap tidak publik
            ->call('publish')->assertHasNoErrors();

        $this->assertFalse(MockData::announcements()[0]['show_public']);
        $this->assertSame([], MockData::publicAnnouncements());
        $this->assertFalse(collect(MockData::notifications())->contains('title', 'Cek harga seragam')); // pengurus tidak menerima pengumuman kasir

        $this->loginAs('kasir');
        $this->assertTrue(collect(MockData::notifications())->contains('title', 'Cek harga seragam'));
    }

    public function test_validation_and_expiry(): void
    {
        Livewire::test(Announcements::class)
            ->set('title', 'abc')->set('message', 'pendek')->set('target', 'tamu')->set('expires_at', now()->subDay()->toDateString())
            ->call('publish')
            ->assertHasErrors(['title', 'message', 'target', 'expires_at'])
            ->assertDispatched('toast', type: 'error');

        AnnouncementService::toArray(app(AnnouncementService::class)->create(['title' => 'Lama', 'message' => 'Sudah lewat waktunya.', 'target' => 'semua', 'show_public' => true, 'expires_at' => now()->subDay()->toDateString()], auth()->user()));
        $this->assertSame([], MockData::publicAnnouncements());
        $this->assertFalse(collect(MockData::notifications())->contains('title', 'Lama'));
    }

    public function test_delete_and_access(): void
    {
        $a = AnnouncementService::toArray(app(AnnouncementService::class)->create(['title' => 'Hapus saya', 'message' => 'Isi pengumuman uji.', 'target' => 'semua', 'show_public' => true, 'expires_at' => null], auth()->user()));

        Livewire::test(Announcements::class)->call('delete', $a['id'])->assertDispatched('toast', type: 'success', message: 'Pengumuman dihapus.');
        $this->assertSame([], MockData::announcements());

        $this->loginAs('kasir');
        $this->get('/notifikasi/pengumuman')->assertForbidden();
        $this->get('/notifikasi')->assertDontSee('Buat pengumuman');
    }
}
