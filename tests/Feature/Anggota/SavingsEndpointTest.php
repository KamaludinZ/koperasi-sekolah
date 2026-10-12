<?php

namespace Tests\Feature\Anggota;

use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Services\SavingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SavingsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = Member::factory()->create();
    }

    private function save(array $body)
    {
        return $this->postJson("/anggota/{$this->member->id}/tabungan", $body);
    }

    public function test_deposit_then_withdraw_updates_balance_and_ledger(): void
    {
        $this->save(['type' => 'setor', 'amount' => 25000])
            ->assertCreated()
            ->assertJsonPath('data.type', 'setor')
            ->assertJsonPath('data.balance_before', 0)
            ->assertJsonPath('data.balance_after', 25000)
            ->assertJsonPath('data.note', 'Setoran tabungan')
            ->assertJsonPath('member.balance', 25000);

        $this->save(['type' => 'tarik', 'amount' => 10000, 'note' => ' Beli buku '])
            ->assertCreated()
            ->assertJsonPath('data.type_label', 'Penarikan')
            ->assertJsonPath('data.balance_after', 15000)
            ->assertJsonPath('data.note', 'Beli buku');

        $this->assertSame('15000.00', $this->member->fresh()->balance);
        $this->assertSame([25000, 15000], $this->member->savings()->orderBy('id')->pluck('balance_after')->map(fn ($v) => (int) $v)->all());
    }

    public function test_withdrawal_cannot_exceed_balance(): void
    {
        $this->save(['type' => 'setor', 'amount' => 5000]);

        $this->save(['type' => 'tarik', 'amount' => 6000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'Penarikan melebihi saldo (Rp5.000).']);

        $this->save(['type' => 'tarik', 'amount' => 5000])->assertCreated()->assertJsonPath('member.balance', 0);
        $this->assertSame(2, SavingsTransaction::count());
    }

    public function test_validates_amount_type_and_member(): void
    {
        $this->save(['type' => 'setor', 'amount' => 500])->assertJsonValidationErrors('amount');
        $this->save(['type' => 'setor', 'amount' => 10_000_001])->assertJsonValidationErrors('amount');
        $this->save(['type' => 'setor', 'amount' => 1500.5])->assertJsonValidationErrors('amount');
        $this->save(['type' => 'bunga', 'amount' => 5000])->assertJsonValidationErrors('type');
        $this->save(['type' => 'setor', 'amount' => 5000, 'note' => str_repeat('x', 151)])->assertJsonValidationErrors('note');

        $this->member->update(['is_active' => false]);
        $this->save(['type' => 'setor', 'amount' => 5000])->assertJsonValidationErrors('member');

        $this->postJson('/anggota/9999/tabungan', ['type' => 'setor', 'amount' => 5000])->assertNotFound();
        $this->assertSame(0, SavingsTransaction::count());
    }

    public function test_service_rejects_invalid_amount_directly(): void
    {
        $this->expectException(ValidationException::class);
        app(SavingsService::class)->withdraw($this->member, 0);
    }

    public function test_stale_model_still_checks_locked_balance(): void
    {
        $stale = Member::find($this->member->id); // saldo 0 di memori
        app(SavingsService::class)->deposit($this->member, 8000);

        // Model basi tidak boleh membuat saldo dihitung dari angka lama.
        $entry = app(SavingsService::class)->withdraw($stale, 3000);
        $this->assertSame('8000.00', $entry->balance_before);
        $this->assertSame('5000.00', $stale->balance);
    }
}
