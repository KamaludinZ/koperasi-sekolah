<?php

namespace Tests\Feature\Kasir;

use App\Livewire\Kasir\Cart;
use App\Livewire\Pengaturan\Profile;
use App\Services\QrisImageService;
use App\Services\SettingsService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class QrisPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ProductSeeder::class);
    }

    private function upload(int $w = 300, int $h = 300)
    {
        return Livewire::test(Profile::class)->set('qrisUpload', UploadedFile::fake()->image('qris.png', $w, $h));
    }

    public function test_admin_uploads_qris_and_cashier_sees_it_in_payment(): void
    {
        $this->upload()->assertHasNoErrors()->call('save')->assertHasNoErrors();
        $name = app(SettingsService::class)->section('profile')['qris'];
        $this->assertMatchesRegularExpression('/^'.QrisImageService::FILE_PATTERN.'$/', $name);
        Storage::disk('local')->assertExists(QrisImageService::path($name));

        $c = Livewire::test(Cart::class)->call('add', $this->pid('ATK001'))->call('openPayment')->set('paymentMethod', 'qris');
        $c->assertSee(QrisImageService::url($name))->assertSee('Minta pembeli memindai QRIS');
        $this->get(QrisImageService::url($name))->assertOk();

        auth()->logout();
        $this->get(QrisImageService::url($name))->assertRedirect();
    }

    public function test_without_image_shows_hint_and_validation(): void
    {
        Livewire::test(Cart::class)->call('add', $this->pid('ATK001'))->call('openPayment')->assertSee('Gambar QRIS koperasi belum diunggah');
        $this->upload(120, 120)->assertHasErrors('qrisUpload');
        $this->upload(300, 600)->assertHasNoErrors(); // tidak harus persegi
    }

    public function test_remove_qris(): void
    {
        $this->upload()->call('save');
        $name = app(SettingsService::class)->section('profile')['qris'];
        Livewire::test(Profile::class)->call('removeQris')->call('save')->assertHasNoErrors();
        $this->assertNull(app(SettingsService::class)->section('profile')['qris']);
        Storage::disk('local')->assertMissing(QrisImageService::path($name));
    }
}
