<?php

namespace Tests\Feature\Database;

use App\Models\Category;
use App\Models\Product;
use App\Services\RetentionService;
use Database\Seeders\ProductSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CategoryDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_model_refuses_to_delete_used_category(): void
    {
        $atk = Category::where('name', 'Alat Tulis')->first();
        try {
            $atk->delete();
            $this->fail('Kategori yang dipakai seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('masih dipakai 5 barang', $e->errors()['pindah_ke'][0]);
        }
        $this->assertNotSoftDeleted($atk);

        // Barang terhapus lunak tidak menghalangi hapus lunak, tetapi menghalangi hapus permanen.
        Product::where('category_id', $atk->id)->get()->each->delete();
        $this->assertTrue($atk->trash());
        $this->expectException(ValidationException::class);
        $atk->forceDelete();
    }

    public function test_endpoint_reports_guard_message(): void
    {
        $atk = Category::where('name', 'Alat Tulis')->first();
        $this->deleteJson("/barang/kategori/{$atk->id}")->assertJsonValidationErrors('pindah_ke');
        $empty = Category::create(['name' => 'Kosong']);
        $this->deleteJson("/barang/kategori/{$empty->id}")->assertOk();
    }

    public function test_database_restricts_hard_delete(): void
    {
        $atk = Category::where('name', 'Alat Tulis')->first();
        $this->expectException(QueryException::class);
        DB::table('categories')->where('id', $atk->id)->delete();
    }

    public function test_retention_skips_trashed_category_still_used(): void
    {
        $this->travelTo('2026-09-01');
        $used = Category::create(['name' => 'Lama']);
        $empty = Category::create(['name' => 'Kosong']);
        $empty->trash();
        // Kategori terhapus yang masih dipakai (mis. data lama) — paksa lewat query.
        DB::table('categories')->where('id', $used->id)->update(['deleted_at' => now()]);
        Product::first()->update(['category_id' => $used->id]);

        $this->travelTo('2026-12-01');
        $counts = app(RetentionService::class)->run();
        $this->assertSame([1, 1], [$counts['kategori'], $counts['kategori_dilewati']]);
        $this->assertNotNull(Category::withTrashed()->find($used->id));
        $this->assertNull(Category::withTrashed()->find($empty->id));
    }
}
