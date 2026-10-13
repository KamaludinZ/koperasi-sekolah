<?php

namespace Tests\Feature\Database;

use App\Models\Member;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MemberModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_casts_and_display_name(): void
    {
        $m = Member::create(['member_code' => ' s-2024-001 ', 'name' => 'Andi Pratama', 'class' => '7A', 'joined_at' => '2026-07-15'])->fresh();

        $this->assertSame('S-2024-001', $m->member_code);
        $this->assertSame('siswa', $m->type);
        $this->assertTrue($m->is_active);
        $this->assertSame('0.00', $m->balance);
        $this->assertSame('2026-07-15', $m->joined_at->toDateString());
        $this->assertSame('Andi Pratama (7A)', $m->display_name);
        $this->assertSame('Pak Budi (Guru)', Member::factory()->teacher()->make(['name' => 'Pak Budi'])->display_name);
    }

    public function test_balance_is_not_mass_assignable(): void
    {
        $m = Member::create(['member_code' => 'S-9', 'name' => 'Uji', 'class' => '7A', 'joined_at' => '2026-10-01', 'balance' => 999]);
        $this->assertSame('0.00', $m->fresh()->balance);

        $this->assertSame('5000.00', Member::factory()->withBalance(5000)->create()->fresh()->balance);
    }

    public function test_member_code_is_unique_case_insensitively(): void
    {
        Member::factory()->create(['member_code' => 'S-1']);

        $this->expectException(UniqueConstraintViolationException::class);
        Member::factory()->create(['member_code' => 's-1']);
    }

    public function test_scopes(): void
    {
        Member::factory()->create(['name' => 'Siti Nurhaliza', 'class' => '8B']);
        Member::factory()->teacher()->create(['name' => 'Bu Rina']);
        Member::factory()->inactive()->create(['name' => 'Galih', 'class' => '9C']);

        $this->assertSame(2, Member::active()->count());
        $this->assertSame(['Bu Rina'], Member::ofType('guru')->pluck('name')->all());
        $this->assertSame(['Siti Nurhaliza'], Member::search('8b')->pluck('name')->all());
        $this->assertSame(['Siti Nurhaliza'], Member::search('NURHA')->pluck('name')->all());
        $this->assertSame(0, Member::search('%')->count());
    }

    public function test_transactions_link_to_member_and_detach_on_force_delete(): void
    {
        $m = Member::factory()->create();
        $tx = Transaction::factory()->create(['member_id' => $m->id]);

        $this->assertTrue($tx->member->is($m));
        $this->assertSame(1, $m->transactions()->count());

        $m->delete(); // hapus lunak: riwayat tetap terhubung & terbaca
        $this->assertSame($m->id, $tx->fresh()->member_id);
        $this->assertTrue($tx->fresh()->member->is($m));

        $m->forceDelete();
        $this->assertNull($tx->fresh()->member_id);
    }

    public function test_database_constraints_on_pgsql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint hanya di PostgreSQL.');
        }

        $m = Member::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('members')->where('id', $m->id)->update(['balance' => -1]);
    }
}
