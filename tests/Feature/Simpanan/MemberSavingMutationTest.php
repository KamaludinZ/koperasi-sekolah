<?php

namespace Tests\Feature\Simpanan;

use App\Livewire\Simpanan\MemberSavings;
use App\Models\ActivityLog;
use App\Models\Member;
use App\Models\MemberSavingEntry;
use App\Models\SavingType;
use App\Services\MemberSavingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class MemberSavingMutationTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 09:00:00');
        $this->member = Member::factory()->create(['joined_at' => '2026-08-20']);
    }

    private function type(string $code): SavingType
    {
        return SavingType::where('code', $code)->firstOrFail();
    }

    private function svc(): MemberSavingService
    {
        return app(MemberSavingService::class);
    }

    public function test_wajib_pays_oldest_months_then_in_advance(): void
    {
        $entries = $this->svc()->deposit($this->member, $this->type('WAJIB'), 0, null, null, 2);
        $this->assertSame(['2026-08', '2026-09'], array_map(fn ($e) => $e->period, $entries));
        $this->assertSame(20000, (int) end($entries)->balance_after);

        $entries = $this->svc()->deposit($this->member, $this->type('WAJIB'), 0, null, null, 3);
        $this->assertSame(['2026-10', '2026-11', '2026-12'], array_map(fn ($e) => $e->period, $entries));
        $this->assertSame([], $this->svc()->unpaidPeriods($this->member, $this->type('WAJIB')));

        $audit = ActivityLog::where('event', 'simpanan.setor')->latest('id')->first();
        $this->assertSame(30000, $audit->amount);
        $this->assertSame(['2026-10', '2026-11', '2026-12'], $audit->properties['periods']);
    }

    public function test_pokok_installments_until_paid(): void
    {
        $this->svc()->deposit($this->member, $this->type('POKOK'), 30000);
        $this->expectValidation(fn () => $this->svc()->deposit($this->member, $this->type('POKOK'), 30000), 'amount', 'Sisa Simpanan Pokok hanya Rp20.000.');
        $this->svc()->deposit($this->member, $this->type('POKOK'), 20000);
        $this->expectValidation(fn () => $this->svc()->deposit($this->member, $this->type('POKOK'), 1000), 'amount', 'Simpanan Pokok sudah lunas.');
    }

    public function test_withdraw_rules(): void
    {
        $this->svc()->deposit($this->member, $this->type('SUKARELA'), 15000);
        $this->expectValidation(fn () => $this->svc()->withdraw($this->member, $this->type('SUKARELA'), 20000), 'amount', 'Penarikan melebihi saldo Simpanan Sukarela (Rp15.000).');
        $this->expectValidation(fn () => $this->svc()->withdraw($this->member, $this->type('WAJIB'), 1000), 'type', 'Simpanan Wajib tidak bisa ditarik selama masih menjadi anggota.');
        $e = $this->svc()->withdraw($this->member, $this->type('SUKARELA'), 5000);
        $this->assertSame(10000, (int) $e->balance_after);
        $this->assertSame(10000, (int) $this->member->savingAccounts()->where('saving_type_id', $this->type('SUKARELA')->id)->value('balance'));
        $this->assertEquals(0, (float) $this->member->fresh()->balance); // tabungan tidak tersentuh
    }

    public function test_inactive_member_type_and_idempotency(): void
    {
        $this->svc()->deposit($this->member, $this->type('SUKARELA'), 5000, null, null, 1, 'ref-1');
        $this->svc()->deposit($this->member, $this->type('SUKARELA'), 5000, null, null, 1, 'ref-1');
        $this->assertSame(1, MemberSavingEntry::count());

        $this->type('SUKARELA')->update(['is_active' => false]);
        $this->expectValidation(fn () => $this->svc()->deposit($this->member, $this->type('SUKARELA'), 5000), 'type', 'Simpanan Sukarela sudah nonaktif; tidak menerima setoran baru.');
        $this->member->update(['is_active' => false]);
        $this->expectValidation(fn () => $this->svc()->withdraw($this->member, $this->type('SUKARELA'), 1000), 'member', 'Anggota nonaktif tidak bisa setor/tarik simpanan.');
    }

    public function test_form_deposit_and_withdraw(): void
    {
        $c = Livewire::test(MemberSavings::class, ['memberId' => $this->member->id])
            ->call('openForm', 'setor', $this->type('WAJIB')->id)
            ->assertSet('amount', 10000)->set('months', 3)
            ->assertSee('Untuk bulan: Agt 2026, Sep 2026, Okt 2026')
            ->call('save')->assertHasNoErrors()->assertSet('open', false)->assertSee('Lancar');
        $this->assertSame(3, MemberSavingEntry::where('saving_type_id', $this->type('WAJIB')->id)->count());

        $c->call('openForm', 'setor', $this->type('POKOK')->id)->assertSet('amount', 50000)->set('amount', 60000)
            ->call('save')->assertHasErrors('amount')->set('amount', 50000)->call('save')->assertHasNoErrors();

        $c->call('openForm', 'setor', $this->type('SUKARELA')->id)->assertSet('amount', null)->call('save')->assertHasErrors('amount')
            ->set('amount', 8000)->call('save')->assertHasNoErrors();
        $c->call('openForm', 'tarik', $this->type('SUKARELA')->id)->set('amount', 9000)->call('save')->assertHasErrors('amount')
            ->set('amount', 3000)->call('save')->assertHasNoErrors();

        $this->assertSame(85000, app(MemberSavingService::class)->summary($this->member)['total']);
    }

    private function expectValidation(callable $fn, string $key, string $message): void
    {
        try {
            $fn();
            $this->fail('ValidationException expected');
        } catch (ValidationException $e) {
            $this->assertSame($message, $e->errors()[$key][0] ?? null);
        }
    }
}
