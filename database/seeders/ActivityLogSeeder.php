<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\SavingsTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AuditIntegrity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Riwayat audit contoh dari data demo yang sudah ada (hanya pengembangan):
 * login pagi/keluar siang tiap petugas pada hari ada penjualan, penjualan, dan
 * setor/tarik tabungan, ditambah beberapa percobaan login gagal.
 * Dilewati bila tabel audit sudah berisi.
 */
class ActivityLogSeeder extends Seeder
{
    public function run(): void
    {
        if (ActivityLog::exists()) {
            return;
        }

        $staff = User::with('role')->get()->keyBy('name')->map(fn ($u) => ['id' => $u->id, 'role' => $u->role?->name, 'label' => $u->role?->label]);
        $actor = fn (?string $name) => [
            'actor_id' => $staff[$name]['id'] ?? null,
            'actor_name' => $name ?? 'Sistem',
            'actor_role' => $staff[$name]['role'] ?? 'kasir',
        ];
        $base = ['ip_address' => '192.168.1.20', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/128 (data contoh)', 'amount' => null, 'properties' => null,
            'subject_type' => null, 'subject_id' => null, 'subject_label' => null];
        $rows = [];

        $sales = Transaction::with('user:id,name')->orderBy('created_at')->get();
        foreach ($sales->groupBy(fn ($t) => $t->created_at->toDateString()) as $day) {
            foreach ($day->groupBy(fn ($t) => $t->user?->name ?? 'Kasir') as $name => $txs) {
                $rows[] = [...$base, ...$actor($name), 'event' => 'auth.login', 'category' => 'auth',
                    'description' => "{$name} masuk sebagai ".($staff[$name]['label'] ?? 'Kasir'), 'created_at' => $txs->first()->created_at->copy()->subMinutes(12)];
                $rows[] = [...$base, ...$actor($name), 'event' => 'auth.logout', 'category' => 'auth',
                    'description' => "{$name} keluar", 'created_at' => $txs->last()->created_at->copy()->addMinutes(20)];
            }
        }

        foreach ($sales as $t) {
            $rows[] = [...$base, ...$actor($t->user?->name ?? 'Kasir'), 'event' => 'sale.created', 'category' => 'transaksi',
                'description' => "Penjualan {$t->invoice_number}: {$t->item_count} barang, ".($t->payment_method === 'qris' ? 'QRIS / Transfer' : 'Tunai'),
                'subject_type' => 'transaction', 'subject_id' => $t->id, 'subject_label' => $t->invoice_number,
                'amount' => (int) $t->total, 'created_at' => $t->created_at];
        }

        foreach (SavingsTransaction::with(['member:id,name,member_code', 'user:id,name'])->get() as $s) {
            $deposit = $s->type === 'setor';
            $rows[] = [...$base, ...$actor($s->user?->name), 'event' => $deposit ? 'savings.deposit' : 'savings.withdraw', 'category' => 'tabungan',
                'description' => ($deposit ? 'Setoran' : 'Penarikan').' tabungan '.$s->member->name.': Rp'.number_format((int) $s->amount, 0, ',', '.'),
                'subject_type' => 'member', 'subject_id' => $s->member_id, 'subject_label' => "{$s->member->member_code} · {$s->member->name}",
                'amount' => (int) $s->amount, 'properties' => json_encode(['balance_after' => (int) $s->balance_after]), 'created_at' => $s->created_at];
        }

        // Beberapa percobaan login gagal untuk contoh pemantauan.
        foreach ([3, 9, 20] as $daysAgo) {
            $rows[] = [...$base, 'actor_id' => null, 'actor_name' => 'tamu@contoh.test', 'actor_role' => null, 'ip_address' => '10.0.0.'.$daysAgo,
                'event' => 'auth.failed', 'category' => 'auth', 'description' => 'Gagal masuk: kata sandi salah atau akun tidak dikenal (tamu@contoh.test)',
                'created_at' => now()->subDays($daysAgo)->setTime(6, 40)];
        }

        usort($rows, fn ($a, $b) => $a['created_at'] <=> $b['created_at']);
        $rows = array_map(fn ($r) => [...$r, 'checksum' => AuditIntegrity::checksum($r)], $rows);
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('activity_logs')->insert($chunk);
        }
    }
}
