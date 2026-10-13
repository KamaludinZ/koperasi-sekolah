<?php

namespace Tests\Feature\Transaksi;

use App\Livewire\Transaksi\Returns;
use App\Livewire\Transaksi\VoidForm;
use App\Models\Transaction;
use App\Support\MockData;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VoidFormTest extends TestCase
{
    use RefreshDatabase;

    private Transaction $tx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->tx = Transaction::factory()->withItems([$this->pid('ATK001') => 2])->at(now()->subHour()->toDateTimeString())->create();
    }

    private function code(Transaction $t): string
    {
        return substr(preg_replace('/\D/', '', $t->invoice_number), -4);
    }

    public function test_links_from_detail_and_returns_lookup(): void
    {
        $this->get(route('transaksi.show', $this->tx))->assertOk()->assertSee(route('transaksi.retur.batal', $this->tx), false);
        $this->assertSame(route('transaksi.retur.batal', $this->tx), Returns::formLink('batal', $this->tx->id));
        $this->assertSame(route('transaksi.retur.buat', $this->tx), Returns::formLink('retur', $this->tx->id));
    }

    public function test_form_shows_items_and_effects(): void
    {
        $this->get(route('transaksi.retur.batal', $this->tx))->assertOk()
            ->assertSee($this->tx->invoice_number)->assertSee('Akibat pembatalan')
            ->assertSee('Seluruh 2 barang kembali ke stok')->assertSee(VoidForm::EFFECTS['tunai'])
            ->assertSee($this->code($this->tx));
    }

    public function test_validation_and_mock_submit(): void
    {
        $c = Livewire::test(VoidForm::class, ['transaction' => $this->tx->id]);
        $c->call('submit')->assertHasErrors(['reason' => 'required', 'confirmCode' => 'required']);
        $c->set('reason', 'lainnya')->set('confirmCode', '9999')->call('submit')->assertHasErrors(['note' => 'required', 'confirmCode' => 'in']);
        $c->set('note', 'abc')->call('submit')->assertHasErrors(['note' => 'min']);
        $c->set('note', 'Pembeli berubah pikiran')->set('confirmCode', $this->code($this->tx))->call('submit')
            ->assertHasNoErrors()->assertRedirect(route('transaksi.retur'));
        $this->assertStringContainsString('Pengajuan pembatalan BTL-', session('toast')['message']);
        $this->assertSame(1, Transaction::whereKey($this->tx->id)->count());
    }

    public function test_blocked_when_expired_or_has_open_request(): void
    {
        $old = Transaction::factory()->withItems()->at(now()->subDays(5)->toDateTimeString())->create();
        $this->get(route('transaksi.retur.batal', $old))->assertOk()->assertSee('sudah lewat batas 3 hari')->assertDontSee('Ajukan pembatalan');
        Livewire::test(VoidForm::class, ['transaction' => $old->id])->set('reason', 'ganda')->call('submit')->assertForbidden();

        // Transaksi yang punya pengajuan tiruan (bukan ditolak) tidak bisa diajukan lagi.
        Transaction::factory()->withItems()->create(['created_at' => now()]);
        $open = collect(MockData::transactionReturns())->first(fn ($r) => $r['status'] !== 'ditolak' && now()->subDays(3)->startOfDay()->lte($r['transaction']['created_at']));
        $this->assertNotNull($open);
        $this->get(route('transaksi.retur.batal', $open['transaction']['id']))->assertOk()->assertSee('sudah punya pengajuan '.$open['number']);
    }

    public function test_effect_text_follows_payment_method(): void
    {
        $this->tx->update(['payment_method' => 'qris']);
        $this->get(route('transaksi.retur.batal', $this->tx))->assertSee(VoidForm::EFFECTS['qris']);
    }
}
