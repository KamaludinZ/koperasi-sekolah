<?php

namespace Tests\Feature\Auth;

use App\Livewire\Pengguna\ActiveSessions;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\SessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ActiveSessionsAdminTest extends TestCase
{
    use RefreshDatabase;

    private function addSession(string $id, User $user, int $minutesAgo, string $agent = 'Mozilla/5.0 (Linux; Android 13) Chrome/120'): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '10.0.0.5', 'user_agent' => $agent, 'payload' => '', 'last_activity' => now()->subMinutes($minutesAgo)->timestamp]);
    }

    public function test_lists_active_sessions_and_forces_logout(): void
    {
        $kasir = User::where('email', 'kasir@koperasi.test')->first();
        $kasir->forceFill(['remember_token' => 'abc'])->save();
        $this->addSession('s-kasir', $kasir, 5);
        $this->addSession('s-lama', $kasir, 600); // di luar batas menganggur

        $rows = app(SessionService::class)->allActive();
        $this->assertSame(['s-kasir'], array_column($rows, 'id'));
        $this->assertSame(['Bu Rina', 'kasir', 'Android', 'Chrome'], [$rows[0]['name'], $rows[0]['role'], $rows[0]['device'], $rows[0]['browser']]);

        $this->get('/pengguna')->assertSee(route('pengguna.sesi'));
        $this->get('/pengguna/sesi-aktif')->assertOk()->assertSee('Bu Rina')->assertSee('Android · Chrome');

        $c = Livewire::test(ActiveSessions::class)->call('confirmEnd', 's-kasir')->assertSee('Keluarkan sesi Bu Rina?')
            ->set('reason', 'hp')->call('end')->assertHasErrors('reason');
        $c->set('reason', 'HP kasir hilang')->call('end')->assertHasNoErrors()->assertDispatched('toast')->assertSet('ending', null);
        $this->assertFalse(DB::table('sessions')->where('id', 's-kasir')->exists());
        $this->assertNull($kasir->fresh()->remember_token);
        $log = ActivityLog::where('event', 'auth.session_ended')->sole();
        $this->assertSame(['HP kasir hilang', $kasir->id], [$log->reason, $log->subject_id]);
    }

    public function test_cannot_end_own_current_session_and_admin_only(): void
    {
        $this->assertSame(false, app(SessionService::class)->forceEnd('tidak-ada', auth()->user(), 'alasan uji'));
        try {
            app(SessionService::class)->forceEnd(session()->getId(), auth()->user(), 'alasan uji');
            $this->fail('Seharusnya ditolak.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('sesi Anda sendiri', $e->errors()['reason'][0]);
        }
        $this->loginAs('pengurus@koperasi.test');
        $this->get('/pengguna/sesi-aktif')->assertForbidden();
    }
}
