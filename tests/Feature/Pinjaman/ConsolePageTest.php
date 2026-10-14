<?php

namespace Tests\Feature\Pinjaman;

use App\Models\Loan;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Livewire\Pinjaman\Index;
use Livewire\Livewire;
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
        $this->get('/pinjaman')->assertOk()->assertSee('Belum ada pinjaman anggota')->assertSee('data-empty="pinjaman"', false)->assertDontSee('Semua metode');
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

    public function test_search_and_filters(): void
    {
        $rina = Member::factory()->teacher()->create(['name' => 'Bu Rina', 'member_code' => 'G-2026-777']);
        Loan::factory()->running()->create(['code' => 'PJM-R1', 'member_id' => $rina->id, 'payment_method' => 'transfer', 'outstanding' => 100000]);
        Loan::factory()->create(['code' => 'PJM-R2', 'member_id' => $rina->id, 'payment_method' => 'tunai']);
        $budi = Member::factory()->teacher()->create(['name' => 'Pak Budi', 'member_code' => 'G-2026-001']); // nama tetap: faker bisa memuat "rina"
        Loan::factory()->overdue()->create(['code' => 'PJM-Z9', 'member_id' => $budi->id, 'payment_method' => 'potong_gaji', 'outstanding' => 50000]);

        $codes = fn ($c) => collect($c->instance()->loans->items())->pluck('code')->sort()->values()->all();

        $c = Livewire::test(Index::class)->set('search', 'rina');
        $this->assertSame(['PJM-R1', 'PJM-R2'], $codes($c));
        $c->set('search', 'g-2026-777')->set('method', 'transfer');
        $this->assertSame(['PJM-R1'], $codes($c));
        $c->set('search', 'pjm-z9')->set('method', '');
        $this->assertSame(['PJM-Z9'], $codes($c));
        $c->call('resetFilters')->set('status', 'diajukan');
        $this->assertSame(['PJM-R2'], $codes($c));

        // Kartu ringkasan menyaring status; klik lagi melepas.
        $c->call('resetFilters')->call('filterStatus', 'menunggak')->assertSet('status', 'menunggak');
        $this->assertSame(['PJM-Z9'], $codes($c));
        $c->call('filterStatus', 'menunggak')->assertSet('status', '');
        $this->assertCount(3, $codes($c));

        // Nilai tidak dikenal diabaikan, bukan error.
        $c->set('status', 'hacked')->set('method', "x' OR 1=1");
        $this->assertCount(3, $codes($c));
        $c->call('filterStatus', 'bogus')->assertSet('status', '');

        $c->set('search', 'tidak-ada-yang-cocok')->assertSee('Tidak ada pinjaman yang cocok.');
    }

    public function test_filters_from_query_string(): void
    {
        Loan::factory()->create(['code' => 'PJM-Q1']);
        Loan::factory()->create(['code' => 'PJM-Q2', 'status' => 'lunas']);
        $this->get('/pinjaman?status=lunas')->assertOk()->assertSee('PJM-Q2')->assertDontSee('PJM-Q1');
        $this->get('/pinjaman?q=q1')->assertOk()->assertSee('PJM-Q1')->assertDontSee('PJM-Q2');
    }
}
