<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Impersonation;
use App\Models\Role;
use App\Models\User;
use App\Support\Menu;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Jejak audit khusus peran & impersonasi (Audit → Peran & impersonasi):
 * - perubahan peran: peran akun ditambah/dicabut/diganti, hak menu peran diubah, perbaikan konsistensi;
 * - sesi impersonasi: siapa masuk sebagai siapa, alasan, mulai/berakhir/cara berakhir, durasi;
 * - aksi selama impersonasi: catatan activity_logs yang ditandai keterangan impersonasi (pelaku = admin).
 * Setiap catatan activity_logs ditandai sah/tidak menurut checksum HMAC-nya (AuditIntegrity).
 */
class RoleImpersonationTrail
{
    public const ROLE_EVENTS = [
        'user.created', 'user.role_changed', 'user.role_added', 'user.role_removed', 'role.permissions_updated', 'user.roles_repaired',
    ];

    public const IMPERSONATION_EVENTS = ['impersonation.started', 'impersonation.ended'];

    public const PERIODS = ['7' => '7 hari', '30' => '30 hari', '90' => '90 hari', 'semua' => 'Semua'];

    private function since(string $period): ?Carbon
    {
        return is_numeric($period) ? now()->subDays((int) $period)->startOfDay() : null;
    }

    /** @param  array{period?: string, search?: string, actor?: string}  $f */
    public function roleChanges(array $f, int $perPage = 15): LengthAwarePaginator
    {
        return $this->logs(ActivityLog::query()->whereIn('event', self::ROLE_EVENTS), $f)
            ->where(fn ($q) => $q->where('event', '!=', 'user.created')->orWhereNotNull('properties')) // akun baru: hanya bila mencatat perannya
            ->paginate($perPage, pageName: 'hal');
    }

    /** @param  array{period?: string, search?: string, actor?: string}  $f */
    public function actions(array $f, int $perPage = 15): LengthAwarePaginator
    {
        $q = ActivityLog::query()->whereNotIn('event', self::IMPERSONATION_EVENTS);
        DB::getDriverName() === 'pgsql'
            ? $q->whereRaw('properties::text LIKE ?', ['%"impersonation":{%'])
            : $q->where('properties', 'like', '%"impersonation":{%');

        return $this->logs($q, $f)->paginate($perPage, pageName: 'hal');
    }

    /** @param  array{period?: string, search?: string, actor?: string, status?: string}  $f */
    public function sessions(array $f, int $perPage = 15): LengthAwarePaginator
    {
        $since = $this->since($f['period'] ?? '30');
        $search = trim((string) ($f['search'] ?? ''));

        return Impersonation::query()
            ->when($since, fn ($q) => $q->where('started_at', '>=', $since))
            ->when(($f['actor'] ?? '') !== '', fn ($q) => $q->where('admin_name', $f['actor']))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('target_name', 'like', "%{$search}%")
                ->orWhere('admin_name', 'like', "%{$search}%")->orWhere('reason', 'like', "%{$search}%")))
            ->when(($f['status'] ?? '') === 'aktif', fn ($q) => $q->active())
            ->when(($f['status'] ?? '') === 'selesai', fn ($q) => $q->whereNotNull('ended_at'))
            ->orderByDesc('started_at')
            ->paginate($perPage, pageName: 'hal');
    }

    private function logs(Builder $q, array $f): Builder
    {
        $since = $this->since($f['period'] ?? '30');
        $search = trim((string) ($f['search'] ?? ''));

        return $q->when($since, fn ($w) => $w->where('created_at', '>=', $since))
            ->when(($f['actor'] ?? '') !== '', fn ($w) => $w->where('actor_name', $f['actor']))
            ->when($search !== '', fn ($w) => $w->where(fn ($s) => $s->where('description', 'like', "%{$search}%")
                ->orWhere('subject_label', 'like', "%{$search}%")->orWhere('reason', 'like', "%{$search}%")))
            ->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Status checksum HMAC per baris activity_logs (id → sah?). Dibaca mentah dari tabel agar sama persis dengan
     * pemeriksa terjadwal.
     *
     * @param  list<int>  $ids
     * @return array<int, bool>
     */
    public function integrity(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('activity_logs')->whereIn('id', $ids)->get()
            ->mapWithKeys(fn ($row) => [(int) $row->id => $row->checksum !== null && hash_equals($row->checksum, AuditIntegrity::checksum($row))])
            ->all();
    }

    /**
     * Catatan mulai & selesai tiap sesi impersonasi (id sesi → ['started' => log, 'ended' => log]).
     *
     * @param  list<string>  $sessionIds
     * @return array<string, array{started: ?ActivityLog, ended: ?ActivityLog}>
     */
    public function sessionLogs(array $sessionIds): array
    {
        $out = array_fill_keys($sessionIds, ['started' => null, 'ended' => null]);
        if ($sessionIds === []) {
            return $out;
        }
        ActivityLog::query()->whereIn('event', self::IMPERSONATION_EVENTS)->latest('id')->limit(2000)->get()
            ->each(function (ActivityLog $l) use (&$out) {
                $id = $l->properties['impersonation_id'] ?? null;
                if ($id !== null && isset($out[$id])) {
                    $out[$id][$l->event === 'impersonation.started' ? 'started' : 'ended'] ??= $l;
                }
            });

        return $out;
    }

    /**
     * Rincian satu catatan perubahan peran untuk panel detail: pelaku (+IP/perangkat), akun/peran yang diubah,
     * peran sebelum → sesudah, ditambah/dicabut, dampak pada menu gabungan (dihitung dengan hak menu peran SAAT INI),
     * konteks impersonasi, alasan, dan status checksum HMAC. Null bila bukan catatan perubahan peran.
     */
    public function roleChangeDetail(int $id): ?array
    {
        $log = ActivityLog::query()->whereKey($id)->whereIn('event', self::ROLE_EVENTS)->first();
        if (! $log) {
            return null;
        }
        $p = $log->properties ?? [];
        $names = fn ($v) => array_values(array_filter(array_map('trim', is_array($v) ? $v : explode(',', (string) $v))));
        $kind = match ($log->event) {
            'role.permissions_updated' => 'peran', 'user.roles_repaired' => 'perbaikan', default => 'akun'
        };

        $before = $after = [];
        if ($kind === 'akun') {
            [$before, $after] = match ($log->event) {
                'user.created' => [[], $names($p['roles'] ?? ($log->new_values['roles'] ?? []))],
                'user.role_changed' => [$names($p['changes']['roles']['from'] ?? ($log->old_values['roles'] ?? '')), $names($p['changes']['roles']['to'] ?? ($log->new_values['roles'] ?? ''))],
                default => [[], []],
            };
        }
        $added = array_values((array) ($p['added'] ?? array_diff($after, $before)));
        $removed = array_values((array) ($p['removed'] ?? array_diff($before, $after)));

        $menuImpact = null;
        if ($kind === 'akun' && ($before || $after)) {
            $merger = app(PermissionMerger::class);
            $mb = $merger->mergeNames($before)['permissions'];
            $ma = $merger->mergeNames($after)['permissions'];
            $label = fn (array $keys) => array_map(fn ($k) => Menu::ITEMS[$k]['label'] ?? $k, $keys);
            $menuImpact = ['gained' => $label(array_values(array_diff($ma, $mb))), 'lost' => $label(array_values(array_diff($mb, $ma))), 'total_after' => count($ma)];
        }

        return [
            'id' => $log->id, 'event' => $log->event, 'kind' => $kind, 'description' => $log->description,
            'at' => $log->created_at, 'reason' => $log->reason,
            'actor' => ['id' => $log->actor_id, 'name' => $log->actor_name ?? 'Sistem', 'role' => $log->actor_role, 'ip' => $log->ip_address, 'agent' => $log->user_agent],
            'subject' => ['type' => $log->subject_type, 'id' => $log->subject_id, 'label' => $log->subject_label],
            'before' => $before, 'after' => $after, 'added' => $added, 'removed' => $removed,
            'menu_impact' => $menuImpact,
            'menus_before' => $kind === 'peran' ? (string) ($log->old_values['menus'] ?? '') : null,
            'menus_after' => $kind === 'peran' ? (string) ($log->new_values['menus'] ?? '') : null,
            'holders' => $kind === 'peran' && $log->subject_id ? User::holdingRole((string) Role::whereKey($log->subject_id)->value('name'))->count() : null,
            'automatic' => (bool) ($p['otomatis'] ?? false),
            'impersonation' => $p['impersonation'] ?? null,
            'repair' => $kind === 'perbaikan' ? array_intersect_key($p, array_flip(['missing_pivot', 'wrong_primary', 'no_role', 'fixed'])) : null,
            'valid' => $this->integrity([$log->id])[$log->id] ?? false,
        ];
    }

    /** Jumlah aksi yang tercatat selama satu sesi impersonasi (di luar catatan mulai/selesainya). */
    public static function actionCount(string $impersonationId): int
    {
        if (! Str::isUuid($impersonationId)) {
            return 0;
        }
        $needle = '%"impersonation":{"id":"'.$impersonationId.'"%';
        $q = ActivityLog::query()->whereNotIn('event', self::IMPERSONATION_EVENTS);

        return (DB::getDriverName() === 'pgsql' ? $q->whereRaw('properties::text LIKE ?', [$needle]) : $q->where('properties', 'like', $needle))->count();
    }

    /** Ringkasan untuk kartu atas (periode terpilih). */
    public function stats(string $period): array
    {
        $since = $this->since($period);
        $roleLogs = ActivityLog::query()->whereIn('event', [...self::ROLE_EVENTS, ...self::IMPERSONATION_EVENTS])
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since));
        $ids = (clone $roleLogs)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $integrity = $this->integrity($ids);

        return [
            'role_changes' => (clone $roleLogs)->whereIn('event', self::ROLE_EVENTS)->count(),
            'sessions' => Impersonation::query()->when($since, fn ($q) => $q->where('started_at', '>=', $since))->count(),
            'active' => Impersonation::active()->count(),
            'checked' => count($integrity),
            'invalid' => count(array_filter($integrity, fn ($ok) => ! $ok)),
        ];
    }

    /** @return list<string> nama pelaku yang pernah muncul (untuk saringan) */
    public function actors(): array
    {
        return ActivityLog::query()->whereIn('event', [...self::ROLE_EVENTS, ...self::IMPERSONATION_EVENTS])
            ->whereNotNull('actor_name')->distinct()->orderBy('actor_name')->pluck('actor_name')->all();
    }
}
