<?php

namespace Tests\Feature\Transaksi;

use App\Livewire\Transaksi\Returns;
use App\Livewire\Transaksi\VoidForm;
use App\Models\Transaction;
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

    public function test_validation_and_submit(): void
    {
        $c = Livewire::test(VoidForm::class, ['transaction' => $this->tx->id]);
        $c->call('submit')->assertHasErrors(['reason' => 'required', 'confirmCode' => 'required']);
        $c->set('reason', 'lainnya')->set('confirmCode', '9999')->call('submit')->assertHasErrors(['note' => 'required', 'confirmCode' => 'in']);
        $c->set('note', 'abc')->call('submit')->assertHasErrors(['note' => 'min']);
        $c->set('note', 'Pembeli berubah pikiran')->set('confirmCode', $this->code($this->tx))->call('submit')
            ->assertHasNoErrors()->assertRedirect(route('transaksi.retur'));
        $this->assertStringContainsString('Pengajuan pembatalan BTL-', session('toast')['message']);
        $r = \App\Models\TransactionReturn::sole();
        $this->assertSame(['batal', 'menunggu', 'lainnya', 'Pembeli berubah pikiran'], [$r->kind, $r->status, $r->reason_key, $r->note]);
        $this->assertFalse($this->tx->fresh()->isVoided());
    }

    public function test_blocked_when_expired_or_has_open_request(): void
    {
        $old = Transaction::factory()->withItems()->at(now()->subDays(5)->toDateTimeString())->create();
        $this->get(route('transaksi.retur.batal', $old))->assertOk()->assertSee('sudah lewat batas 3 hari')->assertDontSee('Ajukan pembatalan');
        Livewire::test(VoidForm::class, ['transaction' => $old->id])->set('reason', 'ganda')->call('submit')->assertForbidden();

        // Transaksi yang punya pengajuan menunggu tidak bisa diajukan lagi.
        $r = app(\App\Services\TransactionReturnService::class)->requestReturn($this->tx, [$this->tx->items->first()->id => 1], 'rusak', true, 'tunai', null, null);
        $this->get(route('transaksi.retur.batal', $this->tx))->assertOk()->assertSee('sudah punya pengajuan '.$r->number);
    }

    public function test_effect_text_follows_payment_method(): void
    {
        $this->tx->update(['payment_method' => 'qris']);
        $this->get(route('transaksi.retur.batal', $this->tx))->assertSee(VoidForm::EFFECTS['qris']);
    }

    public function test_auto_approved_void_from_form(): void
    {
        $own = Transaction::factory()->withItems([$this->pid('ATK001') => 1])->create(['user_id' => auth()->id(), 'created_at' => now()->subMinutes(5)]);
        $this->get(route('transaksi.retur.batal', $own))->assertSee('Langsung dibatalkan');
        Livewire::test(VoidForm::class, ['transaction' => $own->id])->set('reason', 'salah_input')->set('confirmCode', $this->code($own))->call('submit')
            ->assertHasNoErrors()->assertRedirect(route('transaksi.retur'));
        $this->assertTrue($own->fresh()->isVoided());
        $this->assertStringContainsString('dibatalkan', session('toast')['message']);
        $this->get(route('transaksi.retur.batal', $own))->assertSee('Transaksi ini sudah dibatalkan.');
    }

    public function test_api_void_approve_reject(): void
    {
        $this->postJson(route('transaksi.retur.void', $this->tx), ['reason' => 'iseng'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $res = $this->postJson(route('transaksi.retur.void', $this->tx), ['reason' => 'batal_beli', 'note' => 'Uang kurang'])
            ->assertCreated()->assertJsonPath('data.kind', 'batal')->assertJsonPath('data.status', 'menunggu')->assertJsonPath('auto_approved', false);
        $id = $res->json('data.id');
        $this->getJson(route('transaksi.retur.show', $id))->assertOk()->assertJsonPath('data.number', $res->json('data.number'));

        // Pengaju (admin) tidak boleh memutus sendiri.
        $this->postJson(route('transaksi.retur.approve', $id))->assertStatus(422)->assertJsonValidationErrors('decision_note');
        $this->loginAs('pengurus@koperasi.test');
        if (! \Illuminate\Support\Facades\Gate::allows('setujui-retur')) {
            $this->markTestSkipped('Pengurus tidak punya menu Transaksi di data awal.');
        }
        $this->postJson(route('transaksi.retur.reject', $id), ['note' => 'no'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson(route('transaksi.retur.approve', $id), ['note' => 'OK'])->assertOk()->assertJsonPath('data.status', 'disetujui');
        $this->assertTrue($this->tx->fresh()->isVoided());
        $this->postJson(route('transaksi.retur.reject', $id), ['note' => 'Terlambat ditolak'])->assertStatus(422)->assertJsonValidationErrors('status');

        $this->loginAs('kasir@koperasi.test');
        $this->postJson(route('transaksi.retur.approve', $id))->assertForbidden();
    }
}
