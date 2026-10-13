<?php

namespace Tests\Feature\Database;

use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SavingsTransactionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_relations_casts_and_labels(): void
    {
        $member = Member::factory()->create();
        $user = User::factory()->create();
        $s = SavingsTransaction::create([
            'member_id' => $member->id, 'type' => 'tarik', 'amount' => 3000,
            'balance_before' => 10000, 'balance_after' => 7000, 'user_id' => $user->id,
        ])->fresh();

        $this->assertTrue($s->member->is($member));
        $this->assertTrue($s->user->is($user));
        $this->assertSame('3000.00', $s->amount);
        $this->assertSame(-3000, $s->signed_amount);
        $this->assertSame('Penarikan', $s->type_label);
        $this->assertNotNull($s->created_at);
        $this->assertSame(1, $member->savings()->count());
    }

    public function test_scopes(): void
    {
        $m = Member::factory()->create();
        $old = SavingsTransaction::factory()->for($m)->create(['created_at' => '2026-10-01 08:00:00']);
        $new = SavingsTransaction::factory()->for($m)->create(['created_at' => '2026-10-10 08:00:00']);

        $this->assertSame([$new->id, $old->id], $m->savings()->latestFirst()->pluck('id')->all());
        $this->assertSame(0, $m->savings()->ofType('tarik')->count());
        $this->assertSame([$new->id], $m->savings()->betweenDates(now()->parse('2026-10-05'), null)->pluck('id')->all());
    }

    public function test_member_with_history_cannot_be_deleted(): void
    {
        $m = Member::factory()->create();
        SavingsTransaction::factory()->for($m)->create();

        $this->deleteJson("/anggota/{$m->id}")->assertJsonValidationErrors('member');
        $this->assertModelExists($m);

        $this->expectException(QueryException::class);
        $m->forceDelete(); // FK restrict
    }

    public function test_database_constraints_on_pgsql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint hanya di PostgreSQL.');
        }

        $m = Member::factory()->create();
        $base = ['member_id' => $m->id, 'type' => 'setor', 'created_at' => now()];

        foreach ([
            ['amount' => 0, 'balance_before' => 0, 'balance_after' => 0],         // nominal harus > 0
            ['amount' => 5000, 'balance_before' => 0, 'balance_after' => 4000],   // saldo tidak cocok
            ['amount' => 5000, 'balance_before' => 0, 'balance_after' => -5000, 'type' => 'tarik'], // saldo negatif
            ['amount' => 5000, 'balance_before' => 0, 'balance_after' => 5000, 'type' => 'bunga'],  // jenis tak dikenal
        ] as $row) {
            try {
                DB::transaction(fn () => DB::table('savings_transactions')->insert([...$base, ...$row]));
                $this->fail('Seharusnya ditolak: '.json_encode($row));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
