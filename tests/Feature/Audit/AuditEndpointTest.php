<?php

namespace Tests\Feature\Audit;

use App\Models\Product;
use App\Services\AuditLog;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        $p = Product::where('code', 'ATK003')->first();
        AuditLog::withReason('Harga dari pemasok naik', fn () => $p->update(['price' => 3000]));
        $p->update(['unit' => 'btg']);
        AuditLog::record('auth.login', 'auth', 'Admin masuk');
    }

    public function test_changes_endpoint_filters_and_shapes_rows(): void
    {
        $res = $this->getJson('/audit/perubahan/data?jenis=barang&sensitif=1')->assertOk();
        $res->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.entity', 'barang')
            ->assertJsonPath('data.0.action', 'diubah')
            ->assertJsonPath('data.0.subject_label', 'ATK003 · Pensil 2B')
            ->assertJsonPath('data.0.changes.price', ['from' => 2500, 'to' => 3000])
            ->assertJsonPath('data.0.reason', 'Harga dari pemasok naik')
            ->assertJsonPath('data.0.sensitive', true)
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.price_changes', 1)
            ->assertJsonPath('meta.total', 1);
        $this->assertNotNull($res->json('data.0.ip_address'));

        $id = Product::where('code', 'ATK003')->value('id');
        $this->getJson("/audit/perubahan/data?objek=barang:{$id}&per_page=1")
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('subject_label', 'ATK003 · Pensil 2B');
        $this->getJson('/audit/perubahan/data?q=pemasok')->assertJsonPath('meta.total', 1);
        $this->getJson('/audit/perubahan/data?aksi=dihapus')->assertJsonPath('meta.total', 0);
    }

    public function test_general_endpoint_and_validation(): void
    {
        $this->getJson('/audit/data?tampil=login')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.event', 'auth.login');
        $this->getJson('/audit/data?kategori=barang')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.changes.unit', ['from' => 'pcs', 'to' => 'btg']);

        $this->getJson('/audit/perubahan/data?jenis=rahasia')->assertUnprocessable()->assertJsonValidationErrors('jenis');
        $this->getJson('/audit/perubahan/data?objek=barang')->assertUnprocessable()->assertJsonValidationErrors('objek');
        $this->getJson('/audit/data?dari=2026-10-10&sampai=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('sampai');
        $this->getJson('/audit/data?per_page=500')->assertUnprocessable();
    }

    public function test_detail_endpoint(): void
    {
        $price = \App\Models\ActivityLog::where('reason', 'Harga dari pemasok naik')->first();

        $this->getJson("/audit/{$price->id}/data")->assertOk()
            ->assertJsonPath('data.id', $price->id)
            ->assertJsonPath('data.subject.label', 'ATK003 · Pensil 2B')
            ->assertJsonPath('data.changes.price', ['from' => 2500, 'to' => 3000])
            ->assertJsonPath('data.reason', 'Harga dari pemasok naik')
            ->assertJsonPath('data.intact', true)
            ->assertJsonCount(1, 'data.related')
            ->assertJsonPath('data.related.0.description', fn ($d) => str_contains($d, 'ATK003') || str_contains($d, 'Pensil'));

        $login = \App\Models\ActivityLog::where('event', 'auth.login')->latest('id')->first();
        $this->getJson("/audit/{$login->id}/data")->assertJsonPath('data.subject', null)->assertJsonPath('data.related', []);
        $this->getJson('/audit/999999/data')->assertNotFound();

        $this->loginAs('pengurus@koperasi.test');
        $this->getJson("/audit/{$price->id}/data")->assertOk()->assertJsonPath('data.ip_address', null);
        $this->loginAs('kasir@koperasi.test');
        $this->getJson("/audit/{$price->id}/data")->assertForbidden();
    }

    public function test_permissions(): void
    {
        $this->loginAs('pengurus@koperasi.test');
        $this->getJson('/audit/perubahan/data')->assertOk()->assertJsonPath('data.0.ip_address', null);
        $this->getJson('/audit/data')->assertOk()->assertJsonPath('data.0.user_agent', null);

        $this->loginAs('kasir@koperasi.test');
        $this->getJson('/audit/perubahan/data')->assertForbidden();
        $this->getJson('/audit/data')->assertForbidden();

        auth()->logout();
        $this->getJson('/audit/data')->assertUnauthorized();
    }
}
