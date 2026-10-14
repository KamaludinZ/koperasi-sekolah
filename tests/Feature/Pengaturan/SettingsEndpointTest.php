<?php

namespace Tests\Feature\Pengaturan;

use App\Models\ActivityLog;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_returns_all_sections_with_defaults(): void
    {
        $this->getJson('/pengaturan/data')
            ->assertOk()
            ->assertJsonPath('data.profile.name', SettingsService::DEFAULTS['profile']['name'])
            ->assertJsonPath('data.receipt.paper', '58mm')
            ->assertJsonPath('data.tax.enabled', false)
            ->assertJsonPath('meta.updated_at', null)
            ->assertJsonStructure(['data' => ['profile', 'receipt', 'tax'], 'meta' => ['updated_at', 'updated_by', 'defaults']]);

        $this->getJson('/pengaturan/data/tax')->assertOk()->assertJsonPath('data.rounding', 'none');
        $this->getJson('/pengaturan/data/rahasia')->assertNotFound();
    }

    public function test_returns_and_credit_sections_round_trip(): void
    {
        $this->getJson('/pengaturan/data')->assertJsonStructure(['data' => ['credit', 'returns', 'security', 'discount']]);
        $this->getJson('/pengaturan/data/returns')->assertOk()->assertJsonPath('data.limit_days', 3);

        $this->putJson('/pengaturan/data/returns', ['limit_days' => 7, 'void_auto_max' => 0])->assertOk();
        $this->getJson('/pengaturan/data/returns')->assertJsonPath('data.limit_days', 7)->assertJsonPath('data.void_auto_max', 0)
            ->assertJsonPath('data.void_auto_minutes', 30);
        $this->assertSame(7, app(SettingsService::class)->section('returns')['limit_days']);

        $this->putJson('/pengaturan/data/returns', ['limit_days' => 0])->assertJsonValidationErrors('limit_days');
        $this->putJson('/pengaturan/data/returns', ['void_auto_minutes' => 999])->assertJsonValidationErrors('void_auto_minutes');

        $this->postJson('/pengaturan/data/returns/reset')->assertOk();
        $this->getJson('/pengaturan/data/returns')->assertJsonPath('data.limit_days', 3);
    }

    public function test_only_admin_can_access(): void
    {
        $this->loginAs('kasir');
        $this->getJson('/pengaturan/data')->assertForbidden();
        $this->putJson('/pengaturan/data/tax', ['enabled' => true, 'rate' => 11])->assertForbidden();

        $this->loginAs('pengurus');
        $this->putJson('/pengaturan/data/profile', ['name' => 'X'])->assertForbidden();

        auth()->logout();
        $this->getJson('/pengaturan/data')->assertUnauthorized();
    }

    public function test_partial_update_saves_and_reports_editor(): void
    {
        $this->putJson('/pengaturan/data/profile', ['name' => '  Kopsis Baru  ', 'motto' => '', 'unknown' => 'x'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Kopsis Baru')
            ->assertJsonPath('data.motto', null)
            ->assertJsonPath('data.school', SettingsService::DEFAULTS['profile']['school'])
            ->assertJsonPath('meta.updated_by', 'Admin Koperasi');

        $this->getJson('/pengaturan/data/profile')->assertJsonPath('data.name', 'Kopsis Baru');
        $this->assertSame(1, ActivityLog::where('event', 'setting.updated')->count());
    }

    public function test_schedule_is_validated_and_missing_days_are_closed(): void
    {
        $this->putJson('/pengaturan/data/profile', ['schedule' => [1 => ['open' => '07:00', 'close' => '06:00']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['schedule.1.close' => 'Jam tutup harus setelah jam buka.']);
        $this->putJson('/pengaturan/data/profile', ['schedule' => [8 => ['open' => '07:00', 'close' => '12:00']]])
            ->assertJsonValidationErrors('schedule');
        $this->putJson('/pengaturan/data/profile', ['schedule' => [1 => ['open' => '7 pagi', 'close' => '12:00']]])
            ->assertJsonValidationErrors('schedule.1.open');
        $this->putJson('/pengaturan/data/profile', ['schedule' => [1 => ['open' => '07:00']]])
            ->assertJsonValidationErrors('schedule.1');

        $res = $this->putJson('/pengaturan/data/profile', ['schedule' => [1 => ['open' => '07:00', 'close' => '12:00'], 6 => null]])->assertOk();
        $this->assertSame(['open' => '07:00', 'close' => '12:00'], $res->json('data.schedule.1'));
        $this->assertNull($res->json('data.schedule.2'));
        $this->assertNull($res->json('data.schedule.6'));
    }

    public function test_receipt_rules(): void
    {
        $this->putJson('/pengaturan/data/receipt', ['paper' => '100mm', 'footer' => '', 'header' => str_repeat('A', 41), 'note' => "1\n2\n3\n4"])
            ->assertUnprocessable()->assertJsonValidationErrors(['paper', 'footer', 'header', 'note']);

        $this->putJson('/pengaturan/data/receipt', ['paper' => '80mm', 'show_phone' => '1', 'header' => ''])
            ->assertOk()
            ->assertJsonPath('data.paper', '80mm')
            ->assertJsonPath('data.show_phone', true)
            ->assertJsonPath('data.header', null);
    }

    public function test_tax_rules_use_saved_values(): void
    {
        $this->putJson('/pengaturan/data/tax', ['enabled' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['rate' => 'Isi tarif pajak lebih dari 0, atau matikan pajak.']);
        $this->putJson('/pengaturan/data/tax', ['rate' => 101])->assertJsonValidationErrors('rate');
        $this->putJson('/pengaturan/data/tax', ['rate' => 2.555])->assertJsonValidationErrors('rate');
        $this->putJson('/pengaturan/data/tax', ['rounding' => 'acak', 'rounding_unit' => 250])->assertJsonValidationErrors(['rounding', 'rounding_unit']);

        $this->putJson('/pengaturan/data/tax', ['enabled' => true, 'rate' => '2.5', 'rounding' => 'up', 'rounding_unit' => '500'])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.rate', 2.5)
            ->assertJsonPath('data.rounding_unit', 500);

        // Pajak sudah aktif bertarif 2,5 → mengubah label saja tetap sah.
        $this->putJson('/pengaturan/data/tax', ['label' => 'PPN'])->assertOk();
        $this->putJson('/pengaturan/data/tax', ['rate' => 0])->assertJsonValidationErrors('rate');
        $this->assertSame(2.5, app(SettingsService::class)->taxRate());
    }

    public function test_reset_section(): void
    {
        $this->putJson('/pengaturan/data/receipt', ['paper' => '80mm'])->assertOk();

        $this->postJson('/pengaturan/data/receipt/reset')->assertOk()->assertJsonPath('data.paper', '58mm');
    }

    public function test_logo_cannot_be_set_through_update(): void
    {
        $this->putJson('/pengaturan/data/profile', ['logo' => 'javascript:alert(1)'])->assertOk()->assertJsonPath('data.logo', null);
    }
}
