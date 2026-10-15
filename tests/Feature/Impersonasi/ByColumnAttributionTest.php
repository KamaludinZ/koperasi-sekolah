<?php

namespace Tests\Feature\Impersonasi;

use App\Models\Member;
use App\Models\Notification;
use App\Models\User;
use App\Support\Actor;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ByColumnAttributionTest extends TestCase
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

    private function impersonateKasir(): void
    {
        $this->post(route('pengguna.impersonasi.petugas', $this->kasir), ['reason' => 'membantu kasir memeriksa struk', 'minutes' => 10]);
        $this->assertSame($this->kasir->id, auth()->id());
    }

    public function test_by_columns_record_the_admin_while_ownership_stays_with_target(): void
    {
        $this->impersonateKasir();
        $n = Notification::create(['type' => 'sistem', 'target' => 'semua', 'user_id' => $this->kasir->id, 'created_by' => auth()->id(),
            'title' => 'uji', 'message' => 'uji atribusi']);
        $this->assertSame($this->admin->id, $n->created_by);   // pelaku = admin
        $this->assertSame($this->kasir->id, $n->user_id);      // kepemilikan/penerima tetap kasir
    }

    public function test_soft_delete_records_admin_as_deleter(): void
    {
        $m = Member::factory()->create();
        $this->impersonateKasir();
        $m->trash('uji hapus saat impersonasi');
        $this->assertSame($this->admin->id, Member::withTrashed()->find($m->id)->deleted_by);
    }

    public function test_other_user_ids_are_untouched(): void
    {
        $other = User::factory()->role('kasir')->create();
        $this->impersonateKasir();
        $n = Notification::create(['type' => 'sistem', 'target' => 'semua', 'created_by' => $other->id, 'title' => 'x', 'message' => 'bukan target']);
        $this->assertSame($other->id, $n->created_by);
    }

    public function test_without_impersonation_values_are_kept(): void
    {
        $this->actingAs($this->kasir);
        $n = Notification::create(['type' => 'sistem', 'target' => 'semua', 'created_by' => $this->kasir->id, 'title' => 'x', 'message' => 'tanpa impersonasi']);
        $this->assertSame($this->kasir->id, $n->created_by);
        $m = Member::factory()->create();
        $m->trash('hapus biasa');
        $this->assertSame($this->kasir->id, Member::withTrashed()->find($m->id)->deleted_by);
    }

    public function test_member_impersonation_keeps_admin_on_web_guard(): void
    {
        $m = Member::factory()->create();
        $m->forceFill(['password' => 'rahasia-portal-1'])->save();
        $this->post(route('pengguna.impersonasi.anggota', $m), ['reason' => 'membantu anggota memakai portal', 'minutes' => 10]);
        $n = Notification::create(['type' => 'sistem', 'target' => 'semua', 'created_by' => auth('web')->id(), 'title' => 'x', 'message' => 'anggota']);
        $this->assertSame($this->admin->id, $n->created_by);
        $this->assertSame($this->admin->id, Actor::userId($this->admin->id));
    }
}
