<?php

namespace Tests\Feature\Portal;

use App\Models\Member;
use App\Models\MemberSavingEntry;
use App\Models\SavingType;
use App\Services\BalanceDeductionService;
use App\Services\MemberSavingService;
use App\Services\SavingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BalanceDeductionServiceTest extends TestCase
{
    use RefreshDatabase;

    private Member $m;

    private BalanceDeductionService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = Member::factory()->create();
        $this->svc = app(BalanceDeductionService::class);
        app(SavingsService::class)->deposit($this->m, 10000);
    }

    private function fails(callable $fn, string $contains): void
    {
        try {
            $fn();
            $this->fail('ValidationException expected');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($contains, $e->errors()['payment_method'][0]);
        }
    }

    public function test_tabungan_deduct_and_refund(): void
    {
        $r = $this->svc->deduct($this->m, null, 4000, 'Pesanan ORD-1', ['order', 1]);
        $this->assertSame(['source' => 'Tabungan', 'before' => 10000, 'after' => 6000], collect($r)->except('entry_id')->all());
        $this->assertEquals(6000, (float) $this->m->fresh()->balance);

        $this->fails(fn () => $this->svc->deduct($this->m, null, 7000, 'Pesanan ORD-2', ['order', 2]), 'Saldo tabungan kurang Rp1.000');
        $this->assertEquals(6000, (float) $this->m->fresh()->balance); // tidak ada yang dipotong

        $this->svc->refund($this->m, null, 4000, 'Pengembalian ORD-1', ['order', 1]);
        $this->assertEquals(10000, (float) $this->m->fresh()->balance);
    }

    public function test_simpanan_rules(): void
    {
        $sukarela = SavingType::where('code', 'SUKARELA')->first();
        app(MemberSavingService::class)->deposit($this->m, $sukarela, 5000);
        $r = $this->svc->deduct($this->m, $sukarela->id, 2000, 'Pesanan ORD-3', ['order', 3]);
        $this->assertSame(3000, $r['after']);
        $this->assertSame('potong', MemberSavingEntry::find($r['entry_id'])->type);

        $this->fails(fn () => $this->svc->deduct($this->m, SavingType::where('code', 'WAJIB')->value('id'), 1000, 'X', ['order', 4]), 'tidak bisa dipakai');
        $this->fails(fn () => $this->svc->deduct($this->m, $sukarela->id, 9000, 'X', ['order', 5]), 'Saldo Simpanan Sukarela kurang');
        $this->fails(fn () => $this->svc->deduct($this->m, null, 0, 'X', ['order', 6]), 'lebih dari 0');
        $this->m->update(['is_active' => false]);
        $this->fails(fn () => $this->svc->deduct($this->m, null, 1000, 'X', ['order', 7]), 'nonaktif');
    }
}
