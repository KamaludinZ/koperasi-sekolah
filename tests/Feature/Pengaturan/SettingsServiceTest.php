<?php

namespace Tests\Feature\Pengaturan;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\MockData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SettingsService
    {
        return app(SettingsService::class);
    }

    public function test_defaults_are_used_when_nothing_is_saved(): void
    {
        $all = $this->service()->all();

        $this->assertSame(SettingsService::DEFAULTS['profile']['name'], $all['profile']['name']);
        $this->assertSame('58mm', $all['receipt']['paper']);
        $this->assertFalse($all['tax']['enabled']);
        $this->assertNull($all['updated_at']);
        $this->assertSame(0.0, $this->service()->taxRate());
        $this->assertSame(0, Setting::count());
    }

    public function test_save_persists_only_changed_known_keys_and_records_editor(): void
    {
        $all = $this->service()->save('tax', ['enabled' => true, 'rate' => 2.5, 'label' => 'Pajak', 'unknown' => 'x']);

        $this->assertTrue($all['tax']['enabled']);
        $this->assertSame(2.5, $all['tax']['rate']);
        $this->assertSame(2.5, $this->service()->taxRate());
        $this->assertSame(['tax.enabled', 'tax.rate'], Setting::orderBy('key')->pluck('key')->all());
        $this->assertSame('Admin Koperasi', $all['updated_by']);
        $this->assertNotNull($all['updated_at']);
    }

    public function test_saved_values_survive_a_new_request(): void
    {
        $this->service()->save('profile', ['name' => 'Koperasi Baru', 'schedule' => [1 => ['open' => '07:00', 'close' => '12:00'], 2 => null, 3 => null, 4 => null, 5 => null, 6 => null, 7 => null]]);
        $this->service()->save('receipt', ['footer' => 'Barakallah', 'show_phone' => true]);
        $this->app->forgetInstance(SettingsService::class);
        \Illuminate\Support\Facades\Cache::flush();

        $profile = MockData::profile();
        $this->assertSame('Koperasi Baru', $profile['name']);
        $this->assertSame(['open' => '07:00', 'close' => '12:00'], $profile['schedule'][1]);
        $this->assertNull($profile['schedule'][7]);
        $this->assertSame('Barakallah', $profile['receipt_footer']);
        $this->assertTrue(MockData::receipt()['show_phone']);
        $this->assertSame([['days' => 'Senin', 'time' => '07.00 – 12.00']], $profile['hours']);
    }

    public function test_changes_are_audited_without_logo_content(): void
    {
        $this->service()->save('profile', ['name' => 'Kopsis Baru', 'logo' => 'data:image/png;base64,AAAA']);
        $this->service()->save('profile', ['name' => 'Kopsis Baru']); // tidak berubah

        $logs = ActivityLog::where('event', 'setting.updated')->get();
        $this->assertCount(1, $logs);
        $this->assertSame('pengaturan', $logs[0]->category);
        $this->assertSame('Admin Koperasi', $logs[0]->actor_name);
        $this->assertSame('Kopsis Baru', $logs[0]->properties['changes']['name']['to']);
        $this->assertSame('(gambar baru)', $logs[0]->properties['changes']['logo']['to']);
        $this->assertStringNotContainsString('base64', json_encode($logs[0]->properties));
    }

    public function test_reset_returns_section_to_defaults(): void
    {
        $this->service()->save('receipt', ['paper' => '80mm']);
        $this->service()->save('tax', ['enabled' => true]);

        $all = $this->service()->reset('receipt');

        $this->assertSame('58mm', $all['receipt']['paper']);
        $this->assertTrue($all['tax']['enabled']);
        $this->assertSame(1, ActivityLog::where('event', 'setting.reset')->count());
    }

    public function test_unknown_section_is_rejected(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->service()->save('rahasia', ['x' => 1]);
    }
}
