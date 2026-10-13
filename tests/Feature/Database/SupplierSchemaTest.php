<?php

namespace Tests\Feature\Database;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Supplier;
use App\Services\TrashService;
use Database\Seeders\ProductSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ProductSeeder::class, SupplierSeeder::class]);
    }

    public function test_seeded_suppliers_with_categories(): void
    {
        $this->assertSame(4, Supplier::count());
        $this->assertSame(3, Supplier::active()->count());
        $berkah = Supplier::where('name', 'UD Berkah Pangan')->first();
        $this->assertSame(['Makanan', 'Minuman'], $berkah->categories->pluck('name')->all());
        $this->assertSame(['Tempo 7 hari', true], [$berkah->termsLabel(), $berkah->isCredit()]);
        $this->assertSame('Tunai', Supplier::where('terms_days', 0)->first()->termsLabel());
        $this->assertSame(0, ActivityLog::where('event', 'like', 'supplier.%')->count()); // seeder senyap
    }

    public function test_name_unique_among_active_case_insensitive(): void
    {
        $sinar = Supplier::where('name', 'CV Sinar Ilmu')->first();
        try {
            \DB::transaction(fn () => Supplier::factory()->create(['name' => 'cv sinar ilmu']));
            $this->fail('Nama dobel seharusnya ditolak.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        $sinar->trash('Tutup');
        $new = Supplier::factory()->create(['name' => 'CV Sinar Ilmu']);
        $this->assertNotNull($new->id);
        $this->assertStringContainsString('sudah dipakai supplier aktif', app(TrashService::class)->conflict('supplier', Supplier::withTrashed()->find($sinar->id)));
    }

    public function test_changes_are_audited_and_category_link_cascades(): void
    {
        $s = Supplier::factory()->create(['name' => 'Toko Buku Cahaya', 'terms_days' => 0]);
        $s->update(['terms_days' => 14]);
        $log = ActivityLog::where('event', 'supplier.updated')->sole();
        $this->assertSame(['pengadaan', ['terms_days' => 0], ['terms_days' => 14]], [$log->category, $log->old_values, $log->new_values]);

        $s->categories()->sync(Category::pluck('id'));
        $s->forceDelete();
        $this->assertSame(0, \DB::table('category_supplier')->where('supplier_id', $s->id)->count());
    }
}
