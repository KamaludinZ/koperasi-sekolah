<?php

namespace Database\Factories;

use App\Models\Loan;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Loan> */
class LoanFactory extends Factory
{
    protected $model = Loan::class;

    public function definition(): array
    {
        $principal = $this->faker->randomElement([500000, 1000000, 1500000, 2000000]);

        return [
            'code' => 'PJM-'.now()->format('Ymd').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'member_id' => Member::factory()->teacher(),
            'principal' => $principal,
            'interest' => (int) ($principal * 0.05),
            'tenor_months' => 10,
            'payment_method' => 'potong_gaji',
            'status' => 'diajukan',
            'purpose' => 'Keperluan keluarga',
            'outstanding' => 0,
            'applied_at' => now()->toDateString(),
        ];
    }

    /** Lengkapi kolom wajib per status sesuai CHECK PostgreSQL: tanggal cair, sisa nol saat lunas, waktu keputusan/batal. */
    public function configure(): static
    {
        return $this->afterMaking(function (Loan $loan) {
            if (in_array($loan->status, ['berjalan', 'menunggak', 'lunas'], true) && ! $loan->disbursed_at) {
                $loan->disbursed_at = now()->toDateString();
            }
            if ($loan->status === 'lunas') {
                $loan->outstanding = 0;
            }
            if (in_array($loan->status, ['disetujui', 'ditolak'], true) && ! $loan->decided_at) {
                $loan->decided_at = now();
            }
            if ($loan->status === 'dibatalkan' && ! $loan->cancelled_at) {
                $loan->cancelled_at = now();
                $loan->cancel_reason ??= 'Dibatalkan anggota';
            }
        });
    }

    /** Sudah dicairkan & berjalan: sisa = pokok + bunga. */
    public function running(): static
    {
        return $this->state(fn (array $a) => ['status' => 'berjalan', 'outstanding' => $a['principal'] + $a['interest'], 'disbursed_at' => now()->toDateString()]);
    }

    public function overdue(): static
    {
        return $this->running()->state(['status' => 'menunggak']);
    }
}
