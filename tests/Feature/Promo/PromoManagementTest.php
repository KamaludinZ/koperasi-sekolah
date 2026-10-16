<?php

namespace Tests\Feature\Promo;

use App\Livewire\Promo\CouponForm;
use App\Livewire\Promo\Index;
use App\Livewire\Promo\PromoForm;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Promo;
use App\Models\Transaction;
use App\Models\TransactionDiscount;
use App\Services\CouponService;
use App\Services\PromoEngine;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class PromoManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00'); // Rabu
        $this->seed(ProductSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_create_category_promo_applies_at_cashier_and_is_audited(): void
    {
        $atk = Category::where('name', 'Alat Tulis')->value('id');
        $this->get('/promo')->assertOk()->assertSee(route('promo.buat'))->assertSee(route('promo.kupon.buat'))->assertDontSee('segera');
        $this->get('/promo/baru')->assertOk()->assertSee('Promo baru');

        Livewire::test(PromoForm::class)
            ->set('name', '  Diskon   ATK ')->set('type', 'persen')->set('value', 10)->set('min_qty', 1)->set('min_purchase', 0)
            ->set('scope', 'kategori')->set('category_ids', [(string) $atk])
            ->set('days', ['1', '2', '3', '4', '5'])
            ->call('save')->assertHasNoErrors()->assertRedirect(route('promo'));

        $promo = Promo::with('targets')->sole();
        $this->assertSame(['Diskon ATK', 'persen', 10, [1, 2, 3, 4, 5], 'kategori', [$atk]],
            [$promo->name, $promo->type, (int) $promo->value, $promo->days, $promo->scope, $promo->targets->pluck('category_id')->all()]);
        $this->assertSame(auth()->id(), $promo->created_by);

        $line = ['id' => $this->pid('ATK001'), 'name' => 'Buku', 'category' => 'Alat Tulis', 'price' => 5000, 'quantity' => 2, 'subtotal' => 10000];
        $this->assertSame(1000, app(PromoEngine::class)->evaluate([$line])['total']);

        $log = ActivityLog::where('subject_type', 'promo')->where('action', 'dibuat')->sole();
        $this->assertSame('promo', $log->category);
        $this->assertSame('Sen, Sel, Rab, Kam, Jum', $log->new_values['days']);
        $this->assertSame('Kategori', $log->new_values['scope']);
    }

    public function test_product_picker_and_buy_get_free_rules(): void
    {
        $id = $this->pid('ATK001');
        $c = Livewire::test(PromoForm::class)->set('scope', 'barang')->set('productSearch', 'ATK001')
            ->assertSee('ATK001')->call('addProduct', $id)->assertSet('product_ids', [$id])->assertSet('productSearch', '');
        $c->set('name', 'Beli 2 gratis 1')->set('type', 'beli_gratis')->set('buy_qty', 2)->set('free_qty', 1)
            ->call('save')->assertHasNoErrors();
        $p = Promo::sole();
        $this->assertSame([2, 1, 3, 0], [$p->buy_qty, $p->free_qty, $p->min_qty, (int) $p->value]);

        // Ubah: cakupan berubah → tercatat terpisah; jam berlaku.
        Livewire::test(PromoForm::class, ['promo' => $p->id])->assertSet('product_ids', [$id])
            ->call('removeProduct', $id)->set('scope', 'semua')->set('timed', true)->set('time_from', '13:00')->set('time_to', '14:30')
            ->call('save')->assertHasNoErrors();
        $p->refresh();
        $this->assertSame(['semua', '13:00', '14:30', 0], [$p->scope, substr($p->time_from, 0, 5), substr($p->time_to, 0, 5), $p->targets()->count()]);
        $this->assertTrue(ActivityLog::where('subject_type', 'promo')->where('description', 'like', 'Sasaran promo%')->exists());
    }

    public function test_promo_validation(): void
    {
        Livewire::test(PromoForm::class)->set('name', 'X')->set('type', 'persen')->set('value', 150)->set('scope', 'kategori')
            ->set('days', [])->set('timed', true)->set('time_from', '14:00')->set('time_to', '13:00')
            ->set('ends_on', today()->subDay()->toDateString())
            ->call('save')->assertHasErrors(['name' => 'min', 'value' => 'max', 'category_ids' => 'required', 'days' => 'required', 'time_to' => 'after', 'ends_on'])
            ->assertDispatched('toast', type: 'error');
        Livewire::test(PromoForm::class)->set('name', 'Grosir')->set('type', 'grosir')->set('value', 3000)->set('min_qty', 1)->set('scope', 'barang')
            ->call('save')->assertHasErrors(['min_qty' => 'min', 'product_ids' => 'required']);
        Livewire::test(PromoForm::class)->set('name', 'Kategori palsu')->set('value', 5)->set('scope', 'kategori')->set('category_ids', ['999999'])
            ->call('save')->assertHasErrors('category_ids.*');
        $this->assertSame(0, Promo::count());
    }

    public function test_coupon_create_edit_and_rules(): void
    {
        $this->get('/promo/kupon/baru')->assertOk()->assertSee('Kupon baru');
        Livewire::test(CouponForm::class)->set('code', ' mpls 2026 ')->assertSet('code', 'MPLS2026')
            ->set('name', 'Voucher siswa baru')->set('type', 'nominal')->set('value', 5000)->set('min_purchase', 20000)
            ->set('quota', 2)->set('per_member', 1)->call('save')->assertHasNoErrors()->assertRedirect(route('promo'));
        $c = Coupon::sole();
        $this->assertSame(['MPLS2026', 5000, 2, 1, null], [$c->code, (int) $c->value, $c->quota, $c->per_member, $c->max_discount]);
        $this->assertTrue(app(CouponService::class)->check('mpls2026', 25000)['ok']);

        Livewire::test(CouponForm::class)->set('code', 'MPLS2026')->set('name', 'Duplikat')->set('value', 1000)->call('save')->assertHasErrors('code');
        Livewire::test(CouponForm::class)->set('code', 'A B!')->set('name', 'Kode aneh')->set('value', 1000)->call('save')->assertHasErrors(['code' => 'regex']);
        Coupon::whereKey($c->id)->update(['used_count' => 2]);
        $c->update(['used_count' => 2]);
        Livewire::test(CouponForm::class, ['coupon' => $c->id])->assertSet('code', 'MPLS2026')->set('quota', 1)->call('save')->assertHasErrors(['quota' => 'min']);
        Livewire::test(CouponForm::class, ['coupon' => $c->id])->set('type', 'persen')->set('value', 10)->set('max_discount', 3000)->set('quota', '')
            ->call('save')->assertHasNoErrors();
        $c->refresh();
        $this->assertSame(['persen', 10, 3000, null], [$c->type, (int) $c->value, (int) $c->max_discount, $c->quota]);
        $log = ActivityLog::where('subject_type', 'coupon')->where('action', 'diubah')->latest('id')->first();
        $this->assertSame('Potongan persen', $log->new_values['type']);
        $this->assertSame('promo', $log->category);
    }

    public function test_toggle_and_delete_only_when_unused(): void
    {
        $promo = Promo::create(['name' => 'Coba', 'type' => 'nominal', 'value' => 500, 'scope' => 'semua', 'starts_on' => today(), 'ends_on' => today()->addWeek(), 'days' => [1, 2, 3, 4, 5, 6, 7]]);
        $coupon = Coupon::create(['code' => 'COBA', 'name' => 'Kupon coba', 'type' => 'nominal', 'value' => 1000, 'starts_on' => today(), 'ends_on' => today()->addWeek()]);

        Livewire::test(Index::class)->assertSee(route('promo.ubah', $promo->id))->assertSee(route('promo.kupon.ubah', $coupon->id))
            ->call('togglePromo', $promo->id)->assertDispatched('toast', type: 'success')
            ->call('toggleCoupon', $coupon->id);
        $this->assertFalse($promo->fresh()->is_active);
        $this->assertFalse($coupon->fresh()->is_active);
        $this->assertSame([], app(PromoEngine::class)->livePromos());
        $this->assertTrue(ActivityLog::where('event', 'promo.deactivated')->exists());
        $this->assertTrue(ActivityLog::where('event', 'coupon.deactivated')->exists());

        // Sudah dipakai → tidak bisa dihapus.
        $trx = Transaction::factory()->create();
        TransactionDiscount::create(['transaction_id' => $trx->id, 'source' => 'promo', 'promo_id' => $promo->id, 'label' => 'Coba', 'amount' => 500]);
        Livewire::test(PromoForm::class, ['promo' => $promo->id])->assertSee('tidak bisa dihapus')->call('delete')->assertDispatched('toast', type: 'error');
        $this->assertNotNull($promo->fresh());

        Livewire::test(CouponForm::class, ['coupon' => $coupon->id])->call('delete')->assertRedirect(route('promo'));
        $this->assertNull($coupon->fresh());
        $this->assertTrue(ActivityLog::where('event', 'coupon.purged')->exists());
    }

    public function test_cashier_cannot_manage(): void
    {
        $promo = Promo::create(['name' => 'Coba', 'type' => 'nominal', 'value' => 500, 'scope' => 'semua', 'starts_on' => today(), 'ends_on' => today()->addWeek(), 'days' => [1, 2, 3]]);
        $this->loginAs('kasir');
        $this->get('/promo')->assertOk()->assertDontSee(route('promo.buat'))->assertDontSee('Nonaktifkan');
        $this->get('/promo/baru')->assertForbidden();
        $this->get("/promo/{$promo->id}/ubah")->assertForbidden();
        $this->get('/promo/kupon/baru')->assertForbidden();
        Livewire::test(Index::class)->call('togglePromo', $promo->id)->assertForbidden();
        $this->assertTrue($promo->fresh()->is_active);

        $this->loginAs('pengurus');
        $this->get('/promo/baru')->assertOk();
        $this->get('/promo/999999/ubah')->assertNotFound();
    }
}
