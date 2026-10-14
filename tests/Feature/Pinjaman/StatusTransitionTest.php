<?php

namespace Tests\Feature\Pinjaman;

use App\Livewire\Pinjaman\Applications;
use App\Models\Loan;
use App\Models\Member;
use App\Services\LoanApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_transition_map(): void
    {
        $allowed = [
            ['diajukan', 'disetujui'], ['diajukan', 'ditolak'], ['diajukan', 'dibatalkan'],
            ['disetujui', 'diajukan'], ['disetujui', 'dibatalkan'], ['disetujui', 'berjalan'],
            ['berjalan', 'menunggak'], ['berjalan', 'lunas'], ['menunggak', 'berjalan'], ['menunggak', 'lunas'],
            ['lunas', 'berjalan'], ['diajukan', 'diajukan'],
        ];
        foreach ($allowed as [$from, $to]) {
            $this->assertTrue(Loan::canTransition($from, $to), "{$from} → {$to}");
        }
        $denied = [
            ['diajukan', 'berjalan'], ['diajukan', 'lunas'], ['ditolak', 'disetujui'], ['ditolak', 'diajukan'], ['dibatalkan', 'diajukan'],
            ['dibatalkan', 'berjalan'], ['berjalan', 'diajukan'], ['berjalan', 'dibatalkan'], ['lunas', 'dibatalkan'], ['disetujui', 'lunas'],
        ];
        foreach ($denied as [$from, $to]) {
            $this->assertFalse(Loan::canTransition($from, $to), "{$from} → {$to}");
        }
        $this->assertEqualsCanonicalizing(array_keys(Loan::STATUSES), array_keys(Loan::TRANSITIONS));
    }

    public function test_model_rejects_illegal_jump(): void
    {
        $loan = Loan::factory()->create(['code' => 'PJM-T1']);
        try {
            $loan->update(['status' => 'berjalan', 'disbursed_at' => today(), 'outstanding' => 1]);
            $this->fail('Lompatan diajukan → berjalan harus ditolak.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('PJM-T1 tidak bisa berubah dari "diajukan" menjadi "berjalan"', $e->getMessage());
        }
        $this->assertSame('diajukan', $loan->fresh()->status);

        $rejected = Loan::factory()->create(['status' => 'ditolak']);
        $this->expectException(\DomainException::class);
        $rejected->update(['status' => 'disetujui']);
    }

    public function test_minimum_monthly_installment(): void
    {
        $this->assertNull(LoanApplicationService::monthlyError(['principal' => 120000, 'interest' => 0, 'tenor_months' => 120]));
        $this->assertSame('Angsuran per bulan jadi Rp833; minimal Rp1.000. Perpendek tenor.',
            LoanApplicationService::monthlyError(['principal' => 100000, 'interest' => 0, 'tenor_months' => 120]));

        $m = Member::factory()->teacher()->create();
        $this->postJson(route('pinjaman.pengajuan.store'), ['member_id' => $m->id, 'principal' => 10000, 'interest' => 0, 'tenor_months' => 24, 'payment_method' => 'tunai'])
            ->assertUnprocessable()->assertJsonPath('errors.tenor_months.0', 'Angsuran per bulan jadi Rp416; minimal Rp1.000. Perpendek tenor.');

        Livewire::test(Applications::class)->call('create')->call('selectMember', $m->id)
            ->set('form.principal', 10000)->set('form.interest', 0)->set('form.tenor_months', 24)->call('save')
            ->assertHasErrors('form.tenor_months')->assertSee('Perpendek tenor.');
        $this->assertSame(0, Loan::count());

        $loan = Loan::factory()->create(['member_id' => $m->id, 'principal' => 100000, 'interest' => 0, 'tenor_months' => 10]);
        Livewire::test(Applications::class)->call('edit', $loan->id)->set('form.tenor_months', 120)->call('save')
            ->assertHasErrors('form.tenor_months');
        $this->assertSame(10, $loan->fresh()->tenor_months);
    }
}
