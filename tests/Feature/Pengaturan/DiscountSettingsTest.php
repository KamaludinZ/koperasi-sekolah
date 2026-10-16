<?php

namespace Tests\Feature\Pengaturan;

use App\Livewire\Pengaturan\Discount;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ManualDiscountService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class DiscountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_in_settings_nav_and_saves(): void
    {
        $this->get('/pengaturan')->assertOk()->assertSee('Diskon manual')->assertSee(route('pengaturan.diskon'));
        $this->get('/pengaturan/diskon')->assertOk()->assertSee('Batas persen')->assertSee('Rp20.000');
        $this->get('/promo')->assertOk()->assertSee(route('pengaturan.diskon'));

        Livewire::test(Discount::class)->set('max_percent', 15)->set('max_amount', 50000)->set('reason_required', false)->set('enabled', false)
            ->call('save')->assertHasNoErrors()->assertDispatched('toast', type: 'success');
        $s = app(SettingsService::class)->discount();
        $this->assertSame([false, 15, 50000, false], [$s['enabled'], $s['max_percent'], $s['max_amount'], $s['reason_required']]);
        $this->assertTrue(ActivityLog::where('category', 'pengaturan')->where('description', 'like', '%Diskon manual kasir%')->exists());
    }

    public function test_validation_and_defaults(): void
    {
        Livewire::test(Discount::class)->set('max_percent', 0)->assertHasErrors(['max_percent' => 'min'])
            ->set('max_percent', 101)->assertHasErrors(['max_percent' => 'max'])
            ->set('max_percent', 20)->set('max_amount', -1)->call('save')->assertHasErrors(['max_amount' => 'min']);
        $this->assertSame(10, app(SettingsService::class)->discount()['max_percent']);

        app(SettingsService::class)->save('discount', ['max_percent' => 30]);
        Livewire::test(Discount::class)->assertSet('max_percent', 30)->call('restoreDefaults')->assertSet('max_percent', 10)->call('save');
        $this->assertSame(10, app(SettingsService::class)->discount()['max_percent']);
    }

    public function test_settings_drive_cashier_limits(): void
    {
        $kasir = User::where('email', 'kasir@koperasi.test')->first();
        $svc = app(ManualDiscountService::class);
        app(SettingsService::class)->save('discount', ['max_percent' => 20, 'max_amount' => 5000, 'reason_required' => false]);

        $this->assertSame(4000, $svc->resolve(['type' => 'persen', 'value' => 20], 20000, $kasir)['amount']);
        try {
            $svc->resolve(['type' => 'nominal', 'value' => 6000], 20000, $kasir);
            $this->fail('Seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Melebihi batas', $e->errors()['discount'][0]);
        }

        app(SettingsService::class)->save('discount', ['enabled' => false]);
        $this->expectException(ValidationException::class);
        $svc->resolve(['type' => 'nominal', 'value' => 1000], 20000, $kasir);
    }

    public function test_non_admin_read_only(): void
    {
        $this->loginAs('pengurus@koperasi.test');
        if (! auth()->user()->canOpen('pengaturan')) {
            $this->get('/pengaturan/diskon')->assertForbidden();

            return;
        }
        Livewire::test(Discount::class)->set('max_percent', 50)->call('save')->assertDispatched('toast', type: 'error');
        $this->assertSame(10, app(SettingsService::class)->discount()['max_percent']);
    }
}
