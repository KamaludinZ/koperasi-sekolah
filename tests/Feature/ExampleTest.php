<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_redirects_to_cashier_screen(): void
    {
        $this->get('/')->assertRedirect('/kasir');
    }

    public function test_cashier_screen_renders(): void
    {
        $this->get('/kasir')
            ->assertOk()
            ->assertSee('Layar Kasir')
            ->assertSee('Keranjang masih kosong');
    }
}
