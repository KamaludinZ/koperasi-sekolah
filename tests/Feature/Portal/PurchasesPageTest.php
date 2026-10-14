<?php

namespace Tests\Feature\Portal;

use App\Livewire\Portal\Purchases;
use App\Models\Member;
use App\Models\Transaction;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PurchasesPageTest extends TestCase
{
    use RefreshDatabase;

    private Member $andi;

    private Transaction $mine;

    private Transaction $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $this->travelTo('2026-10-14 09:00:00');
        $this->andi = Member::factory()->create(['name' => 'Andi']);
        $budi = Member::factory()->create();
        $this->mine = Transaction::factory()->withItems([$this->pid('ATK001') => 2])->at('2026-10-10 08:00:00')->create(['member_id' => $this->andi->id, 'invoice_number' => 'INV-20261010-0001']);
        Transaction::factory()->withItems([$this->pid('ATK002') => 1])->at('2026-10-11 08:00:00')
            ->create(['member_id' => $this->andi->id, 'invoice_number' => 'INV-20261011-0001', 'voided_at' => now(), 'void_reason' => 'Salah input']);
        Transaction::factory()->withItems([$this->pid('ATK001') => 1])->at('2026-01-05 08:00:00')->create(['member_id' => $this->andi->id, 'invoice_number' => 'INV-20260105-0001']);
        $this->other = Transaction::factory()->withItems([$this->pid('ATK003') => 1])->at('2026-10-12 08:00:00')->create(['member_id' => $budi->id, 'invoice_number' => 'INV-20261012-0001']);
        $this->actingAsMember($this->andi);
    }

    public function test_lists_own_transactions_with_status(): void
    {
        $this->get('/portal/riwayat')->assertOk()
            ->assertSee('INV-20261010-0001')->assertSee('INV-20261011-0001')->assertSee('Dibatalkan')
            ->assertDontSee('INV-20261012-0001')->assertDontSee('INV-20260105-0001')
            ->assertSee('1</span> transaksi', false)->assertSee('Rp8.000');

        Livewire::test(Purchases::class)->set('period', 'semua')->assertSee('INV-20260105-0001')->assertSee('2</span> transaksi', false);
    }

    public function test_detail_and_ownership(): void
    {
        Livewire::test(Purchases::class)->call('show', $this->mine->id)
            ->assertSee('Buku Tulis 38 Lembar')->assertSee('2 × Rp4.000')->assertSee('Total')
            ->call('close')->assertSet('viewing', null);

        Livewire::test(Purchases::class)->call('show', $this->other->id)->assertStatus(404);
        $this->get('/portal/riwayat?lihat='.$this->other->id)->assertNotFound();
    }
}
