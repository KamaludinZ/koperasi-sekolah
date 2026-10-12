<?php

namespace Tests\Feature\Auth;

use App\Livewire\Akun\Password;
use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $loginAs = null;

    private User $pengurus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ProductSeeder::class, UserSeeder::class]);
        $this->pengurus = User::firstWhere('email', 'pengurus@koperasi.test');
    }

    public function test_requires_login(): void
    {
        $this->get('/akun/kata-sandi')->assertRedirect(route('login'));
        $this->putJson('/akun/kata-sandi', [])->assertUnauthorized();
    }

    public function test_change_password_page_for_any_role(): void
    {
        $this->actingAs($this->pengurus);
        $this->get('/akun/kata-sandi')->assertOk()->assertSee('Ganti Kata Sandi')->assertSee('pengurus@koperasi.test')->assertDontSee('data tiruan');

        Livewire::test(Password::class)
            ->set('current_password', 'password')
            ->set('password', 'Amanah2026')
            ->set('password_confirmation', 'Amanah2026')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true)
            ->assertSet('password', '')
            ->assertSee('Kata sandi berhasil diganti');

        $fresh = $this->pengurus->fresh();
        $this->assertTrue(Hash::check('Amanah2026', $fresh->password));
        $this->assertNotNull($fresh->password_changed_at);
        $this->assertSame(1, ActivityLog::where('event', 'user.password_changed')->count());
    }

    public function test_json_endpoint_and_other_sessions_end(): void
    {
        DB::table('sessions')->insert(['id' => 'sesi-lain', 'user_id' => $this->pengurus->id, 'ip_address' => '10.0.0.9',
            'user_agent' => 'x', 'payload' => 'x', 'last_activity' => now()->timestamp]);
        $this->actingAs($this->pengurus);

        $this->putJson('/akun/kata-sandi', ['current_password' => 'password', 'password' => 'Amanah2026', 'password_confirmation' => 'Amanah2026'])
            ->assertOk()
            ->assertJsonPath('other_sessions_ended', 1);
        $this->assertDatabaseMissing('sessions', ['id' => 'sesi-lain']);

        // Masuk dengan sandi baru; sandi lama ditolak.
        $this->post('/keluar');
        $this->postJson('/masuk', ['email' => 'pengurus@koperasi.test', 'password' => 'password'])->assertUnprocessable();
        $this->postJson('/masuk', ['email' => 'pengurus@koperasi.test', 'password' => 'Amanah2026'])->assertOk();
    }

    public function test_validation_rules(): void
    {
        $this->actingAs($this->pengurus);
        $put = fn (array $body) => $this->putJson('/akun/kata-sandi', $body);

        $put(['current_password' => 'salah', 'password' => 'Amanah2026', 'password_confirmation' => 'Amanah2026'])
            ->assertJsonValidationErrors(['current_password' => 'Kata sandi yang sekarang salah.']);
        $put(['current_password' => 'password', 'password' => 'hanyahuruf', 'password_confirmation' => 'hanyahuruf'])
            ->assertJsonValidationErrors(['password' => 'Gunakan gabungan huruf dan angka.']);
        $put(['current_password' => 'password', 'password' => 'Abc12', 'password_confirmation' => 'Abc12'])
            ->assertJsonValidationErrors(['password' => 'Minimal 8 karakter.']);
        $put(['current_password' => 'password', 'password' => 'Amanah2026', 'password_confirmation' => 'Amanah2027'])
            ->assertJsonValidationErrors(['password' => 'Ulangan kata sandi baru tidak sama.']);

        Livewire::test(Password::class)->set('current_password', 'salah')->set('password', 'Amanah2026')->set('password_confirmation', 'Amanah2026')
            ->call('save')->assertHasErrors('current_password')->assertSet('saved', false);

        $this->assertTrue(Hash::check('password', $this->pengurus->fresh()->password));
        $this->assertSame(2, ActivityLog::where('event', 'user.password_change_failed')->count());
    }

    public function test_sidebar_links_to_change_password(): void
    {
        $this->actingAs(User::firstWhere('email', 'kasir@koperasi.test'))->get('/kasir')->assertSee(route('akun.password'), false);
    }
}
