<?php

namespace Tests\Feature\Database;

use App\Models\Product;
use App\Models\Transaction;
use Database\Seeders\ProductSeeder;
use Database\Seeders\TransactionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        // Rabu sore: jam transaksi contoh diacak 07–14, jadi hari ini pasti punya transaksi (pagi hari bisa kosong
        // bila semua jam acak jatuh setelah "sekarang" — dulu membuat tes ini gagal sesekali).
        $this->travelTo('2026-10-14 15:30:00');
    }

    public function test_today_transactions_never_in_the_future(): void
    {
        $this->travelTo('2026-10-14 09:00:00');
        $this->seed(TransactionSeeder::class);
        $this->assertSame(0, Transaction::where('created_at', '>', now())->count());
        $this->assertTrue(Transaction::where('created_at', '>=', today())->get()->every(fn ($t) => $t->created_at->lte(now())));
    }

    public function test_seeds_consistent_history_without_touching_stock(): void
    {
        $stock = Product::pluck('stock', 'id')->all();

        $this->seed(TransactionSeeder::class);

        $this->assertGreaterThan(100, Transaction::count());
        $this->assertSame($stock, Product::pluck('stock', 'id')->all());
        $this->assertSame(0, Transaction::where('created_at', '>', now())->count());
        $this->assertFalse(Transaction::pluck('created_at')->contains(fn ($at) => $at->isSunday()));
        $this->assertTrue(Transaction::where('created_at', '>=', today())->exists());

        // Total & jumlah barang cocok dengan itemnya.
        Transaction::with('items')->limit(20)->get()->each(function (Transaction $t) {
            $this->assertEquals($t->items->sum('subtotal'), $t->total);
            $this->assertSame($t->items->sum('quantity'), $t->item_count);
            $this->assertGreaterThanOrEqual($t->total, $t->paid);
        });

        // Diproses kasir contoh, nomor invoice unik per hari.
        $this->assertSame(0, Transaction::whereNull('user_id')->count());
        $this->assertSame(Transaction::count(), Transaction::distinct()->count('invoice_number'));
    }

    public function test_skips_when_transactions_exist(): void
    {
        $this->seed(TransactionSeeder::class);
        $count = Transaction::count();

        $this->seed(TransactionSeeder::class);

        $this->assertSame($count, Transaction::count());
    }
}
