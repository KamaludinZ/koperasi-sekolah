<?php

namespace Tests\Feature\Simpanan;

use App\Support\DatabaseIndexes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SavingIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_indexes_exist(): void
    {
        $names = fn ($t) => collect(Schema::getIndexes($t))->pluck('name')->all();
        $this->assertContains('member_saving_entries_member_type_period_index', $names('member_saving_entries'));
        $this->assertContains('saving_dues_type_period_status_index', $names('saving_dues'));

        $missing = collect(DatabaseIndexes::report())->flatMap(fn ($t) => collect($t['checks'])->where('ok', false)->map(fn ($c) => $t['table'].'.'.$c['column']))->all();
        $this->assertSame([], $missing);
    }
}
