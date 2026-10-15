<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Jejak audit khusus pinjaman (kategori `pinjaman` di activity_logs): label tiap peristiwa, kelompok (pengajuan,
 * pencairan, angsuran, tunggakan, ekspor), dan pencarian per kelompok/peristiwa, periode, petugas, pinjaman, kata kunci.
 */
class LoanAuditQuery
{
    public const EVENTS = [
        'pinjaman.diajukan' => 'Pengajuan dicatat', 'pinjaman.diubah' => 'Pengajuan diubah', 'pinjaman.disetujui' => 'Pengajuan disetujui',
        'pinjaman.ditolak' => 'Pengajuan ditolak', 'pinjaman.dibatalkan' => 'Pengajuan dibatalkan',
        'pinjaman.dicairkan' => 'Dana dicairkan', 'pinjaman.bukti' => 'Bukti pencairan diperbarui', 'pinjaman.bukti_dibuka' => 'Bukti pencairan dibuka',
        'pinjaman.bukti_tidak_cocok' => 'Bukti pencairan tidak cocok',
        'pinjaman.angsuran' => 'Angsuran dicatat', 'pinjaman.angsuran_koreksi' => 'Angsuran dikoreksi', 'pinjaman.jadwal_diubah' => 'Jadwal angsuran diubah',
        'pinjaman.hitung_ulang' => 'Sisa & status dihitung ulang', 'pinjaman.menunggak' => 'Pinjaman menunggak',
        'pinjaman.rekap_diunduh' => 'Rekap diunduh', 'pinjaman.audit_diekspor' => 'Jejak audit diekspor',
        'pinjaman.data_berubah' => 'Data pinjaman berubah (otomatis)',
        'pinjaman.audit_tidak_utuh' => 'Jejak audit tidak utuh',
        'pinjaman.pencairan_otomatis' => 'Pencairan berubah di luar alur (otomatis)', 'pinjaman.angsuran_otomatis' => 'Pembayaran berubah di luar alur (otomatis)',
    ];

    public const GROUPS = [
        'pengajuan' => ['label' => 'Pengajuan', 'events' => ['pinjaman.diajukan', 'pinjaman.diubah', 'pinjaman.disetujui', 'pinjaman.ditolak', 'pinjaman.dibatalkan']],
        'pencairan' => ['label' => 'Pencairan', 'events' => ['pinjaman.dicairkan', 'pinjaman.bukti', 'pinjaman.bukti_dibuka', 'pinjaman.bukti_tidak_cocok']],
        'angsuran' => ['label' => 'Angsuran & koreksi', 'events' => ['pinjaman.angsuran', 'pinjaman.angsuran_koreksi', 'pinjaman.jadwal_diubah', 'pinjaman.hitung_ulang']],
        'tunggakan' => ['label' => 'Tunggakan', 'events' => ['pinjaman.menunggak']],
        'ekspor' => ['label' => 'Rekap & ekspor', 'events' => ['pinjaman.rekap_diunduh', 'pinjaman.audit_diekspor']],
        'lainnya' => ['label' => 'Perubahan lain', 'events' => ['pinjaman.data_berubah', 'pinjaman.pencairan_otomatis', 'pinjaman.angsuran_otomatis', 'pinjaman.audit_tidak_utuh']],
    ];

    /** Peristiwa yang patut diperhatikan (ditandai di daftar). */
    public const NOTABLE = ['pinjaman.angsuran_koreksi', 'pinjaman.bukti_tidak_cocok', 'pinjaman.jadwal_diubah', 'pinjaman.menunggak', 'pinjaman.dibatalkan', 'pinjaman.data_berubah', 'pinjaman.pencairan_otomatis', 'pinjaman.angsuran_otomatis', 'pinjaman.audit_tidak_utuh'];

    public static function label(string $event): string
    {
        return self::EVENTS[$event] ?? $event;
    }

    public static function group(string $event): ?string
    {
        foreach (self::GROUPS as $key => $g) {
            if (in_array($event, $g['events'], true)) {
                return $key;
            }
        }

        return null;
    }

    /** @param  array{group?: string, event?: string, from?: ?Carbon, to?: ?Carbon, actor?: string, loan_id?: ?int, search?: string}  $f */
    public function query(array $f = []): Builder
    {
        $term = mb_strtolower(trim(mb_substr($f['search'] ?? '', 0, 100)));

        return ActivityLog::query()->where('category', 'pinjaman')
            ->betweenDates($f['from'] ?? null, $f['to'] ?? null)
            ->when(isset(self::GROUPS[$f['group'] ?? '']), fn ($q) => $q->whereIn('event', self::GROUPS[$f['group']]['events']))
            ->when(isset(self::EVENTS[$f['event'] ?? '']), fn ($q) => $q->where('event', $f['event']))
            ->when(($f['actor'] ?? '') !== '', fn ($q) => $q->where('actor_name', $f['actor']))
            ->when(! empty($f['loan_id']), fn ($q) => $q->where('subject_type', 'loan')->where('subject_id', (int) $f['loan_id']))
            ->when($term !== '', function (Builder $q) use ($term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $q->where(fn (Builder $w) => $w->whereRaw("LOWER(description) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(subject_label) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(actor_name) LIKE ? ESCAPE '\\'", [$like]));
            })
            ->latestFirst();
    }

    /** Jumlah per kelompok pada rentang (untuk kartu/tab). */
    public function counts(?Carbon $from, ?Carbon $to): array
    {
        $byEvent = ActivityLog::query()->where('category', 'pinjaman')->betweenDates($from, $to)->toBase()
            ->groupBy('event')->selectRaw('event, COUNT(*) AS n')->pluck('n', 'event');

        return collect(self::GROUPS)->map(fn ($g) => (int) collect($g['events'])->sum(fn ($e) => $byEvent[$e] ?? 0))->all()
            + ['semua' => (int) $byEvent->sum(), 'perhatian' => (int) collect(self::NOTABLE)->sum(fn ($e) => $byEvent[$e] ?? 0)];
    }

    /** Petugas yang pernah muncul di jejak pinjaman (untuk pilihan saring). */
    public function actors(): array
    {
        return ActivityLog::query()->where('category', 'pinjaman')->whereNotNull('actor_name')->distinct()->orderBy('actor_name')->pluck('actor_name')->all();
    }

    public static function toArray(ActivityLog $l): array
    {
        return [
            'id' => $l->id, 'event' => $l->event, 'label' => self::label($l->event), 'group' => self::group($l->event),
            'notable' => in_array($l->event, self::NOTABLE, true), 'description' => $l->description,
            'loan' => $l->subject_type === 'loan' ? ['id' => $l->subject_id, 'label' => $l->subject_label] : null,
            'actor' => $l->actor_name, 'actor_role' => $l->actor_role, 'at' => $l->created_at?->toDateTimeString(),
            'amount' => $l->amount, 'ip' => $l->ip_address, 'action' => $l->action,
            'old' => $l->old_values, 'new' => $l->new_values, 'reason' => $l->reason, 'properties' => $l->properties,
        ];
    }
}
