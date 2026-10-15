<?php

namespace Tests\Concerns;

use App\Models\Impersonation;
use App\Support\ImpersonationSession;
use Illuminate\Support\Carbon;

/**
 * Pembantu tes: keadaan impersonasi di sesi + baris impersonation_sessions yang cocok (middleware TrackImpersonation
 * menolak keadaan sesi yang tidak punya baris terbuka di basis data).
 */
trait FakesImpersonation
{
    /** @param  array<string, mixed>  $state  isi session `impersonation` (id = uuid) */
    protected function impersonationRecord(array $state): array
    {
        Impersonation::query()->whereKey($state['id'])->exists() || Impersonation::query()->forceCreate([
            'id' => $state['id'], 'admin_id' => $state['admin_id'], 'admin_name' => $state['admin_name'], 'target_type' => $state['target_type'],
            'target_user_id' => $state['target_type'] === 'petugas' ? $state['target_id'] : null,
            'target_member_id' => $state['target_type'] === 'anggota' ? $state['target_id'] : null,
            'target_name' => $state['target_name'], 'target_label' => $state['target_label'] ?? null, 'reason' => $state['reason'],
            'started_at' => Carbon::createFromTimestamp($state['started_at'], config('app.timezone')), 'expires_at' => Carbon::createFromTimestamp($state['expires_at'], config('app.timezone')),
        ]);

        return [ImpersonationSession::KEY => $state];
    }
}
