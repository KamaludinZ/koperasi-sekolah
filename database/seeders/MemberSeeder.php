<?php

namespace Database\Seeders;

use App\Models\Member;
use App\Support\MockData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Anggota contoh (12 siswa, 4 guru) beserta riwayat tabungan yang saldonya
 * berantai sampai saldo anggota (hanya untuk pengembangan/demo).
 * Dilewati bila tabel anggota sudah berisi.
 */
class MemberSeeder extends Seeder
{
    public function run(): void
    {
        if (Member::exists()) {
            return;
        }

        $staff = TransactionSeeder::cashiers(); // [Bu Rina, Pak Dedi]

        DB::transaction(function () use ($staff) {
            foreach (MockData::seedMembers() as $row) {
                $member = Member::create(collect($row)->only(['member_code', 'name', 'type', 'class', 'phone', 'is_active', 'joined_at'])->all());

                $before = 0;
                foreach (MockData::seedSavings($row['id']) as $i => $entry) {
                    $member->savings()->create([
                        'type' => $entry['type'],
                        'amount' => $entry['amount'],
                        'balance_before' => $before,
                        'balance_after' => $entry['balance_after'],
                        'note' => $entry['note'],
                        'user_id' => $staff[$i % count($staff)],
                    ])->forceFill(['created_at' => $entry['created_at']])->save();
                    $before = $entry['balance_after'];
                }

                $member->forceFill(['balance' => $before])->save();
            }
        });
    }
}
