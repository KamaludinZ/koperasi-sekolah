<?php

namespace Tests\Feature\Impersonasi;

use App\Models\ActivityLog;
use App\Models\Impersonation;
use App\Models\User;
use App\Services\AuditIntegrity;
use App\Services\AuditLog;
use App\Support\Actor;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionAttributionTest extends TestCase
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

    private function impersonateKasir(): Impersonation
    {
        $this->post(route('pengguna.impersonasi.petugas', $this->kasir), ['reason' => 'membantu kasir memeriksa struk', 'minutes' => 10]);

        return Impersonation::sole();
    }

    public function test_audit_entries_during_impersonation_name_the_admin_not_the_target(): void
    {
        $rec = $this->impersonateKasir();
        // aksi nyata sebagai kasir: ganti kata sandi akun yang sedang dipakai → dicatat
        AuditLog::record('uji.aksi', 'kas', 'Aksi uji saat impersonasi', ['type' => 'user', 'id' => $this->kasir->id, 'label' => 'x']);
        $log = ActivityLog::where('event', 'uji.aksi')->sole();

        $this->assertSame([$this->admin->id, $this->admin->name, 'admin'], [$log->actor_id, $log->actor_name, $log->actor_role]);
        $this->assertSame(['id' => $rec->id, 'target_type' => 'petugas', 'target_id' => $this->kasir->id, 'target_name' => $this->kasir->name], $log->properties['impersonation']);
        $this->assertTrue(AuditIntegrity::isValid($log)); // keterangan impersonasi ikut ditandatangani
    }

    public function test_real_request_action_is_attributed_to_admin(): void
    {
        $this->impersonateKasir();
        $this->getJson('/pengguna/daftar')->assertForbidden(); // ditolak sebagai kasir → akses ditolak tercatat
        $denied = ActivityLog::where('category', 'auth')->where('event', 'like', '%denied%')->latest('id')->first();
        $this->assertNotNull($denied);
        $this->assertSame($this->admin->id, $denied->actor_id);
        $this->assertSame($this->kasir->name, $denied->properties['impersonation']['target_name']);
    }

    public function test_explicit_target_actor_is_overridden_during_impersonation(): void
    {
        $this->impersonateKasir();
        AuditLog::record('uji.eksplisit', 'anggota', 'x', null, [], actor: ['id' => $this->kasir->id, 'name' => $this->kasir->name, 'role' => 'kasir']);
        $this->assertSame($this->admin->id, ActivityLog::where('event', 'uji.eksplisit')->value('actor_id'));
    }

    public function test_after_returning_actions_are_normal_again(): void
    {
        $this->impersonateKasir();
        $this->post('/impersonasi/selesai');
        AuditLog::record('uji.normal', 'kas', 'Aksi biasa', null);
        $log = ActivityLog::where('event', 'uji.normal')->sole();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertArrayNotHasKey('impersonation', $log->properties ?? []);
        $this->assertNull(Actor::impersonation());
    }

    public function test_without_impersonation_target_acts_as_himself(): void
    {
        $this->actingAs($this->kasir);
        AuditLog::record('uji.kasir', 'kas', 'Aksi kasir', null);
        $this->assertSame($this->kasir->id, ActivityLog::where('event', 'uji.kasir')->value('actor_id'));
        $this->assertSame('Bu X', Actor::name('Bu X'));
        $this->assertSame(7, Actor::userId(7));
    }

    public function test_history_helpers_name_admin_as_target(): void
    {
        $this->impersonateKasir();
        $this->assertSame($this->admin->name.' (sebagai '.$this->kasir->name.')', Actor::name($this->kasir->name));
        $this->assertSame($this->admin->id, Actor::userId($this->kasir->id));
    }

    public function test_audit_page_marks_entries_made_while_impersonating(): void
    {
        $this->impersonateKasir();
        AuditLog::record('uji.tampil', 'kas', 'Aksi tampil', null);
        $this->post('/impersonasi/selesai');
        $this->get('/audit')->assertOk()->assertSeeHtml('data-impersonated-as')->assertSeeText('sebagai '.$this->kasir->name);
    }
}
