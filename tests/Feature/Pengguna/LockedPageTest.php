<?php

namespace Tests\Feature\Pengguna;

use App\Livewire\Pengguna\Locked;
use App\Models\ActivityLog;
use App\Models\LoginLockout;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LockedPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 15:00:00');
        $id = fn (string $email) => User::where('email', $email)->value('id');
        LoginLockout::create(['email' => 'kasir@koperasi.test', 'user_id' => $id('kasir@koperasi.test'), 'failed_count' => 6, 'level' => 1,
            'locked_at' => now(), 'locked_until' => now()->addMinute(), 'last_attempt_at' => now(), 'ip_addresses' => ['192.168.1.12']]);
        LoginLockout::create(['email' => 'pengurus@koperasi.test', 'user_id' => $id('pengurus@koperasi.test'), 'failed_count' => 11, 'level' => 2,
            'locked_at' => now()->subMinutes(6), 'locked_until' => now()->addMinutes(9), 'last_attempt_at' => now()->subMinutes(2), 'ip_addresses' => ['192.168.1.14', '10.0.0.7']]);
        LoginLockout::create(['email' => 'nonaktif@koperasi.test', 'user_id' => $id('nonaktif@koperasi.test'), 'failed_count' => 15, 'level' => 3,
            'locked_at' => now()->subDay(), 'last_attempt_at' => now()->subHours(20), 'ip_addresses' => ['192.168.1.30']]);
        LoginLockout::create(['email' => 'bendahara@koperasi.test', 'failed_count' => 23, 'level' => 3,
            'locked_at' => now()->subHours(5), 'last_attempt_at' => now()->subHours(2), 'ip_addresses' => ['185.220.101.4', '185.220.101.7']]);
        // Kunci sementara yang sudah habis tidak tampil.
        LoginLockout::create(['email' => 'lama@koperasi.test', 'failed_count' => 5, 'level' => 1,
            'locked_at' => now()->subHour(), 'locked_until' => now()->subMinutes(59), 'last_attempt_at' => now()->subHour()]);
    }

    private function row(string $email): ?array
    {
        return collect(Livewire::test(Locked::class)->instance()->accounts)->firstWhere('email', $email);
    }

    public function test_only_admin_can_open(): void
    {
        $this->get('/pengguna/terkunci')->assertOk()->assertSee('Akun terkunci')->assertSee('Email tidak terdaftar')->assertDontSee('Data contoh');
        $this->get('/pengguna')->assertSee(route('pengguna.terkunci'));
        $this->loginAs('pengurus@koperasi.test');
        $this->get('/pengguna/terkunci')->assertForbidden();
        $this->getJson('/pengguna/terkunci/data')->assertForbidden();
        $this->loginAs('kasir@koperasi.test');
        $this->get('/pengguna/terkunci')->assertForbidden();
    }

    public function test_list_tiers_and_summary(): void
    {
        $this->assertSame(9, Locked::minutesLeft($this->row('pengurus@koperasi.test')));
        $this->assertNull(Locked::minutesLeft($this->row('nonaktif@koperasi.test')));
        $this->assertNull($this->row('lama@koperasi.test'));

        $c = Livewire::test(Locked::class);
        $this->assertSame(['total' => 4, 'manual' => 2, 'temporary' => 2, 'unknown' => 1, 'failed' => 55], $c->instance()->summary);
        $this->assertSame(3, $c->instance()->accounts[0]['level']);
        $this->assertSame('Pak Galih', $this->row('nonaktif@koperasi.test')['name']);
        $this->assertSame([5, 10, 15], array_column(Locked::tiers(), 'failed'));
        $c->assertSee('5× gagal', false);
    }

    public function test_unlock_temporary_lock_without_note(): void
    {
        $id = $this->row('kasir@koperasi.test')['id'];
        Livewire::test(Locked::class)
            ->call('confirmUnlock', $id)->assertSee('Buka kunci Bu Rina?')
            ->call('unlock')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success', message: 'Bu Rina dapat mencoba masuk lagi.')
            ->assertSet('unlockId', null);
        $this->assertNull($this->row('kasir@koperasi.test'));
        $this->assertSame(1, ActivityLog::where('event', 'auth.unlocked')->count());
    }

    public function test_final_tier_requires_note(): void
    {
        $id = $this->row('bendahara@koperasi.test')['id'];
        $c = Livewire::test(Locked::class)->call('confirmUnlock', $id)->call('unlock')->assertHasErrors(['note' => 'required']);
        $this->assertNotNull($this->row('bendahara@koperasi.test'));

        $c->set('note', 'Sudah dikonfirmasi lewat telepon')->call('unlock')->assertHasNoErrors();
        $this->assertNull($this->row('bendahara@koperasi.test'));
        $this->assertSame('Sudah dikonfirmasi lewat telepon', ActivityLog::where('event', 'auth.unlocked')->value('reason'));

        $c->call('confirmUnlock', 99999)->call('unlock')->assertSet('unlockId', null);
    }

    public function test_endpoints(): void
    {
        $this->getJson('/pengguna/terkunci/data')->assertOk()
            ->assertJsonCount(4, 'data')->assertJsonPath('summary.manual', 2)->assertJsonPath('tiers.2.label', 'Sampai dibuka Admin')
            ->assertJsonPath('data.0.level', 3);

        $manual = LoginLockout::where('email', 'nonaktif@koperasi.test')->value('id');
        $this->postJson("/pengguna/terkunci/{$manual}/buka")->assertJsonValidationErrors('catatan');
        $this->postJson("/pengguna/terkunci/{$manual}/buka", ['catatan' => 'Akun diaktifkan kembali'])->assertOk()
            ->assertJsonPath('message', 'Pak Galih dapat mencoba masuk lagi.');
        $temp = LoginLockout::where('email', 'kasir@koperasi.test')->value('id');
        $this->postJson("/pengguna/terkunci/{$temp}/buka")->assertOk();
        $this->postJson("/pengguna/terkunci/{$temp}/buka")->assertNotFound();
        $this->getJson('/pengguna/terkunci/data')->assertJsonCount(2, 'data');
    }
}
