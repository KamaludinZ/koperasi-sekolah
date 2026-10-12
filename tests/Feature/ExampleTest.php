<?php

namespace Tests\Feature;

use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $mockRole = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_root_shows_public_home(): void
    {
        $this->get('/')->assertOk()->assertSee('Masuk petugas');
    }

    public function test_cashier_screen_renders(): void
    {
        session(['mock.user' => \App\Support\MockData::findUser(2)]);

        $this->get('/kasir')
            ->assertOk()
            ->assertSee('Layar Kasir')
            ->assertSee('Keranjang masih kosong');
    }
}
