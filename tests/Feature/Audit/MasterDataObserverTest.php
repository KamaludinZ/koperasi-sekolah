<?php

namespace Tests\Feature\Audit;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Member;
use App\Models\Product;
use App\Services\AuditLog;
use App\Services\MemberService;
use App\Services\ProductService;
use App\Services\SettingsService;
use Database\Seeders\MemberSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataObserverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    private function last(string $event): ?ActivityLog
    {
        return ActivityLog::where('event', $event)->latest('id')->first();
    }

    public function test_seeders_do_not_flood_the_audit_log(): void
    {
        $this->seed(MemberSeeder::class);
        $this->assertSame(0, ActivityLog::masterChanges()->count());
    }

    public function test_product_create_update_and_delete_are_recorded_once_with_values(): void
    {
        $service = app(ProductService::class);
        $data = ['code' => 'IBD001', 'name' => 'Peci Hitam', 'category' => 'Seragam', 'price' => 25000, 'cost_price' => 20000,
            'stock' => 5, 'min_stock' => 2, 'unit' => 'pcs', 'is_active' => true];
        $p = $service->create($data);

        $created = $this->last('product.created');
        $this->assertSame(1, ActivityLog::where('event', 'product.created')->count());
        $this->assertSame('dibuat', $created->action);
        $this->assertSame('IBD001 · Peci Hitam', $created->subject_label);
        $this->assertSame(['type' => 'product', 'id' => $p->id], ['type' => $created->subject_type, 'id' => $created->subject_id]);
        $this->assertNull($created->old_values);
        $this->assertSame(25000, $created->new_values['price']);
        $this->assertSame('Seragam', $created->new_values['category_id']);
        $this->assertArrayNotHasKey('stock', $created->new_values);

        AuditLog::withReason('Harga dari pemasok naik', fn () => $service->update($p, [...$data, 'price' => 27000, 'category' => 'Alat Tulis']));
        $updated = $this->last('product.updated');
        $this->assertSame(1, ActivityLog::where('event', 'product.updated')->count());
        $this->assertSame('diubah', $updated->action);
        $this->assertSame(['category_id' => 'Seragam', 'price' => 25000], $updated->old_values);
        $this->assertSame(['category_id' => 'Alat Tulis', 'price' => 27000], $updated->new_values);
        $this->assertSame('Harga dari pemasok naik', $updated->reason);
        $this->assertStringContainsString('Data barang Peci Hitam diubah: category_id, price', $updated->description);

        // Simpan tanpa perubahan kolom yang dipantau (hanya stok) → tidak ada baris "diubah" baru.
        $service->update($p->refresh(), [...$data, 'price' => 27000, 'category' => 'Alat Tulis', 'stock' => 9]);
        $this->assertSame(1, ActivityLog::where('event', 'product.updated')->count());
        $this->assertSame(1, ActivityLog::where('event', 'stock.adjusted')->count());

        $p->refresh()->update(['is_active' => false]);
        $this->assertSame(['is_active' => true], $this->last('product.deactivated')->old_values);

        $p->stockMovements()->delete();
        $p->delete();
        $deleted = ActivityLog::where('subject_type', 'product')->where('subject_id', $p->id)->where('action', 'dihapus')->first();
        $this->assertSame('Peci Hitam', $deleted->old_values['name']);
        $this->assertNull($deleted->new_values);
    }

    public function test_member_events_keep_existing_names_and_descriptions(): void
    {
        $service = app(MemberService::class);
        $m = $service->create(['name' => 'Andi', 'type' => 'siswa', 'class' => '7A']);
        $this->assertStringContainsString('Anggota baru Andi (7A)', $this->last('member.created')->description);
        $this->assertSame($m->member_code.' · Andi', $this->last('member.created')->subject_label);

        $service->update($m, ['member_code' => $m->member_code, 'name' => 'Andi', 'type' => 'siswa', 'class' => '8A', 'is_active' => true]);
        $this->assertSame(['class' => '7A'], $this->last('member.updated')->old_values);
        $this->assertSame(['class' => ['from' => '7A', 'to' => '8A']], $this->last('member.updated')->properties['changes']);

        $service->update($m->refresh(), ['member_code' => $m->member_code, 'name' => 'Andi', 'type' => 'siswa', 'class' => '8A', 'is_active' => false]);
        $this->assertNotNull($this->last('member.deactivated'));

        $service->delete($m->refresh(), 'Pendaftaran ganda');
        $deleted = $this->last('member.deleted');
        $this->assertSame('dihapus', $deleted->action);
        $this->assertSame('Pendaftaran ganda', $deleted->reason);
        $this->assertFalse($deleted->properties['permanent']);

        $m->restore();
        $this->assertSame('dipulihkan', $this->last('member.restored')->action);
        $this->assertNull($m->fresh()->delete_reason);

        $m->forceDelete();
        $this->assertTrue($this->last('member.purged')->properties['permanent']);
    }

    public function test_category_changes_and_quiet_mode(): void
    {
        $c = Category::create(['name' => 'Perlengkapan Ibadah']);
        $c->update(['name' => 'Ibadah']);
        $this->assertSame(['name' => 'Perlengkapan Ibadah'], $this->last('category.updated')->old_values);
        $this->assertSame('barang', $this->last('category.updated')->category);

        $before = ActivityLog::count();
        AuditLog::quietly(fn () => Category::create(['name' => 'Diam']));
        $this->assertSame($before, ActivityLog::count());
        $this->assertFalse(AuditLog::isQuiet());
        $this->assertNull(AuditLog::reason());
    }

    public function test_settings_store_dotted_before_after_values(): void
    {
        $settings = app(SettingsService::class);
        $settings->save('tax', ['enabled' => true, 'rate' => 2.5]);
        $log = $this->last('setting.updated');
        $this->assertSame('diubah', $log->action);
        $this->assertSame(['type' => 'setting', 'label' => 'Pajak & pembulatan'], ['type' => $log->subject_type, 'label' => $log->subject_label]);
        $this->assertSame(['tax.enabled' => true, 'tax.rate' => 2.5], $log->new_values);

        $settings->reset('tax');
        $reset = $this->last('setting.reset');
        $this->assertSame(['tax.enabled' => true, 'tax.rate' => 2.5], $reset->old_values);
        $this->assertSame(['tax.enabled' => false, 'tax.rate' => 0], $reset->new_values);
    }
}
