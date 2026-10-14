<?php

namespace Tests\Feature\Pinjaman;

use App\Services\LoanScheduleGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ScheduleGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_basic_schedule(): void
    {
        $rows = LoanScheduleGenerator::generate(1000000, 50000, 4, '2026-10-13');
        $this->assertSame([262500, 262500, 262500, 262500], array_column($rows, 'amount'));
        $this->assertSame([12500, 12500, 12500, 12500], array_column($rows, 'interest_part'));
        $this->assertSame([250000, 250000, 250000, 250000], array_column($rows, 'principal_part'));
        $this->assertSame(['2026-11-13', '2026-12-13', '2027-01-13', '2027-02-13'], array_column($rows, 'due_date'));
        $this->assertSame([1, 2, 3, 4], array_column($rows, 'sequence'));
    }

    public function test_rounding_remainder_goes_to_last(): void
    {
        $rows = LoanScheduleGenerator::generate(1000000, 100, 3, '2026-01-01');
        $this->assertSame([333366, 333366, 333368], array_column($rows, 'amount'));
        $this->assertSame([33, 33, 34], array_column($rows, 'interest_part'));
    }

    public function test_month_end_anchoring_does_not_drift(): void
    {
        $this->assertSame(['2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'], array_column(LoanScheduleGenerator::generate(400000, 0, 4, '2026-01-31'), 'due_date'));
        $this->assertSame(['2028-02-29', '2028-03-30'], array_column(LoanScheduleGenerator::generate(200000, 0, 2, '2028-01-30'), 'due_date')); // kabisat
        $this->assertSame(['2026-12-15', '2027-01-15'], array_column(LoanScheduleGenerator::generate(200000, 0, 2, '2026-11-15 23:59:00'), 'due_date'));
    }

    public function test_invariants_over_many_inputs(): void
    {
        mt_srand(20261015);
        foreach (range(1, 300) as $_) {
            $principal = mt_rand(10_000, 100_000_000);
            $interest = mt_rand(0, $principal);
            $tenor = mt_rand(1, 120);
            $start = sprintf('2026-%02d-%02d', mt_rand(1, 12), mt_rand(1, 28));
            $rows = LoanScheduleGenerator::generate($principal, $interest, $tenor, $start);

            $this->assertCount($tenor, $rows);
            $this->assertSame($principal + $interest, array_sum(array_column($rows, 'amount')));
            $this->assertSame($interest, array_sum(array_column($rows, 'interest_part')));
            $this->assertSame($principal, array_sum(array_column($rows, 'principal_part')));
            $dates = array_column($rows, 'due_date');
            $sorted = $dates;
            sort($sorted);
            $this->assertSame($sorted, $dates);
            $this->assertCount($tenor, array_unique($dates));
            $first = $rows[0]['amount'];
            foreach ($rows as $i => $r) {
                $this->assertGreaterThanOrEqual(0, $r['principal_part']);
                $this->assertGreaterThanOrEqual(0, $r['interest_part']);
                if ($i < $tenor - 1) {
                    $this->assertSame($first, $r['amount']);
                }
            }
            $this->assertLessThan($first + $tenor, end($rows)['amount']); // sisa pembulatan < tenor rupiah
        }
    }

    public function test_rejects_invalid_input(): void
    {
        foreach ([[100000, 0, 0], [100000, 0, 121], [0, 0, 1], [100000, -1, 1]] as [$p, $i, $t]) {
            try {
                LoanScheduleGenerator::generate($p, $i, $t, '2026-10-15');
                $this->fail("Input {$p}/{$i}/{$t} harus ditolak.");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }
}
