<?php

namespace Tests\Feature\Simpanan;

use App\Livewire\Anggota\Show;
use App\Models\Member;
use App\Models\MemberSavingAccount;
use App\Models\MemberSavingEntry;
use App\Models\SavingType;
use App\Services\MemberSavingService;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileSavingTypesTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 09:00:00');
        $this->member = Member::factory()->create(['joined_at' => '2026-08-20']);
    }

    private function deposit(string $code, int $amount, ?string $period = null): void
    {
        $type = SavingType::where('code', $code)->firstOrFail();
        $acc = MemberSavingAccount::firstOrCreate(['member_id' => $this->member->id, 'saving_type_id' => $type->id]);
        $before = (int) $acc->balance;
        MemberSavingEntry::create(['member_id' => $this->member->id, 'saving_type_id' => $type->id, 'type' => 'setor', 'amount' => $amount,
            'balance_before' => $before, 'balance_after' => $before + $amount, 'period' => $period]);
        $acc->update(['balance' => $before + $amount]);
    }

    public function test_summary_balances_status_and_arrears(): void
    {
        $this->deposit('POKOK', 50000);
        $this->deposit('WAJIB', 10000, '2026-08');
        $this->deposit('SUKARELA', 7500);

        $s = app(MemberSavingService::class)->summary($this->member);
        $by = collect($s['types'])->keyBy('code');

        $this->assertSame(67500, $s['total']);
        $this->assertSame('lunas', $by['POKOK']['status']);
        $this->assertSame(['tunggak', 1, 2, 20000], [$by['WAJIB']['status'], $by['WAJIB']['paid_months'], $by['WAJIB']['arrears_months'], $by['WAJIB']['arrears_amount']]);
        $this->assertNull($by['SUKARELA']['status']);
        $this->assertSame(3, MemberSavingService::monthsDue(Carbon::parse('2026-08-20'), now()));
        $this->assertSame(0, MemberSavingService::monthsDue(Carbon::parse('2026-11-01'), now()));
    }

    public function test_inactive_type_shown_only_with_balance(): void
    {
        SavingType::where('code', 'SUKARELA')->update(['is_active' => false]);
        $codes = fn () => collect(app(MemberSavingService::class)->summary($this->member)['types'])->pluck('code')->all();
        $this->assertSame(['POKOK', 'WAJIB'], $codes());
        $this->deposit('SUKARELA', 1000);
        $this->assertSame(['POKOK', 'WAJIB', 'SUKARELA'], $codes());
    }

    public function test_profile_card_for_admin_and_pengurus_not_kasir(): void
    {
        $this->deposit('POKOK', 20000);
        Livewire::test(Show::class, ['member' => $this->member->id])
            ->assertSee('data-saving-types', false)->assertSee('Simpanan Pokok')->assertSee('Belum dibayar Rp30.000')
            ->assertSee('Tunggakan 3 bulan · Rp30.000');

        $this->seed(UserSeeder::class);
        $this->loginAs('kasir@koperasi.test');
        Livewire::test(Show::class, ['member' => $this->member->id])->assertDontSee('data-saving-types', false);
        $this->loginAs('pengurus@koperasi.test');
        Livewire::test(Show::class, ['member' => $this->member->id])->assertSee('data-saving-types', false);
    }
}
