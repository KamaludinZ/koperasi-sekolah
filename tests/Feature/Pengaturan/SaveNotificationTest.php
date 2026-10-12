<?php

namespace Tests\Feature\Pengaturan;

use App\Livewire\Pengaturan\Profile;
use App\Livewire\Pengaturan\Receipt;
use App\Livewire\Pengaturan\Tax;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SaveNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); // admin
        $this->seed(ProductSeeder::class);
    }

    public function test_success_toasts(): void
    {
        Livewire::test(Profile::class)->call('save')
            ->assertDispatched('toast', type: 'success', message: 'Profil koperasi disimpan. Struk dan beranda langsung memakai data baru.')
            ->assertDispatched('profile-saved');
        Livewire::test(Receipt::class)->call('save')->assertDispatched('toast', type: 'success');
        Livewire::test(Tax::class)->call('save')->assertDispatched('toast', type: 'success');
    }

    public function test_failure_toasts_count_fields_and_keep_field_errors(): void
    {
        Livewire::test(Profile::class)->set('name', '')->set('school', '')->call('save')
            ->assertHasErrors(['name', 'school'])
            ->assertSet('saved', false)
            ->assertDispatched('toast', type: 'error', message: 'Belum tersimpan: ada 2 isian yang perlu diperbaiki.')
            ->assertDispatched('focus-first-error')
            ->assertNotDispatched('profile-saved');

        Livewire::test(Tax::class)->set('enabled', true)->set('rate', '0')->call('save')
            ->assertDispatched('toast', type: 'error', message: 'Belum tersimpan: periksa isian yang ditandai merah.');
    }

    public function test_toast_container_is_on_every_app_page(): void
    {
        $this->get('/pengaturan/pajak')->assertSee('x-on:toast.window', false)->assertSee('aria-live="polite"', false);
        $this->get('/kasir')->assertSee('x-on:toast.window', false);
    }
}
