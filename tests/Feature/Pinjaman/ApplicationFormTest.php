<?php

namespace Tests\Feature\Pinjaman;

use App\Livewire\Pinjaman\Applications;
use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ApplicationFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 10:00:00');
    }

    public function test_pengurus_records_application_through_form(): void
    {
        $user = $this->loginAs('pengurus');
        $sari = Member::factory()->teacher()->create(['name' => 'Bu Sari Wulandari', 'member_code' => 'G-2026-031']);

        $c = Livewire::test(Applications::class)->call('create')->assertSet('creating', true)
            ->assertSet('form.applied_at', '2026-10-14')
            ->set('memberSearch', 'sari w')->assertSee('G-2026-031')
            ->call('selectMember', $sari->id)->assertSet('form.member_id', $sari->id)->assertSeeHtml('data-selected-member="'.$sari->id.'"')
            ->set('form.principal', 2000000)->set('form.interest', 100000)->set('form.tenor_months', 10)
            ->assertSee('Rp2.100.000')->assertSee('Rp210.000')
            ->set('form.payment_method', 'transfer')->set('form.purpose', '  Biaya kuliah anak  ')
            ->call('save')->assertHasNoErrors()->assertSet('creating', false)
            ->assertSee('Pengajuan PJM-2026-0001 untuk Bu Sari Wulandari dicatat');

        $loan = Loan::firstOrFail();
        $this->assertSame(['PJM-2026-0001', 'diajukan', 2000000, 100000, 10, 'transfer', 'Biaya kuliah anak', '2026-10-14', $user->id, 0],
            [$loan->code, $loan->status, (int) $loan->principal, (int) $loan->interest, $loan->tenor_months, $loan->payment_method, $loan->purpose,
                $loan->applied_at->toDateString(), $loan->created_by, (int) $loan->outstanding]);
        $c->assertSeeHtml('data-application="PJM-2026-0001"');

        $log = ActivityLog::where('event', 'pinjaman.diajukan')->firstOrFail();
        $this->assertSame(['pinjaman', 'loan', $loan->id, 2000000, 'dibuat'], [$log->category, $log->subject_type, $log->subject_id, $log->amount, $log->action]);
        $this->assertSame(100000, $log->new_values['interest']);
    }

    public function test_validation_messages(): void
    {
        $inactive = Member::factory()->teacher()->inactive()->create();
        Livewire::test(Applications::class)->call('create')
            ->set('form.applied_at', '2026-10-20')->set('form.payment_method', 'kartu')
            ->call('save')->assertHasErrors(['form.member_id' => 'required', 'form.principal' => 'required', 'form.interest' => 'required',
                'form.tenor_months' => 'required', 'form.payment_method', 'form.applied_at'])
            ->assertSee('Pilih anggota yang mengajukan.')->assertSee('Tanggal pengajuan tidak boleh di masa depan.')
            ->set('form.member_id', $inactive->id)->set('form.principal', 5000)->set('form.interest', -1)->set('form.tenor_months', 121)
            ->call('save')->assertHasErrors(['form.member_id' => 'exists', 'form.principal' => 'min', 'form.interest' => 'min', 'form.tenor_months' => 'max'])
            ->set('form.principal', 100000)->set('form.interest', 100001)
            ->call('save')->assertHasErrors(['form.interest' => 'lte'])->assertSee('Bunga tidak boleh melebihi nominal pinjaman.');
        $this->assertSame(0, Loan::count());
    }

    public function test_inactive_members_cannot_be_picked_and_open_loans_warned(): void
    {
        $inactive = Member::factory()->teacher()->inactive()->create(['name' => 'Pak Nonaktif']);
        $budi = Member::factory()->teacher()->create(['name' => 'Pak Budi']);
        Loan::factory()->overdue()->create(['code' => 'PJM-2026-0004', 'member_id' => $budi->id, 'principal' => 500000, 'interest' => 0, 'outstanding' => 300000]);

        Livewire::test(Applications::class)->call('create')
            ->set('memberSearch', 'Nonaktif')->assertSee('Tidak ada anggota aktif yang cocok.')
            ->call('selectMember', $inactive->id)->assertSet('form.member_id', null)
            ->call('selectMember', $budi->id)
            ->assertSee('masih punya 1 pinjaman terbuka (PJM-2026-0004), sisa Rp300.000, termasuk yang menunggak');
    }

    public function test_codes_are_sequential_per_year(): void
    {
        $member = Member::factory()->teacher()->create();
        $svc = app(LoanApplicationService::class);
        $base = ['member_id' => $member->id, 'principal' => 100000, 'interest' => 0, 'tenor_months' => 1, 'payment_method' => 'tunai'];
        $this->assertSame('PJM-2026-0001', $svc->create($base + ['applied_at' => '2026-10-01'])->code);
        $this->assertSame('PJM-2026-0002', $svc->create($base + ['applied_at' => '2026-10-14'])->code);
        $this->assertSame('PJM-2025-0001', $svc->create($base + ['applied_at' => '2025-12-20'])->code);
    }

    public function test_service_rules_reject_bad_payload(): void
    {
        $this->expectException(ValidationException::class);
        validator(['member_id' => 999, 'principal' => 'abc', 'interest' => 0, 'tenor_months' => 0, 'payment_method' => 'tunai', 'applied_at' => '2024-01-01'],
            LoanApplicationService::rules())->validate();
    }

    public function test_kasir_cannot_open_form(): void
    {
        $this->loginAs('kasir');
        Livewire::test(Applications::class)->call('create')->assertForbidden();
        Livewire::test(Applications::class)->call('save')->assertForbidden();
    }
}
