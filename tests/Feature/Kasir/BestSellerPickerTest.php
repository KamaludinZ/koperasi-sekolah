<?php

namespace Tests\Feature\Kasir;

use App\Livewire\Kasir\ProductPicker;
use App\Livewire\Pengaturan\Cashier;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionReturn;
use App\Models\User;
use App\Services\BestSellers;
use App\Services\ProductCatalog;
use App\Services\SettingsService;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** Daftar barang kasir: urut paling laku, batas tampil dari pengaturan Layar kasir, keterangan terpotong, label Terlaris. */
class BestSellerPickerTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Product> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->freezeTime();
        foreach (['Apel' => 'A01', 'Buku' => 'B01', 'Cokelat' => 'C01', 'Donat' => 'D01'] as $name => $code) {
            $this->p[$name] = Product::factory()->create(['name' => $name, 'code' => $code, 'stock' => 50, 'is_active' => true]);
        }
        $this->actingAs(User::where('email', 'admin@koperasi.test')->first(), 'web');
    }

    private function sell(string $name, int $qty, ?string $at = null, bool $voided = false): Transaction
    {
        $tx = Transaction::factory()->withItems([$this->p[$name]->id => $qty])->create(['created_at' => $at ?? now()]);
        if ($voided) {
            $tx->forceFill(['voided_at' => now()])->save();
        }
        Cache::flush();

        return $tx;
    }

    private function names(array $items): array
    {
        return array_column($items, 'name');
    }

    public function test_without_sales_order_is_alphabetical(): void
    {
        $this->assertSame(['Apel', 'Buku', 'Cokelat', 'Donat'], $this->names(app(ProductCatalog::class)->search()));
    }

    public function test_best_sellers_first_excluding_voided_old_and_returned(): void
    {
        $this->sell('Donat', 5);
        $this->sell('Cokelat', 3);
        $this->sell('Buku', 9, now()->subDays(40)->toDateTimeString());   // di luar periode 30 hari
        $this->sell('Apel', 20, null, true);                                // dibatalkan
        $ret = $this->sell('Cokelat', 4);                                   // 3 + 4 = 7, lalu retur 4 disetujui → 3
        $r = TransactionReturn::factory()->create(['transaction_id' => $ret->id, 'status' => 'disetujui', 'decided_at' => now()]);
        $r->items()->delete(); // ganti baris bawaan pabrik dengan retur 4 Cokelat
        $r->items()->create(['transaction_item_id' => $ret->items()->first()->id, 'product_id' => $this->p['Cokelat']->id, 'product_name' => 'Cokelat', 'quantity' => 4, 'unit_price' => 0, 'amount' => 0]);
        Cache::flush();

        $this->assertSame([$this->p['Donat']->id => 5, $this->p['Cokelat']->id => 3], app(BestSellers::class)->ranking(30));
        $items = app(ProductCatalog::class)->search();
        $this->assertSame(['Donat', 'Cokelat', 'Apel', 'Buku'], $this->names($items));
        $this->assertSame([5, 3, 0, 0], array_column($items, 'sold'));

        app(SettingsService::class)->save('cashier', ['bestseller_days' => 60]);       // periode lebih panjang: penjualan 40 hari lalu ikut
        $this->assertSame(['Buku', 'Donat', 'Cokelat', 'Apel'], $this->names(app(ProductCatalog::class)->search()));
    }

    public function test_exact_code_match_stays_on_top_when_searching(): void
    {
        $this->sell('Donat', 10);
        $this->assertSame('Buku', app(ProductCatalog::class)->search('b01')[0]['name']);
        $this->sell('Buku', 1);
        Product::factory()->create(['name' => 'Donat Besar', 'code' => 'D02', 'is_active' => true]);
        $this->assertSame(['Donat', 'Donat Besar'], $this->names(app(ProductCatalog::class)->search('donat')));
    }

    public function test_limit_total_and_settings(): void
    {
        Product::factory()->count(30)->create(['is_active' => true]);
        $r = app(ProductCatalog::class)->searchWithTotal();
        $this->assertSame([34, 34, 60], [count($r['items']), $r['total'], $r['limit']]);
        app(SettingsService::class)->save('cashier', ['picker_limit' => 20]);
        $r = app(ProductCatalog::class)->searchWithTotal();
        $this->assertSame([20, 34, 20], [count($r['items']), $r['total'], $r['limit']]);
        $this->assertCount(5, app(ProductCatalog::class)->search('', '', 5));
        $this->assertCount(34, app(ProductCatalog::class)->search('', '', 9999));         // dibatasi MAX_LIMIT 200
    }

    public function test_picker_shows_truncation_notice_and_best_seller_badges(): void
    {
        Product::factory()->count(25)->create(['is_active' => true]);
        app(SettingsService::class)->save('cashier', ['picker_limit' => 20, 'bestseller_badges' => 1]);
        $this->sell('Donat', 5);
        $this->sell('Cokelat', 2);
        Livewire::test(ProductPicker::class)
            ->assertSeeText('Menampilkan 20 dari 29 barang')->assertSeeHtml('data-picker-truncated')->assertSeeText('9 barang lain tidak ditampilkan')
            ->assertSeeHtml('data-best-seller="'.$this->p['Donat']->id.'"')->assertDontSeeHtml('data-best-seller="'.$this->p['Cokelat']->id.'"')
            ->set('search', 'apel')->assertSeeText('1 barang')->assertDontSeeHtml('data-picker-truncated');
        app(SettingsService::class)->save('cashier', ['bestseller_badges' => 0]);
        Livewire::test(ProductPicker::class)->assertDontSeeHtml('data-best-seller=');
    }

    public function test_api_limit_follows_settings_and_max(): void
    {
        Product::factory()->count(30)->create(['is_active' => true]);
        app(SettingsService::class)->save('cashier', ['picker_limit' => 25]);
        $this->getJson('/kasir/barang/cari')->assertOk()->assertJsonPath('meta.count', 25);
        $this->getJson('/kasir/barang/cari?limit=200')->assertOk()->assertJsonPath('meta.count', 34);
        $this->getJson('/kasir/barang/cari?limit=201')->assertUnprocessable();
    }

    public function test_cashier_settings_page_saves_validates_and_audits(): void
    {
        $this->get('/pengaturan/kasir')->assertOk()->assertSeeText('Daftar barang di kasir');
        Livewire::test(Cashier::class)->set('picker_limit', 10)->call('save')->assertHasErrors('picker_limit')
            ->set('picker_limit', 100)->set('bestseller_days', 7)->set('bestseller_badges', 5)->call('save')->assertHasNoErrors();
        $this->assertSame(['picker_limit' => 100, 'bestseller_days' => 7, 'bestseller_badges' => 5], app(SettingsService::class)->cashier());
        $this->assertNotNull(ActivityLog::where('event', 'setting.updated')->latest('id')->first());

        $this->actingAs(User::where('email', 'kasir@koperasi.test')->first(), 'web');
        $this->get('/pengaturan/kasir')->assertForbidden();
    }
}
