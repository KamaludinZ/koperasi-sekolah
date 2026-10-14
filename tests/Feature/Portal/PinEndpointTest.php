<?php

namespace Tests\Feature\Portal;

use App\Models\Member;
use App\Services\PortalPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PinEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Member $andi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->andi = Member::factory()->create(['password' => 'rahasia123', 'pin' => '246810']);
        $this->actingAsMember($this->andi);
    }

    public function test_status_and_verify_with_one_time_token(): void
    {
        $this->getJson('/portal/pin/status')->assertOk()->assertJsonPath('data', ['has_pin' => true, 'left' => 3, 'locked' => false, 'minutes' => 0, 'seconds' => 0]);

        $this->postJson('/portal/pin/verifikasi', ['pin' => '111111'])->assertStatus(422)->assertJsonPath('message', 'PIN salah. Sisa 2 percobaan.')->assertJsonPath('meta.left', 2);
        $token = $this->postJson('/portal/pin/verifikasi', ['pin' => '246810'])->assertOk()->assertJsonPath('data.verified', true)->json('data.token');
        $this->assertSame(40, strlen($token));

        $pins = app(PortalPinService::class);
        $this->assertTrue($pins->consumeToken($this->andi, $token));
        $this->assertFalse($pins->consumeToken($this->andi, $token)); // sekali pakai
    }

    public function test_token_expires_and_belongs_to_member(): void
    {
        $pins = app(PortalPinService::class);
        $t = $pins->issueToken($this->andi)['token'];
        $this->travel(6)->minutes();
        $this->assertFalse($pins->consumeToken($this->andi, $t));
        $t = $pins->issueToken($this->andi)['token'];
        $this->assertFalse($pins->consumeToken(Member::factory()->create(), $t));
    }

    public function test_locked_after_three_and_requires_login(): void
    {
        foreach (range(1, 3) as $i) {
            $this->postJson('/portal/pin/verifikasi', ['pin' => '000000']);
        }
        $this->postJson('/portal/pin/verifikasi', ['pin' => '246810'])->assertStatus(422)->assertJsonPath('meta.locked', true);
        $this->getJson('/portal/pin/status')->assertJsonPath('data.locked', true);

        auth('member')->logout();
        $this->getJson('/portal/pin/status')->assertUnauthorized();
    }

    public function test_every_attempt_is_audited(): void
    {
        $this->postJson('/portal/pin/verifikasi', ['pin' => '246810']);
        foreach (range(1, 3) as $i) {
            $this->postJson('/portal/pin/verifikasi', ['pin' => '000000']);
        }
        $events = \App\Models\ActivityLog::where('subject_type', 'member')->where('subject_id', $this->andi->id)->where('event', 'like', 'portal.pin_%')->orderBy('id')->pluck('event')->all();
        $this->assertSame(['portal.pin_verified', 'portal.pin_failed', 'portal.pin_failed', 'portal.pin_failed', 'portal.pin_locked'], $events);

        $last = \App\Models\ActivityLog::where('event', 'portal.pin_failed')->latest('id')->first();
        $this->assertSame(['attempt' => 3, 'left' => 0], $last->properties);
        $this->assertSame('anggota', $last->actor_role);
        $this->assertStringContainsString('dikunci 15 menit', \App\Models\ActivityLog::where('event', 'portal.pin_locked')->value('description'));
    }
}
