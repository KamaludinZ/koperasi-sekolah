<?php

namespace Tests\Feature\Database;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Services\ProductCatalog;
use Database\Seeders\ProductSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CategorySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_columns_defaults_and_positions(): void
    {
        $this->assertTrue(Schema::hasColumns('categories', ['color', 'position', 'description']));
        $this->seed(ProductSeeder::class);
        $this->assertSame(['Alat Tulis', 'Makanan', 'Minuman', 'Seragam'], Category::ordered()->pluck('name')->all());
        $this->assertSame([1, 2, 3, 4], Category::ordered()->pluck('position')->all());

        $new = Category::create(['name' => 'Perlengkapan Ibadah']);
        $this->assertSame([5, 'emerald'], [$new->position, $new->fresh()->color]);

        $new->update(['position' => 0]);
        $this->assertSame('Perlengkapan Ibadah', Category::ordered()->first()->name);
        $this->assertSame('Perlengkapan Ibadah', app(ProductCatalog::class)->categories()[0] ?? null);
    }

    public function test_color_change_is_audited(): void
    {
        $c = Category::create(['name' => 'Aksesoris']);
        $c->update(['color' => 'rose', 'description' => 'Gantungan kunci, pin']);
        $log = ActivityLog::where('event', 'category.updated')->sole();
        $this->assertSame(['color' => 'emerald', 'description' => null], $log->old_values);
    }

    public function test_pgsql_rejects_unknown_color(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraint khusus PostgreSQL.');
        }
        $this->expectException(QueryException::class);
        Category::create(['name' => 'Ungu', 'color' => 'ungu']);
    }
}
