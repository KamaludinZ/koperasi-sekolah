<?php

namespace Tests\Feature\Pengguna;

use App\Livewire\Pengguna\Index;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\UserService;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AutoRoleChangeLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->actingAs($this->admin);
    }

    public function test_saving_roles_from_page_logs_exactly_one_entry_with_reason(): void
    {
        $u = User::factory()->role('kasir')->create();
        Livewire::test(Index::class)->call('editRoles', $u->id)
            ->assertSeeHtml('data-role-reason')
            ->set('roleChoice', ['pengurus', 'kasir'])->set('roleReason', '  merangkap bendahara  ')
            ->call('saveRoles')->assertHasNoErrors();

        $logs = ActivityLog::whereIn('event', ['user.role_changed', 'user.role_added', 'user.primary_role_changed'])->get();
        $this->assertCount(1, $logs); // satu catatan ringkas, bukan per baris pivot / pergantian peran utama
        $this->assertSame(['user.role_changed', 'merangkap bendahara', 'pengurus'], [$logs[0]->event, $logs[0]->reason, $logs[0]->properties['primary_to']]);
    }

    public function test_reason_is_limited(): void
    {
        $u = User::factory()->role('kasir')->create();
        Livewire::test(Index::class)->call('editRoles', $u->id)->set('roleChoice', ['kasir', 'pengurus'])
            ->set('roleReason', str_repeat('x', 201))->call('saveRoles')->assertHasErrors('roleReason');
        $this->assertSame(['kasir'], $u->fresh()->roleNames());
    }

    public function test_primary_role_changed_directly_on_model_is_logged_automatically(): void
    {
        $u = User::factory()->role('kasir')->create(['name' => 'Pak Langsung']);
        $u->forceFill(['role_id' => Role::named('pengurus')->id])->save(); // mis. perintah/skrip lama

        $log = ActivityLog::where('event', 'user.primary_role_changed')->sole();
        $this->assertSame(['otomatis', 'kasir', 'pengurus', ['pengurus']], [$log->properties['source'], $log->properties['primary_from'], $log->properties['primary_to'], $log->properties['added']]);
        $this->assertSame(['from' => 'kasir', 'to' => 'pengurus'], $log->properties['changes']['primary_role']);
        $this->assertStringContainsString('Peran utama Pak Langsung diganti di luar halaman Pengguna', $log->description);
        $this->assertSame($this->admin->id, $log->actor_id);
    }

    public function test_quiet_maintenance_and_new_accounts_are_not_logged_as_changes(): void
    {
        User::factory()->role('kasir')->create(); // akun baru lewat factory
        $u = User::factory()->role('kasir')->create();
        AuditLog::quietly(fn () => $u->forceFill(['role_id' => Role::named('pengurus')->id])->save());
        $this->assertSame(0, ActivityLog::where('event', 'user.primary_role_changed')->count());
    }

    public function test_role_change_is_rolled_back_when_audit_cannot_be_written(): void
    {
        $u = User::factory()->role('kasir')->create();
        ActivityLog::creating(function (ActivityLog $l) {
            if ($l->event === 'user.role_changed') {
                throw new \RuntimeException('disk penuh');
            }
        });
        try {
            app(UserService::class)->updateRoles($u, ['kasir', 'pengurus'], $this->admin);
            $this->fail('harus gagal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('perubahan dibatalkan', $e->getMessage());
        }
        $this->assertSame(['kasir'], $u->fresh(['role', 'roles'])->roleNames()); // tidak ada perubahan tanpa jejak
    }
}
