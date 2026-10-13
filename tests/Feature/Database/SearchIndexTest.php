<?php

namespace Tests\Feature\Database;

use App\Services\ProductCatalog;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SearchIndexTest extends TestCase
{
    use RefreshDatabase;

    private function indexNames(): array
    {
        return DB::getDriverName() === 'pgsql'
            ? collect(DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = current_schema()"))->pluck('indexname')->all()
            : collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'index'"))->pluck('name')->all();
    }

    public function test_expression_and_trigram_indexes_exist(): void
    {
        $names = $this->indexNames();
        $this->assertContains('products_code_lower_index', $names);
        if (DB::getDriverName() === 'pgsql') {
            foreach (['products_name_trgm', 'members_name_trgm', 'suppliers_name_trgm', 'transactions_invoice_trgm', 'activity_logs_label_trgm'] as $idx) {
                $this->assertContains($idx, $names);
            }
        }
    }

    public function test_searches_still_work(): void
    {
        $this->seed(ProductSeeder::class);
        $this->assertNotNull(app(ProductCatalog::class)->findByCode('atk001'));
        $this->assertNotEmpty(app(ProductCatalog::class)->search('pul', ''));
    }
}
