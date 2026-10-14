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
}
