<?php

namespace Tests\Feature\Audit;

use App\Livewire\Audit\Changes;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditChangeQuery;
use App\Services\SettingsService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsAndAccountHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_and_role_changes_have_old_new_values(): void
    {
        $svc = app(UserService::class);
        $user = $svc->create(['name' => 'Pak Budi', 'email' => 'budi@koperasi.test', 'role' => 'kasir', 'password' => 'RahasiaKuat123!', 'is_active' => true], auth()->user());
        $svc->update($user, ['name' => 'Pak Budi S.', 'email' => 'budi@koperasi.test', 'role' => 'pengurus', 'is_active' => true], auth()->user());
        $svc->updatePermissions(Role::named('kasir'), ['kasir', 'transaksi']);

        $created = ActivityLog::where('event', 'user.created')->sole();
        $this->assertSame(['dibuat', 'user', 'kasir'], [$created->action, $created->subject_type, $created->new_values['role']]);
        $updated = ActivityLog::where('event', 'user.role_changed')->sole();
        // multi-peran: perubahan peran dicatat sebagai daftar peran (urut prioritas) + rincian ditambah/dicabut
        $this->assertSame(['diubah', ['name' => 'Pak Budi', 'roles' => 'kasir'], ['name' => 'Pak Budi S.', 'roles' => 'pengurus']],
            [$updated->action, $updated->old_values, $updated->new_values]);
        $this->assertSame([['pengurus'], ['kasir']], [$updated->properties['added'], $updated->properties['removed']]);
        $perm = ActivityLog::where('event', 'role.permissions_updated')->sole();
        $this->assertSame(['role', 'Kasir, Transaksi'], [$perm->subject_type, $perm->new_values['menus']]);

        $this->assertSame(2, app(AuditChangeQuery::class)->query(['entity' => 'pengguna'])->count());
        $this->assertSame(1, app(AuditChangeQuery::class)->query(['entity' => 'peran'])->count());
        Livewire::test(Changes::class)->set('entity', 'pengguna')->assertSee('Pak Budi')->assertSee('Akun petugas');
    }

    public function test_history_links_from_settings_and_users(): void
    {
        app(SettingsService::class)->save('tax', ['label' => 'PPN'], auth()->user());
        $this->get('/pengaturan')->assertOk()->assertSee(route('audit.perubahan', ['jenis' => 'pengaturan', 'periode' => 'semua']));
        $this->get('/pengguna')->assertOk()->assertSee('Riwayat perubahan akun');
        $this->get(route('audit.perubahan', ['jenis' => 'pengaturan', 'periode' => 'semua']))->assertOk()->assertSee('Pajak');
    }
}
