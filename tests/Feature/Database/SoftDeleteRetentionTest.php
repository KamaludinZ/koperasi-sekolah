<?php

namespace Tests\Feature\Database;

use App\Models\Category;
use App\Models\Member;
use App\Models\Product;
use App\Services\ProductService;
use App\Services\SettingsService;
use Database\Seeders\ProductSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class SoftDeleteRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_master_tables_have_soft_delete_columns(): void
    {
        foreach (['products', 'categories', 'members'] as $table) {
            $this->assertTrue(Schema::hasColumns($table, ['deleted_at', 'deleted_by', 'delete_reason']), $table);
        }
    }

    public function test_trash_records_who_and_why_and_hides_row(): void
    {
        $p = Product::where('code', 'ATK003')->first();
        $this->assertTrue($p->trash('Salah input'));

        $trashed = Product::withTrashed()->find($p->id);
        $this->assertNull(Product::find($p->id));
        $this->assertSame('Salah input', $trashed->delete_reason);
        $this->assertSame(auth()->id(), $trashed->deleted_by);
        $this->assertSame(30, Product::retentionDays());
        $this->assertTrue($trashed->purgeAt()->equalTo($trashed->deleted_at->copy()->addDays(30)));

        $trashed->restore();
        $this->assertNull($trashed->fresh()->delete_reason);
        $this->assertNull($trashed->fresh()->deleted_by);
    }

    public function test_codes_of_trashed_rows_can_be_reused_but_active_codes_stay_unique(): void
    {
        $old = Product::where('code', 'ATK003')->first();
        $old->trash();

        $rules = ProductService::rules();
        $data = ['code' => 'ATK003', 'name' => 'Pensil 2B Baru', 'category' => 'Alat Tulis', 'price' => 3000, 'cost_price' => 2400,
            'stock' => 0, 'min_stock' => 5, 'unit' => 'pcs', 'is_active' => true];
        $this->assertTrue(Validator::make($data, $rules)->passes());
        Product::create(['code' => 'ATK003', 'name' => 'Pensil 2B Baru', 'price' => 3000]);

        // Memulihkan yang lama bentrok dengan kode aktif → ditolak database.
        $this->expectException(QueryException::class);
        $old->restore();
    }

    public function test_trashed_category_is_not_selectable_but_name_stays_readable(): void
    {
        $cat = Category::create(['name' => 'Aksesoris']);
        $p = Product::create(['code' => 'AKS1', 'name' => 'Gantungan', 'price' => 1000, 'category_id' => $cat->id]);
        $cat->trash('Digabung');

        $this->assertSame('Aksesoris', $p->fresh()->category->name);
        $v = Validator::make(['category' => 'Aksesoris'], ['category' => ProductService::rules()['category']]);
        $this->assertTrue($v->fails());
    }

    public function test_expired_trash_scope_follows_retention_setting(): void
    {
        $this->travelTo('2026-10-14 10:00:00');
        $m = Member::factory()->create();
        $m->trash();
        $this->travel(29)->days();
        $this->assertSame(0, Member::expiredTrash()->count());
        $this->travel(2)->days();
        $this->assertSame(1, Member::expiredTrash()->count());

        app(SettingsService::class)->save('security', ['trash_days' => 60]);
        $this->assertSame(0, Member::expiredTrash()->count());
    }

    public function test_security_settings_validate_and_normalize(): void
    {
        $rules = SettingsService::rules('security');
        $this->assertTrue(Validator::make(['trash_days' => 6], $rules)->fails());
        $this->assertTrue(Validator::make(['import_types' => ['exe']], $rules)->fails());
        $this->assertSame(['png', 'webp'], SettingsService::normalize('security', ['logo_types' => ['webp', 'png', 'png']])['logo_types']);

        $this->putJson('/pengaturan/data/security', ['trash_days' => 45, 'import_types' => ['xlsx']])->assertOk()
            ->assertJsonPath('data.trash_days', 45)->assertJsonPath('data.import_types', ['xlsx']);
        $this->putJson('/pengaturan/data/security', ['trash_days' => 1000])->assertJsonValidationErrors('trash_days');
    }
}
