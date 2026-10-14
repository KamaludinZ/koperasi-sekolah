<?php

namespace Tests\Feature\Pengaturan;

use App\Models\Member;
use App\Services\SettingsService;
use App\Support\PortalCart;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenStatusEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $loginAs = null;

    public function test_public_status_follows_schedule(): void
    {
        $this->travelTo('2026-10-14 09:00:00'); // Rabu
        $this->getJson('/status-koperasi')->assertOk()
            ->assertJsonPath('data.open', true)->assertJsonPath('data.detail', 'Tutup pukul 14.00')
            ->assertJsonPath('data.today', ['open' => '07:00', 'close' => '14:00'])
            ->assertJsonPath('data.closed_days', ['Minggu'])
            ->assertHeader('Cache-Control', 'max-age=60, public');

        $this->travelTo('2026-10-18 09:00:00'); // Minggu
        $this->getJson('/status-koperasi')->assertJsonPath('data.open', false)->assertJsonPath('data.today', null)
            ->assertJsonPath('data.detail', 'Buka besok pukul 07.00');
    }

    public function test_schedule_change_from_settings(): void
    {
        $this->travelTo('2026-10-14 15:00:00');
        $this->getJson('/status-koperasi')->assertJsonPath('data.open', false);

        $schedule = \App\Support\OpeningHours::DEFAULT;
        $schedule[3] = ['open' => '07:00', 'close' => '16:00'];
        app(SettingsService::class)->save('profile', ['schedule' => $schedule]);
        $this->getJson('/status-koperasi')->assertJsonPath('data.open', true);
    }

    public function test_portal_shows_status_and_closed_notice_on_checkout(): void
    {
        $this->seed(ProductSeeder::class);
        $this->travelTo('2026-10-18 09:00:00'); // Minggu, tutup
        $this->actingAsMember(Member::factory()->create());
        $this->get('/portal')->assertOk()->assertSee('data-open-status="tutup"', false);
        PortalCart::set($this->pid('ATK001'), 1);
        $this->get('/portal/checkout')->assertOk()->assertSee('data-closed-notice', false)->assertSee('diproses saat koperasi buka');
    }
}
