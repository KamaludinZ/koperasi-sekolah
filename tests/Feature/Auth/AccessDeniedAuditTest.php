<?php

namespace Tests\Feature\Auth;

use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessDeniedAuditTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $loginAs = 'kasir';

    public function test_denied_access_is_audited_once_per_window(): void
    {
        $this->get('/pengaturan')->assertForbidden()->assertSee('Akses ditolak');
        $this->get('/pengaturan')->assertForbidden();

        $log = ActivityLog::where('event', 'auth.access_denied')->sole();
        $this->assertSame(['auth', 'Bu Rina', '/pengaturan', 'pengaturan'], [$log->category, $log->actor_name, $log->properties['path'], $log->properties['route']]);
        $this->assertNotEmpty($log->reason);

        $this->get('/sistem')->assertForbidden();
        $this->assertSame(2, ActivityLog::where('event', 'auth.access_denied')->count());
    }

    public function test_gate_denials_are_audited_and_guests_are_not(): void
    {
        $this->getJson(route('transaksi.retur.queue'))->assertForbidden(); // can:setujui-retur
        $this->assertSame(1, ActivityLog::where('event', 'auth.access_denied')->count());

        auth()->logout();
        $this->get('/pengaturan')->assertRedirect();
        $this->assertSame(1, ActivityLog::where('event', 'auth.access_denied')->count());
    }
}
