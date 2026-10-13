<?php

namespace Tests\Feature\Kasir;

use App\Models\Transaction;
use App\Services\CheckoutService;
use App\Services\LogoService;
use App\Services\ReceiptPdfService;
use App\Services\SettingsService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Pengaturan profil & struk dipakai saat mencetak struk (HTML, cetak ulang, PDF). */
class ReceiptSettingsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        Storage::fake('local');
    }

    private function sale(): Transaction
    {
        return app(CheckoutService::class)->checkout([$this->pid('ATK001') => 2], 'tunai', 10000, $this->user('admin@koperasi.test')->id);
    }

    private function user(string $email)
    {
        return \App\Models\User::where('email', $email)->first();
    }

    private function settings(string $section, array $values): void
    {
        app(SettingsService::class)->save($section, $values);
    }

    /** Lebar halaman PDF dalam pt dari /MediaBox. */
    private function pdfSize(string $pdf): array
    {
        preg_match('#/MediaBox \[\s*[\d.]+ [\d.]+ ([\d.]+) ([\d.]+)\s*\]#', $pdf, $m);

        return [(float) $m[1], (float) $m[2]];
    }

    public function test_print_page_uses_saved_profile_and_receipt_settings(): void
    {
        $this->settings('profile', ['name' => "KOPSIS QONA'AH", 'address' => 'Jl. Bandung No. 7 Malang', 'phone' => '0341-551752']);
        $this->settings('receipt', ['subheader' => 'MTsN 2 Kota Malang', 'show_phone' => true, 'show_cashier' => false, 'note' => 'Barang yang sudah dibeli tidak dapat ditukar', 'footer' => 'Jazakumullah khairan']);
        $tx = $this->sale();

        $this->get(route('kasir.transactions.receipt', ['transaction' => $tx->id, 'cetak' => 0]))
            ->assertOk()
            ->assertSee("KOPSIS QONA'AH")
            ->assertSee('MTsN 2 Kota Malang')
            ->assertSee('Jl. Bandung No. 7 Malang')
            ->assertSee('Telp. 0341-551752')
            ->assertDontSee('>Kasir<', false)
            ->assertSee('Barang yang sudah dibeli tidak dapat ditukar')
            ->assertSee('Jazakumullah khairan');

        $this->getJson(route('kasir.transactions.show', $tx->id))
            ->assertJsonPath('profile.receipt.header', "KOPSIS QONA'AH")
            ->assertJsonPath('profile.receipt.show_cashier', false);
    }

    public function test_logo_is_printed_only_when_enabled_and_available(): void
    {
        $tx = $this->sale();
        $url = fn () => route('kasir.transactions.receipt', ['transaction' => $tx->id, 'cetak' => 0]);

        $this->settings('receipt', ['show_logo' => true]);
        $this->get($url())->assertDontSee('/logo/logo-', false); // belum ada logo

        app(LogoService::class)->store(UploadedFile::fake()->image('logo.png', 100, 100));
        $this->get($url())->assertSee('<img src="'.url('/logo'), false);

        $pdf = app(ReceiptPdfService::class)->render($tx);
        $this->assertStringContainsString('/Subtype /Image', $pdf);

        $this->settings('receipt', ['show_logo' => false]);
        $this->get($url())->assertDontSee('<img src="'.url('/logo'), false); // logo hanya tetap jadi ikon tab
        $this->assertStringNotContainsString('/Subtype /Image', app(ReceiptPdfService::class)->render($tx));
    }

    public function test_stored_pdf_is_regenerated_after_settings_change(): void
    {
        $tx = $this->sale();
        $pdfs = app(ReceiptPdfService::class);
        [$w58] = $this->pdfSize($pdfs->contents($tx));
        $this->assertEqualsWithDelta(58 * 2.8346, $w58, 0.1);
        touch(Storage::disk('local')->path($pdfs->path($tx)), time() - 300); // file dibuat 5 menit lalu

        // Tanpa perubahan pengaturan → file lama dipakai ulang.
        $this->assertSame(Storage::disk('local')->lastModified($pdfs->path($tx)), Storage::disk('local')->lastModified($pdfs->store($tx)));

        $this->settings('receipt', ['paper' => '80mm']);
        [$w80] = $this->pdfSize($pdfs->contents($tx));
        $this->assertEqualsWithDelta(80 * 2.8346, $w80, 0.1);
        $this->assertSame('8000.00', $tx->fresh()->total); // angka transaksi tidak berubah
    }

    public function test_pdf_height_grows_with_printed_lines(): void
    {
        $tx = $this->sale();
        [, $plain] = $this->pdfSize(app(ReceiptPdfService::class)->render($tx));

        $this->settings('receipt', ['note' => "Baris satu\nBaris dua\nBaris tiga", 'footer' => str_repeat('Terima kasih ', 10)]);
        [, $longer] = $this->pdfSize(app(ReceiptPdfService::class)->render($tx));

        $this->assertGreaterThan($plain + 40, $longer);
    }
}
