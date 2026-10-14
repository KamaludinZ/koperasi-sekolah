<?php

namespace Database\Seeders;

use App\Models\Loan;
use App\Models\Member;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Pinjaman contoh untuk guru (hanya pengembangan/demo): satu per status utama —
 * diajukan, disetujui, berjalan, menunggak, lunas — lengkap dengan pencairan & jadwal angsuran.
 * Dilewati bila tabel pinjaman sudah berisi atau belum ada anggota guru.
 */
class LoanSeeder extends Seeder
{
    public function run(): void
    {
        AuditLog::quietly(fn () => $this->seedRows());
    }

    private function seedRows(): void
    {
        $teachers = Member::where('type', 'guru')->orderBy('id')->get();
        if (Loan::exists() || $teachers->isEmpty()) {
            return;
        }
        $staff = User::whereHas('role', fn ($q) => $q->where('name', 'pengurus'))->value('id')
            ?? User::orderBy('id')->value('id');
        $today = today();

        // [status, pokok, bunga, tenor, metode, keperluan, cair (bulan lalu), angsuran dibayar]
        $plans = [
            ['diajukan', 2_000_000, 100_000, 10, 'potong_gaji', 'Renovasi rumah', null, 0],
            ['disetujui', 1_000_000, 50_000, 5, 'transfer', 'Biaya sekolah anak', null, 0],
            ['berjalan', 3_000_000, 150_000, 10, 'potong_gaji', 'Membeli laptop mengajar', 4, 4],
            ['menunggak', 1_500_000, 75_000, 6, 'tunai', 'Biaya pengobatan', 5, 2],
            ['lunas', 600_000, 30_000, 3, 'potong_gaji', 'Keperluan keluarga', 6, 3],
        ];

        DB::transaction(function () use ($plans, $teachers, $staff, $today) {
            foreach ($plans as $i => [$status, $principal, $interest, $tenor, $method, $purpose, $monthsAgo, $paidCount]) {
                $member = $teachers[$i % $teachers->count()];
                $disbursed = $monthsAgo ? $today->copy()->subMonthsNoOverflow($monthsAgo)->startOfMonth()->addDays(4) : null;
                $applied = ($disbursed ?? $today)->copy()->subDays(7 + $i);
                $total = $principal + $interest;
                $per = intdiv($total, $tenor);

                $loan = Loan::create([
                    'code' => Loan::nextCode($applied), 'member_id' => $member->id, 'principal' => $principal, 'interest' => $interest,
                    'tenor_months' => $tenor, 'payment_method' => $method, 'status' => 'diajukan', 'purpose' => $purpose,
                    'outstanding' => 0, 'applied_at' => $applied, 'created_by' => $staff,
                ]);
                if ($status === 'diajukan') {
                    continue;
                }
                $loan->update(['status' => 'disetujui', 'decided_at' => $applied->copy()->addDays(2)->setTime(10, 0), 'decided_by' => $staff,
                    'decision_note' => 'Memenuhi syarat']);
                if (! $disbursed) {
                    continue;
                }

                $loan->disbursements()->create(['disbursed_on' => $disbursed, 'amount' => $principal, 'proof' => 'Kuitansi serah terima', 'user_id' => $staff]);
                $paid = 0;
                foreach (range(1, $tenor) as $n) {
                    $amount = $n === $tenor ? $total - $per * ($tenor - 1) : $per;
                    $due = $disbursed->copy()->addMonthsNoOverflow($n);
                    $isPaid = $n <= $paidCount;
                    $paid += $isPaid ? $amount : 0;
                    $loan->installments()->create([
                        'sequence' => $n, 'due_date' => $due, 'amount' => $amount, 'paid_amount' => $isPaid ? $amount : 0,
                        'paid_at' => $isPaid ? $due->copy()->subDays(1) : null, 'payment_method' => $isPaid ? $method : null,
                        'status' => $isPaid ? 'dibayar' : ($due->lt(today()) ? 'menunggak' : 'belum'), 'user_id' => $isPaid ? $staff : null,
                    ]);
                }
                $outstanding = $total - $paid;
                $loan->update(['status' => 'berjalan', 'disbursed_at' => $disbursed, 'outstanding' => $total]); // cair dulu, lalu status akhir
                $loan->update([
                    'status' => $outstanding === 0 ? 'lunas' : ($loan->installments()->where('status', 'menunggak')->exists() ? 'menunggak' : 'berjalan'),
                    'disbursed_at' => $disbursed, 'outstanding' => $outstanding,
                    'paid_off_at' => $outstanding === 0 ? $disbursed->copy()->addMonthsNoOverflow($tenor)->subDay() : null,
                ]);
            }
        });
    }
}
