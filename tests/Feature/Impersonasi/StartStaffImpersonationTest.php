<?php

namespace Tests\Feature\Impersonasi;

use App\Livewire\Pengguna\Impersonation as ImpersonationPage;
use App\Models\Impersonation;
use App\Models\Role;
use App\Models\User;
use App\Support\ImpersonationSession;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

class StartStaffImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->kasir = User::where('email', 'kasir@koperasi.test')->first();
        $this->actingAs($this->admin);
    }

    private function start(User $target, array $body = [])
    {
        return $this->post(route('pengguna.impersonasi.petugas', $target), ['reason' => 'membantu kasir memeriksa struk', 'minutes' => 30, ...$body]);
    }

    public function test_admin_becomes_target_with_recorded_session_and_lands_on_target_home(): void
    {
        $this->freezeTime();
        $lastLogin = $this->kasir->last_login_at;
        $this->get('/pengguna'); // sesi admin aktif
        $before = session()->getId();

        $this->start($this->kasir, ['minutes' => 15])->assertRedirect('/kasir');

        $this->assertSame($this->kasir->id, Auth::guard('web')->id());
        $this->assertNotSame($before, session()->getId()); // id sesi diganti
        $rec = Impersonation::sole();
        $this->assertSame([$this->admin->id, 'petugas', $this->kasir->id, 'Kasir', 'membantu kasir memeriksa struk'],
            [$rec->admin_id, $rec->target_type, $rec->target_user_id, $rec->target_label, $rec->reason]);
        $this->assertSame(now()->addMinutes(15)->timestamp, $rec->expires_at->timestamp);
        $this->assertNull($rec->ended_at);
        $this->assertSame($rec->id, ImpersonationSession::current()['id']);
        $this->assertSame($this->admin->id, ImpersonationSession::current()['admin_id']);
        $this->assertEquals($lastLogin, $this->kasir->fresh()->last_login_at); // bukan login sungguhan target

        // menjelajah sebagai target: menu target, spanduk tampil, halaman admin tertutup
        $this->get('/kasir')->assertOk()->assertSeeHtml('data-impersonation-banner');
        $this->get('/pengguna')->assertForbidden();
    }

    public function test_return_closes_the_recorded_session(): void
    {
        $this->start($this->kasir);
        $this->post('/impersonasi/selesai')->assertRedirect(route('pengguna.impersonasi'));
        $rec = Impersonation::sole();
        $this->assertNotNull($rec->ended_at);
        $this->assertSame('manual', $rec->end_reason);
        $this->assertSame($this->admin->id, Auth::guard('web')->id());
    }

    public function test_validation_and_eligibility_are_enforced_on_the_server(): void
    {
        $this->start($this->kasir, ['reason' => 'pendek'])->assertSessionHasErrors('reason');
        $this->start($this->kasir, ['minutes' => 45])->assertSessionHasErrors('minutes');
        $this->start($this->admin)->assertSessionHasErrors('target');                     // diri sendiri
        $this->start(User::factory()->admin()->create())->assertSessionHasErrors('target'); // admin lain
        $multi = User::factory()->role('kasir')->create();
        $multi->roles()->attach(Role::named('admin')->id);
        $this->start($multi->fresh())->assertSessionHasErrors('target');                  // memegang admin
        $this->start(User::where('email', 'nonaktif@koperasi.test')->first())->assertSessionHasErrors('target');
        $this->postJson(route('pengguna.impersonasi.petugas', $this->kasir), ['reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['reason', 'minutes']);
        $this->post('/pengguna/impersonasi/petugas/999999', ['reason' => 'membantu kasir memeriksa', 'minutes' => 10])->assertNotFound();

        $this->assertSame(0, Impersonation::count());
        $this->assertSame($this->admin->id, Auth::guard('web')->id());
    }

    public function test_only_admins_can_start(): void
    {
        $pengurus = User::where('email', 'pengurus@koperasi.test')->first();
        $this->actingAs($pengurus);
        $this->start($this->kasir)->assertForbidden();
        $this->assertSame(0, Impersonation::count());
        auth()->logout();
        $this->postJson(route('pengguna.impersonasi.petugas', $this->kasir), [])->assertUnauthorized();
    }

    public function test_impersonated_staff_cannot_chain_another_impersonation(): void
    {
        $this->start($this->kasir);
        // kini sebagai kasir: rute admin tertutup (bukan admin) — tak bisa memulai impersonasi lain
        $this->post(route('pengguna.impersonasi.petugas', User::where('email', 'pengurus@koperasi.test')->first()), ['reason' => 'membantu pengurus', 'minutes' => 10])->assertForbidden();
        $this->assertSame(1, Impersonation::count());
    }

    public function test_json_response_contract(): void
    {
        $res = $this->postJson(route('pengguna.impersonasi.petugas', $this->kasir), ['reason' => 'membantu kasir memeriksa struk', 'minutes' => 10])->assertCreated();
        $res->assertJsonPath('data.target', ['type' => 'petugas', 'id' => $this->kasir->id, 'name' => $this->kasir->name, 'label' => 'Kasir'])
            ->assertJsonPath('data.redirect', '/kasir')
            ->assertJsonPath('data.id', Impersonation::sole()->id);
    }

    public function test_stale_open_session_of_same_admin_is_closed_when_starting_again(): void
    {
        $old = Impersonation::create(['admin_id' => $this->admin->id, 'admin_name' => $this->admin->name, 'target_type' => 'petugas',
            'target_user_id' => $this->kasir->id, 'target_name' => 'x', 'reason' => 'sesi lama di perangkat lain',
            'started_at' => now()->subHour(), 'expires_at' => now()->subMinutes(30)]);
        $this->start($this->kasir)->assertRedirect();
        $this->assertSame('kedaluwarsa', $old->fresh()->end_reason);
        $this->assertSame(1, Impersonation::open()->count());
    }

    public function test_livewire_dialog_starts_and_redirects(): void
    {
        Livewire::test(ImpersonationPage::class)
            ->call('confirm', 'petugas', $this->kasir->id)
            ->set('reason', 'membantu kasir memeriksa struk')->set('minutes', '10')->set('acknowledged', true)
            ->call('start')->assertHasNoErrors()->assertRedirect('/kasir');
        $this->assertSame($this->kasir->id, Auth::guard('web')->id());
        $this->assertSame(10, (int) round(Impersonation::sole()->started_at->diffInMinutes(Impersonation::sole()->expires_at)));
    }
}
