<?php

namespace Tests\Feature\Pinjaman;

use App\Models\Loan;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsolePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_console_lists_loans_with_action_needed_first(): void
    {
        $dedi = Member::factory()->teacher()->create(['name' => 'Pak Dedi']);
        Loan::factory()->running()->create(['code' => 'PJM-A', 'member_id' => $dedi->id, 'principal' => 1000000, 'interest' => 50000, 'outstanding' => 1050000]);
        Loan::factory()->overdue()->create(['code' => 'PJM-B']);
        Loan::factory()->create(['code' => 'PJM-C']); // diajukan
        Loan::factory()->create(['code' => 'PJM-D', 'status' => 'lunas']);

        $this->get('/pinjaman')->assertOk()->assertSee('Pinjaman Anggota')
            ->assertSeeInOrder(['PJM-B', 'PJM-C', 'PJM-A', 'PJM-D'])
            ->assertSee('Pak Dedi')->assertSee('Rp1.050.000')->assertSee('Rp105.000/bln')
            ->assertSee('data-loan-status="menunggak"', false)->assertSee('Potong gaji');
    }

    public function test_empty_and_access(): void
    {
        $this->get('/pinjaman')->assertOk()->assertSee('Belum ada pinjaman anggota.');
        $this->loginAs('pengurus');
        $this->get('/pinjaman')->assertOk();
        $this->loginAs('kasir');
        $this->get('/pinjaman')->assertForbidden();
    }

    public function test_status_summary_cards(): void
    {
        Loan::factory()->count(2)->create(); // diajukan
        Loan::factory()->create(['status' => 'disetujui']);
        Loan::factory()->running()->create(['principal' => 1000000, 'interest' => 50000, 'outstanding' => 600000]);
        Loan::factory()->overdue()->create(['principal' => 500000, 'interest' => 25000, 'outstanding' => 300000]);
        Loan::factory()->create(['status' => 'lunas', 'outstanding' => 0]);

        $stats = app(\App\Services\LoanService::class)->stats();
        $this->assertSame(['diajukan' => 2, 'disetujui' => 1, 'ditolak' => 0, 'dibatalkan' => 0, 'berjalan' => 1, 'menunggak' => 1, 'lunas' => 1], $stats['counts']);
        $this->assertSame(900000, $stats['outstanding']);
        $this->assertSame(300000, $stats['overdue_outstanding']);

        $this->get('/pinjaman')->assertOk()
            ->assertSeeInOrder(['data-loan-card="diajukan"', 'Menunggu keputusan', '2', '1 disetujui, belum cair'], false)
            ->assertSeeInOrder(['data-loan-card="menunggak"', 'Sisa Rp300.000'], false)
            ->assertSeeInOrder(['data-loan-card="sisa"', 'Rp900.000'], false);
    }

    public function test_summary_cards_when_empty(): void
    {
        $this->get('/pinjaman')->assertOk()->assertSeeInOrder(['data-loan-card="sisa"', 'Rp0'], false);
    }
}
