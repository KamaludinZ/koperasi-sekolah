<?php

namespace Tests\Feature\Pengaturan;

use App\Livewire\Pengaturan\Security;
use App\Models\ActivityLog;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SecuritySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_with_defaults(): void
    {
        $this->get('/pengaturan/keamanan')->assertOk()->assertDontSee('Data contoh')
            ->assertSee('Keamanan &amp; retensi', false)->assertSee('Data terhapus (barang, anggota, kategori)')->assertSee('Kesiapan server');
        $this->get('/pengaturan')->assertSee(route('pengaturan.keamanan'));

        Livewire::test(Security::class)
            ->assertSet('trash_days', 30)->assertSet('idle_minutes', 60)->assertSet('logo_max_kb', 512)
            ->assertSet('logo_types', ['png', 'jpg', 'webp'])->assertSee('5× gagal → kunci 1 menit', false);
    }

    public function test_admin_saves_and_values_persist(): void
    {
        Livewire::test(Security::class)
            ->set('trash_days', '60')->set('idle_minutes', 30)->set('import_types', ['csv'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success');

        $s = app(SettingsService::class)->security();
        $this->assertSame(60, $s['trash_days']);
        $this->assertSame(30, $s['idle_minutes']);
        $this->assertSame(['csv'], $s['import_types']);
        $log = \App\Models\ActivityLog::where('event', 'setting.updated')->latest('id')->first();
        $this->assertSame(['security.trash_days' => 30, 'security.idle_minutes' => 60, 'security.import_types' => ['csv', 'xlsx']], $log->old_values);
        Livewire::test(Security::class)->assertSet('trash_days', 60);
    }

    public function test_validation_limits_and_types(): void
    {
        Livewire::test(Security::class)
            ->set('trash_days', 3)->assertHasErrors(['trash_days' => 'min'])
            ->set('max_attempts', 50)->assertHasErrors(['max_attempts' => 'max'])
            ->set('idle_minutes', '')->assertHasErrors(['idle_minutes' => 'required'])
            ->set('logo_types', [])->assertHasErrors(['logo_types'])
            ->set('import_types', ['exe'])->assertHasErrors(['import_types.0'])->assertSee('Jenis berkas tidak didukung.')
            ->call('save')->assertDispatched('toast', type: 'error');

        $this->assertSame(SettingsService::DEFAULTS['security'], app(SettingsService::class)->security());
    }

    public function test_restore_defaults_and_size_format(): void
    {
        Livewire::test(Security::class)->set('trash_days', 90)->call('restoreDefaults')->assertSet('trash_days', 30);
        $this->assertSame('512 KB', Security::size(512));
        $this->assertSame('5 MB', Security::size(5120));
        $this->assertSame('1,5 MB', Security::size(1536));
    }

    public function test_non_admin_is_read_only(): void
    {
        $role = \App\Models\Role::named('pengurus');
        $role->update(['permissions' => [...$role->permissions, 'pengaturan']]);
        $this->loginAs('pengurus');
        $this->get('/pengaturan/keamanan')->assertOk()->assertSee('Mode baca saja');
        Livewire::test(Security::class)->set('trash_days', 60)->call('save')->assertDispatched('toast', type: 'error');
        $this->assertSame(30, app(SettingsService::class)->security()['trash_days']);
        $this->assertTrue(ActivityLog::where('event', 'setting.denied')->exists());
    }

    public function test_production_checks_follow_config(): void
    {
        config(['app.url' => 'https://koperasi.example', 'session.secure' => true, 'app.debug' => false, 'session.same_site' => 'lax']);
        $checks = Livewire::test(Security::class)->instance()->checks;
        $this->assertTrue(collect($checks)->every('ok'));

        config(['app.url' => 'http://localhost', 'session.secure' => false]);
        $checks = Livewire::test(Security::class)->instance()->checks;
        $this->assertSame(2, collect($checks)->where('ok', false)->count());
    }
}
