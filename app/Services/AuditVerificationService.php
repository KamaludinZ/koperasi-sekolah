<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AuditIntegrityFailure;
use App\Models\Impersonation;
use App\Models\Notification;
use App\Support\AuditVerificationStatus;

/**
 * Verifikasi HMAC jejak audit + penandaan catatan gagal:
 * - penuh (jadwal harian): checksum SEMUA baris activity_logs + rantai mulai–selesai seluruh sesi impersonasi;
 * - cepat (jadwal tiap jam): hanya catatan peran & impersonasi + rantai sesi 7 hari terakhir.
 * Setiap kegagalan ditandai di audit_integrity_failures (sejak kapan, terakhir terlihat); yang tidak lagi gagal
 * pada pemeriksaan dengan cakupan sama ditandai pulih. Kegagalan BARU memicu notifikasi Admin & catatan
 * audit.integrity_failed. Hasil penuh disimpan sebagai "pemeriksaan terakhir" (AuditVerificationStatus).
 */
class AuditVerificationService
{
    public function __construct(private AuditIntegrity $integrity, private RoleImpersonationTrail $trail) {}

    /** @return array{checked: int, invalid: list<int>, unsigned: int, first_id: ?int, last_id: ?int, chains_checked: int, chain_failures: int, new_failures: int, open_failures: int, scope: string} */
    public function run(string $scope = 'penuh', string $source = 'jadwal', ?string $by = null): array
    {
        $now = now();
        if ($scope === 'cepat') {
            $events = [...RoleImpersonationTrail::ROLE_EVENTS, ...RoleImpersonationTrail::IMPERSONATION_EVENTS, 'user.primary_role_changed'];
            $ids = ActivityLog::query()->where(fn ($q) => $q->whereIn('event', $events)->orWhereNotNull('impersonation_id'))->pluck('id')->map(fn ($i) => (int) $i)->all();
            $seal = $this->trail->integrity($ids);
            $result = ['checked' => count($seal), 'invalid' => array_keys(array_filter($seal, fn ($ok) => ! $ok)), 'unsigned' => 0,
                'first_id' => $ids ? min($ids) : null, 'last_id' => $ids ? max($ids) : null];
            $sessions = Impersonation::query()->where('started_at', '>=', $now->copy()->subDays(7))->get();
        } else {
            $result = $this->integrity->verify();
            $sessions = Impersonation::query()->get();
        }

        $new = 0;
        $seenChecksum = [];
        $events = ActivityLog::query()->whereIn('id', $result['invalid'])->pluck('event', 'id');
        foreach ($result['invalid'] as $id) {
            $new += $this->mark('checksum', (int) $id, null, $events[$id] ?? null, 'Checksum HMAC tidak cocok dengan isi catatan', $now);
            $seenChecksum[] = (int) $id;
        }

        $chainFailures = 0;
        $seenChain = [];
        foreach ($sessions as $s) {
            $c = $this->trail->chain($s);
            if (! $c['ok']) {
                $chainFailures++;
                $logId = $c['problem'] === 'Catatan mulai tidak ditemukan' ? null : ($s->end_log_id && str_contains((string) $c['problem'], 'selesai') ? $s->end_log_id : $s->start_log_id);
                $new += $this->mark('rantai', $logId, $s->id, 'impersonation', mb_substr($c['problem'].' — '.$s->admin_name.' sebagai '.$s->target_name, 0, 200), $now);
                $seenChain[] = $s->id;
            }
        }

        // pulihkan penanda yang tidak lagi gagal (hanya dalam cakupan yang benar-benar diperiksa)
        AuditIntegrityFailure::open()->where('kind', 'checksum')
            ->when($scope === 'cepat', fn ($q) => $q->whereIn('activity_log_id', array_keys($seal ?? [])))
            ->whereNotIn('activity_log_id', $seenChecksum)->update(['cleared_at' => $now]); // daftar kosong: whereIn → tak ada, whereNotIn → semua
        AuditIntegrityFailure::open()->where('kind', 'rantai')->whereIn('impersonation_id', $sessions->pluck('id')->all())
            ->whereNotIn('impersonation_id', $seenChain)->update(['cleared_at' => $now]);

        $open = AuditIntegrityFailure::open()->count();
        $summary = $result + ['chains_checked' => $sessions->count(), 'chain_failures' => $chainFailures, 'new_failures' => $new, 'open_failures' => $open, 'scope' => $scope];
        if ($scope === 'penuh') {
            AuditVerificationStatus::store($result, $source, $by);
        }
        if ($new > 0) {
            $this->alert($summary);
        }

        return $summary;
    }

    /** Tandai (atau perbarui) satu kegagalan; true bila baru/kambuh. */
    private function mark(string $kind, ?int $logId, ?string $impersonationId, ?string $event, string $detail, $now): int
    {
        $row = AuditIntegrityFailure::query()->where('kind', $kind)
            ->where(fn ($q) => $logId === null ? $q->whereNull('activity_log_id') : $q->where('activity_log_id', $logId))
            ->where(fn ($q) => $impersonationId === null ? $q->whereNull('impersonation_id') : $q->where('impersonation_id', $impersonationId))
            ->first();
        if ($row && $row->cleared_at === null) {
            $row->update(['last_detected_at' => $now, 'detail' => $detail]);

            return 0;
        }
        if ($row) { // kambuh setelah pulih
            $row->update(['first_detected_at' => $now, 'last_detected_at' => $now, 'cleared_at' => null, 'detail' => $detail]);

            return 1;
        }
        AuditIntegrityFailure::create(['kind' => $kind, 'activity_log_id' => $logId, 'impersonation_id' => $impersonationId, 'event' => $event,
            'detail' => $detail, 'first_detected_at' => $now, 'last_detected_at' => $now]);

        return 1;
    }

    private function alert(array $s): void
    {
        $what = trim(($s['invalid'] ? count($s['invalid']).' catatan tidak cocok checksum' : '').($s['invalid'] && $s['chain_failures'] ? ' & ' : '')
            .($s['chain_failures'] ? $s['chain_failures'].' rantai impersonasi putus' : ''));
        Notification::firstOrCreate(['dedupe_key' => 'audit:integritas:'.now()->toDateString()], [
            'type' => 'sistem', 'target' => 'admin', 'title' => 'Jejak audit terindikasi diubah',
            'message' => "{$what}. Periksa Audit → Peran & impersonasi dan akses langsung ke database.",
            'url' => route('audit.peran', ['segel' => 'rusak'], false),
        ]);
        AuditLog::record('audit.integrity_failed', 'auth', "Verifikasi jejak audit menemukan {$s['new_failures']} kegagalan baru ({$what})", null,
            ['scope' => $s['scope'], 'invalid' => array_slice($s['invalid'], 0, 50), 'chain_failures' => $s['chain_failures'], 'new_failures' => $s['new_failures']]);
    }
}
