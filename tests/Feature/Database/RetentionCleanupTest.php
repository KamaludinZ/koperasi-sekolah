<?php

namespace Tests\Feature\Database;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Member;
use App\Models\Notification;
use App\Models\Product;
use App\Models\ReportExport;
use App\Models\SavingsTransaction;
use App\Models\Transaction;
use App\Services\RetentionService;
use App\Services\SettingsService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RetentionCleanupTest extends TestCase
{
    use RefreshDatabase;

    private Product $oldProduct;

    private Product $freshProduct;

    private Category $oldCategory;

    private Member $oldMember;

    private Member $saver;

    private Transaction $tx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        Storage::fake('local');

        $this->travelTo('2026-09-01 08:00:00');
        $this->oldCategory = Category::create(['name' => 'Aksesoris']);
        $this->oldProduct = Product::create(['code' => 'AKS1', 'name' => 'Gantungan', 'price' => 1000, 'category_id' => $this->oldCategory->id]);
        $this->oldMember = Member::factory()->create();
        $this->tx = Transaction::factory()->create(['member_id' => $this->oldMember->id]);
        $this->saver = Member::factory()->create();
        SavingsTransaction::factory()->for($this->saver)->create();
        foreach ([$this->oldProduct, $this->oldCategory, $this->oldMember, $this->saver] as $m) {
            $m->trash('Uji retensi');
        }

        Storage::disk('local')->put('exports/kas/lama.csv', 'x');
        ReportExport::create(['user_id' => auth()->id(), 'type' => 'kas', 'format' => 'csv', 'status' => 'done', 'file_path' => 'exports/kas/lama.csv', 'params' => []]);
        $read = Notification::create(['type' => 'sistem', 'title' => 'Lama dibaca', 'message' => 'x']);
        DB::table('notification_reads')->insert(['notification_id' => $read->id, 'user_id' => auth()->id(), 'read_at' => now()]);
        Notification::create(['type' => 'sistem', 'title' => 'Lama belum dibaca', 'message' => 'x']);
        $ann = Notification::create(['type' => 'pengumuman', 'title' => 'Pengumuman tetap', 'message' => 'x']);
        DB::table('notification_reads')->insert(['notification_id' => $ann->id, 'user_id' => auth()->id(), 'read_at' => now()]);

        $this->travelTo('2026-12-15 08:00:00'); // > 30 hari data terhapus, > 90 hari notifikasi
        $this->freshProduct = Product::where('code', 'ATK003')->first();
        $this->freshProduct->trash('Baru dihapus');
        ReportExport::create(['user_id' => auth()->id(), 'type' => 'kas', 'format' => 'csv', 'status' => 'done', 'params' => []]);
    }

    public function test_dry_run_counts_without_deleting(): void
    {
        $counts = app(RetentionService::class)->run(dryRun: true);

        $this->assertSame(['barang' => 1, 'kategori' => 1, 'kategori_dilewati' => 0, 'anggota' => 1, 'anggota_dilewati' => 1, 'ekspor' => 1, 'notifikasi' => 1], $counts);
        $this->assertNotNull(Product::withTrashed()->find($this->oldProduct->id));
        $this->assertSame(0, ActivityLog::where('event', 'retention.purged')->count());
    }

    public function test_purges_expired_data_and_keeps_the_rest(): void
    {
        $this->artisan('retensi:bersihkan')->expectsOutputToContain('Pembersihan masa retensi selesai')->assertExitCode(0);

        $this->assertNull(Product::withTrashed()->find($this->oldProduct->id));
        $this->assertNull(Category::withTrashed()->find($this->oldCategory->id));
        $this->assertNull(Member::withTrashed()->find($this->oldMember->id));
        $this->assertNull($this->tx->fresh()->member_id);
        $this->assertNotNull(Member::withTrashed()->find($this->saver->id)); // riwayat tabungan → dilewati
        $this->assertNotNull(Product::withTrashed()->find($this->freshProduct->id)); // masih dalam masa simpan

        Storage::disk('local')->assertMissing('exports/kas/lama.csv');
        $this->assertSame(1, ReportExport::count());
        $this->assertSame(['Lama belum dibaca', 'Pengumuman tetap'], Notification::where('type', '!=', 'stok')->orderBy('title')->pluck('title')->all());

        $purged = ActivityLog::where('event', 'product.purged')->sole();
        $this->assertSame(RetentionService::REASON, $purged->reason);
        $this->assertSame(1, ActivityLog::where('event', 'retention.purged')->count());

        // Jalan kedua: tidak ada lagi yang dibersihkan, tidak ada catatan ringkasan baru.
        app(RetentionService::class)->run();
        $this->assertSame(1, ActivityLog::where('event', 'retention.purged')->count());
    }

    public function test_longer_retention_setting_keeps_data(): void
    {
        app(SettingsService::class)->save('security', ['trash_days' => 365, 'export_days' => 30, 'notification_days' => 365]);
        $counts = app(RetentionService::class)->run();

        $this->assertSame(0, $counts['barang'] + $counts['kategori'] + $counts['anggota'] + $counts['notifikasi']);
        $this->assertSame(1, $counts['ekspor']); // berkas lama ±105 hari > 30 hari
    }
}
