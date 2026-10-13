<?php

namespace Tests\Feature\Pengaturan;

use App\Services\SalesExport;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LogoEverywhereTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ProductSeeder::class);
    }

    private function upload(): string
    {
        return $this->postJson('/pengaturan/data/profile/logo', ['logo' => UploadedFile::fake()->image('logo.png', 128, 128)])
            ->assertCreated()->json('data.url');
    }

    public function test_default_mark_is_used_as_tab_icon_without_logo(): void
    {
        $this->get('/kasir')->assertSee('<link rel="icon" type="image/svg+xml" href="'.route('favicon').'">', false)->assertDontSee('apple-touch-icon');
        $this->get('/favicon.ico')->assertRedirect(route('favicon'));
        $this->get('/favicon.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertSee('<svg', false);
    }

    public function test_uploaded_logo_is_applied_to_tab_icon_and_every_layout(): void
    {
        $url = $this->upload();

        $icon = '<link rel="icon" type="image/png" href="'.$url.'">';
        $this->get('/kasir')->assertSee($icon, false)->assertSee('<link rel="apple-touch-icon" href="'.$url.'">', false)
            ->assertSee('<img src="'.$url.'" alt="" class="size-7 shrink-0 rounded-md object-cover">', false); // bar atas layar kecil
        $this->get('/')->assertSee($icon, false);                       // beranda publik
        $this->get('/halaman-tidak-ada')->assertNotFound()->assertSee($icon, false); // halaman galat
        auth()->logout();
        $this->get('/masuk')->assertSee($icon, false)->assertSee('<img src="'.$url.'"', false);
        $this->get('/favicon.ico')->assertRedirect($url);
    }

    public function test_sales_recap_pdf_embeds_logo(): void
    {
        $this->upload();
        $html = view('laporan.rekap-pdf', app(SalesExport::class)->data(now()->subDays(6)->startOfDay(), now()->endOfDay(), ['summary']))->render();
        $this->assertStringContainsString('src="data:image/png;base64,', $html);
    }
}
