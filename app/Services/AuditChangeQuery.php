<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Riwayat perubahan data master (baris activity_logs yang punya `action`) untuk halaman
 * Audit → Perubahan data & endpoint GET /audit/perubahan/data: filter jenis data, aksi,
 * petugas, periode, kata kunci, satu objek ("jenis:id"), dan perubahan sensitif;
 * ringkasan per periode; bentuk baris {id, at, entity, action, subject_id, subject_label,
 * actor_name, actor_role, changes, reason, ip_address}.
 */
class AuditChangeQuery
{
    /** Jenis data di layar → subject_type di activity_logs. */
    public const ENTITIES = [
        'barang' => 'product',
        'kategori' => 'category',
        'anggota' => 'member',
        'pengaturan' => 'setting',
        'supplier' => 'supplier',
        'promo' => 'promo',
        'kupon' => 'coupon',
        'retur' => 'transaction_return',
        'pengguna' => 'user',
        'peran' => 'role',
    ];

    /** Kolom sensitif: perubahannya (bukan pengisian awal) perlu ditinjau, begitu pula penghapusan. */
    public const SENSITIVE = ['price', 'cost_price', 'is_active', 'discount.max_percent', 'tax.rate'];

    /**
     * @param  array{entity?: string, action?: string, actor?: string, search?: string, subject?: string, sensitive?: bool, from?: ?Carbon, to?: ?Carbon}  $f
     */
    public function query(array $f = []): Builder
    {
        $term = mb_strtolower(trim($f['search'] ?? ''));
        [$subjectEntity, $subjectId] = array_pad(explode(':', $f['subject'] ?? '', 2), 2, null);

        return ActivityLog::query()->masterChanges()
            ->whereIn('subject_type', self::ENTITIES)
            ->betweenDates($f['from'] ?? null, $f['to'] ?? null)
            ->when(isset(self::ENTITIES[$f['entity'] ?? '']), fn ($q) => $q->where('subject_type', self::ENTITIES[$f['entity']]))
            ->when(($f['action'] ?? '') !== '', fn ($q) => $q->where('action', $f['action']))
            ->when(($f['actor'] ?? '') !== '', fn ($q) => $q->where('actor_name', $f['actor']))
            ->when(isset(self::ENTITIES[$subjectEntity ?? '']) && ctype_digit((string) $subjectId),
                fn ($q) => $q->where('subject_type', self::ENTITIES[$subjectEntity])->where('subject_id', (int) $subjectId))
            ->when($f['sensitive'] ?? false, fn ($q) => $this->sensitive($q))
            ->when($term !== '', function (Builder $q) use ($term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw("LOWER(subject_label) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(actor_name) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(COALESCE(reason, '')) LIKE ? ESCAPE '\\'", [$like]));
            })
            ->latestFirst();
    }

    /** Penghapusan, atau perubahan (bukan pembuatan) yang menyentuh kolom sensitif. */
    private function sensitive(Builder $q): void
    {
        $q->where(fn (Builder $w) => $w->where('action', 'dihapus')->orWhere(function (Builder $c) {
            $c->where('action', '!=', 'dibuat')->where(function (Builder $k) {
                foreach (self::SENSITIVE as $field) {
                    $k->orWhereRaw('CAST(new_values AS TEXT) LIKE ?', ['%"'.$field.'"%']);
                }
            });
        }));
    }

    public static function isSensitive(array $row): bool
    {
        return $row['action'] === 'dihapus'
            || ($row['action'] !== 'dibuat' && array_intersect(array_keys($row['changes']), self::SENSITIVE) !== []);
    }

    /** @return array{total: int, by_entity: array<string, int>, price_changes: int, deleted: int, restored: int, sensitive: int, actors: array<string, int>} */
    public function summary(?Carbon $from, ?Carbon $to): array
    {
        $base = fn () => $this->query(['from' => $from, 'to' => $to])->reorder();
        $byType = $base()->toBase()->groupBy('subject_type')->selectRaw('subject_type, COUNT(*) AS n')->pluck('n', 'subject_type');
        $byAction = $base()->toBase()->groupBy('action')->selectRaw('action, COUNT(*) AS n')->pluck('n', 'action');

        return [
            'total' => (int) $byType->sum(),
            'by_entity' => collect(self::ENTITIES)->map(fn ($type) => (int) ($byType[$type] ?? 0))->all(),
            'price_changes' => $base()->where('subject_type', 'product')->where('action', 'diubah')
                ->whereRaw('CAST(new_values AS TEXT) LIKE ?', ['%"price"%'])->count(),
            'deleted' => (int) ($byAction['dihapus'] ?? 0),
            'restored' => (int) ($byAction['dipulihkan'] ?? 0),
            'sensitive' => $this->query(['from' => $from, 'to' => $to, 'sensitive' => true])->reorder()->count(),
            'actors' => $base()->toBase()->whereNotNull('actor_name')->groupBy('actor_name')
                ->selectRaw('actor_name, COUNT(*) AS n')->orderByDesc('n')->orderBy('actor_name')->pluck('n', 'actor_name')
                ->map(fn ($n) => (int) $n)->all(),
        ];
    }

    /** Label terbaru satu objek ("jenis:id"), mis. untuk judul riwayat objek. */
    public function subjectLabel(string $subject): ?string
    {
        return $this->query(['subject' => $subject])->value('subject_label');
    }

    /** @return array<string, mixed> */
    public static function toArray(ActivityLog $log, bool $withIp = true): array
    {
        return [
            'id' => $log->id,
            'at' => $log->created_at->toDateTimeString(),
            'entity' => array_search($log->subject_type, self::ENTITIES, true) ?: $log->subject_type,
            'action' => $log->action,
            'event' => $log->event,
            'description' => $log->description,
            'subject_id' => $log->subject_id,
            'subject_label' => $log->subject_label,
            'actor_name' => $log->actor_name ?? 'Sistem',
            'actor_role' => $log->actor_role,
            'changes' => $log->field_changes,
            'reason' => $log->reason,
            'ip_address' => $withIp ? $log->ip_address : null,
        ];
    }

    /** Nama petugas yang pernah mengubah data master (untuk saringan). */
    public function actors(): array
    {
        return ActivityLog::query()->masterChanges()->whereNotNull('actor_name')
            ->distinct()->orderBy('actor_name')->pluck('actor_name')->all();
    }
}
