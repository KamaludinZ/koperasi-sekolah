<?php

namespace Tests\Feature\Auth;

use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForceHttpsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
        config(['koperasi.force_https' => true]);
    }

    public function test_http_get_redirects_permanently_to_https(): void
    {
        $this->get('http://localhost/kasir?tab=bayar')
            ->assertStatus(301)->assertRedirect('https://localhost/kasir?tab=bayar');
        $this->get('http://localhost/')->assertStatus(301);
    }

    public function test_http_writes_are_refused_and_health_check_is_exempt(): void
    {
        $this->postJson('http://localhost/masuk', ['email' => 'kasir@koperasi.test', 'password' => 'password'])->assertStatus(426);
        $this->get('http://localhost/up')->assertOk();
    }

    public function test_https_responses_carry_hsts_and_security_headers(): void
    {
        $res = $this->get('https://localhost/kasir')->assertOk();
        $res->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        config(['koperasi.hsts_max_age' => 0]);
        $this->get('https://localhost/kasir')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_forwarded_proto_from_trusted_proxy_counts_as_secure(): void
    {
        \Illuminate\Http\Middleware\TrustProxies::at('*');
        try {
            $this->get('http://localhost/kasir', ['X-Forwarded-Proto' => 'https'])->assertOk();
        } finally {
            \Illuminate\Http\Middleware\TrustProxies::flushState();
        }
    }

    public function test_disabled_force_https_keeps_http_but_adds_basic_headers(): void
    {
        config(['koperasi.force_https' => false]);
        $this->get('http://localhost/kasir')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('Strict-Transport-Security');
    }
}
