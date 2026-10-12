<?php

namespace Tests\Feature\Auth;

use App\Livewire\Akun\Password;
use App\Support\MockData;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $mockRole = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProductSeeder::class);
    }

    public function test_requires_login(): void
    {
        $this->get('/akun/kata-sandi')->assertRedirect(route('login'));
    }

    public function test_change_password_for_any_role(): void
    {
        session(['mock.user' => MockData::findUser(3)]); // pengurus

        $this->get('/akun/kata-sandi')->assertOk()->assertSee('Ganti Kata Sandi')->assertSee('pengurus@koperasi.test');

        Livewire::test(Password::class)
            ->set('current_password', 'password')
            ->set('password', 'Amanah2026')
            ->set('password_confirmation', 'Amanah2026')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true)
            ->assertSet('password', '')
            ->assertSee('Kata sandi berhasil diganti');

        $this->assertNull(MockData::attempt('pengurus@koperasi.test', 'password'));
        $this->assertNotNull(MockData::attempt('pengurus@koperasi.test', 'Amanah2026'));
    }

    public function test_validation_rules(): void
    {
        session(['mock.user' => MockData::findUser(2)]);

        Livewire::test(Password::class)
            ->set('current_password', 'salah')->set('password', 'Amanah2026')->set('password_confirmation', 'Amanah2026')
            ->call('save')->assertHasErrors(['current_password']);

        Livewire::test(Password::class)
            ->set('current_password', 'password')->set('password', 'hanyahuruf')->set('password_confirmation', 'hanyahuruf')
            ->call('save')->assertHasErrors(['password' => 'regex']);

        Livewire::test(Password::class)
            ->set('current_password', 'password')->set('password', 'Abc12')->set('password_confirmation', 'Abc12')
            ->call('save')->assertHasErrors(['password' => 'min']);

        Livewire::test(Password::class)
            ->set('current_password', 'password')->set('password', 'Amanah2026')->set('password_confirmation', 'Amanah2027')
            ->call('save')->assertHasErrors(['password' => 'confirmed']);

        $this->assertNotNull(MockData::attempt('kasir@koperasi.test', 'password'));
    }

    public function test_sidebar_links_to_change_password(): void
    {
        session(['mock.user' => MockData::findUser(2)]);
        $this->get('/kasir')->assertSee(route('akun.password'), false);
    }
}
