<?php

namespace Tests\Feature\Pengguna;

use App\Livewire\Pengguna\Locked;
use App\Support\MockData;
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
    }

    private function row(string $email): ?array
    {
        return collect(MockData::lockedAccounts())->firstWhere('email', $email);
    }

    public function test_only_admin_can_open(): void
    {
        $this->get('/pengguna/terkunci')->assertOk()->assertSee('Akun terkunci')->assertSee('Email tidak terdaftar');
        $this->get('/pengguna')->assertSee(route('pengguna.terkunci'));
        $this->loginAs('pengurus@koperasi.test');
        $this->get('/pengguna/terkunci')->assertForbidden();
        $this->loginAs('kasir@koperasi.test');
        $this->get('/pengguna/terkunci')->assertForbidden();
    }

    public function test_tiers_and_summary(): void
    {
        $this->assertSame(1, $this->row('kasir@koperasi.test')['level']);
        $this->assertSame(2, $this->row('pengurus@koperasi.test')['level']);
        $this->assertSame(3, $this->row('admin@koperasi.test')['level']);
        $this->assertNull($this->row('admin@koperasi.test')['locked_until']);
        $this->assertSame(9, Locked::minutesLeft($this->row('pengurus@koperasi.test')));
        $this->assertNull(Locked::minutesLeft($this->row('admin@koperasi.test')));

        $c = Livewire::test(Locked::class);
        $this->assertSame(['total' => 5, 'manual' => 3, 'temporary' => 2, 'unknown' => 1, 'failed' => 72], $c->instance()->summary);
        $this->assertSame(3, $c->instance()->accounts[0]['level']); // tingkat tertinggi di atas
        $this->assertSame(['5', '10', '15'], array_map('strval', array_column(Locked::tiers(), 'failed')));
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
    }

    public function test_final_tier_requires_note(): void
    {
        $id = $this->row('admin@koperasi.test')['id'];
        $c = Livewire::test(Locked::class)->call('confirmUnlock', $id)->call('unlock')->assertHasErrors(['note' => 'required']);
        $this->assertNotNull($this->row('admin@koperasi.test'));

        $c->set('note', 'Sudah dikonfirmasi lewat telepon')->call('unlock')->assertHasNoErrors();
        $this->assertNull($this->row('admin@koperasi.test'));
        $this->assertSame(4, $c->instance()->summary['total']);

        $c->call('confirmUnlock', 99999)->call('unlock')->assertSet('unlockId', null);
        $c->call('confirmUnlock', $this->row('pengurus@koperasi.test')['id'])->call('cancelUnlock')->assertSet('unlockId', null);
    }
}
