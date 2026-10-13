<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Member;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Data master yang dihapus lunak (barang, kategori, anggota): daftar dengan penghapus, alasan,
 * dan jadwal pembersihan permanen sesuai masa retensi; pemeriksaan bentrok (kode/nama sudah
 * dipakai data aktif) dan pemulihan satu atau banyak data sekaligus. Pemulihan tercatat di
 * audit oleh MasterDataObserver (aksi "dipulihkan"). Kunci baris: "jenis:id", mis. "barang:15".
 */
class TrashService
{
    /** Jenis di layar → [model, kolom unik, sebutan kolom unik]. */
    public const ENTITIES = [
        'barang' => [Product::class, 'code', 'Kode'],
        'kategori' => [Category::class, 'name', 'Nama kategori'],
        'anggota' => [Member::class, 'member_code', 'Kode anggota'],
        'supplier' => [Supplier::class, 'name', 'Nama supplier'],
    ];

    public const LABELS = ['barang' => 'Barang', 'kategori' => 'Kategori', 'anggota' => 'Anggota', 'supplier' => 'Supplier'];

    /** @var array<int, ?string> nama penghapus yang sudah dibaca */
    private array $userNames = [];

    /**
     * @param  array{entity?: string, search?: string}  $filters
     * @return list<array<string, mixed>> terbaru dihapus dulu
     */
    public function list(array $filters = []): array
    {
        $term = mb_strtolower(trim($filters['search'] ?? ''));

        return collect(self::ENTITIES)
            ->filter(fn ($c, $entity) => ($filters['entity'] ?? '') === '' || $filters['entity'] === $entity)
            ->flatMap(fn ($c, $entity) => $this->trashed($entity)->map(fn (Model $m) => $this->toArray($entity, $m)))
            ->when($term !== '', fn (Collection $rows) => $rows->filter(fn ($r) => str_contains(
                mb_strtolower($r['label'].' '.($r['deleted_by'] ?? '').' '.($r['reason'] ?? '')), $term)))
            ->sortBy([['deleted_at', 'desc'], ['subject_id', 'desc']])->values()->all();
    }

    /** @return array{total: int, by_entity: array<string, int>, purge_soon: int, conflicts: int, keep_days: int} */
    public function summary(int $soonDays = 7): array
    {
        $rows = collect($this->list());

        return [
            'total' => $rows->count(),
            'by_entity' => collect(self::ENTITIES)->map(fn ($c, $e) => $rows->where('entity', $e)->count())->all(),
            'purge_soon' => $rows->filter(fn ($r) => $r['days_left'] <= $soonDays)->count(),
            'conflicts' => $rows->whereNotNull('conflict')->count(),
            'keep_days' => Product::retentionDays(),
        ];
    }

    public function find(string $key): ?Model
    {
        [$entity, $id] = array_pad(explode(':', $key, 2), 2, null);
        if (! isset(self::ENTITIES[$entity]) || ! ctype_digit((string) $id)) {
            return null;
        }

        return self::ENTITIES[$entity][0]::onlyTrashed()->find((int) $id);
    }

    /** Alasan data belum bisa dipulihkan, atau null bila aman. */
    public function conflict(string $entity, Model $model): ?string
    {
        [$class, $column, $label] = self::ENTITIES[$entity];
        $value = $model->getAttribute($column);
        $active = $class::query()->whereRaw("UPPER({$column}) = ?", [mb_strtoupper((string) $value)])->first();
        if ($active) {
            $owner = $entity === 'kategori' ? '' : ' "'.$active->getAttribute('name').'"';

            return "{$label} {$value} sudah dipakai ".mb_strtolower(self::LABELS[$entity])." aktif{$owner}. Ubah ".mb_strtolower($label).' itu dahulu.';
        }
        if ($entity === 'barang' && $model->category_id && Category::onlyTrashed()->whereKey($model->category_id)->exists()) {
            return 'Kategorinya juga terhapus. Pulihkan kategori '.Category::withTrashed()->find($model->category_id)?->name.' dahulu.';
        }

        return null;
    }

    /**
     * Pulihkan beberapa data. Yang tidak ditemukan dilewati; yang bentrok ditolak beserta alasannya.
     *
     * @param  list<string>  $keys
     * @return array{restored: list<array>, failed: list<array{key: string, label: string, reason: string}>}
     */
    public function restore(array $keys, ?string $reason = null): array
    {
        $restored = [];
        $failed = [];
        foreach (array_unique($keys) as $key) {
            $model = $this->find($key);
            if ($model === null) {
                continue;
            }
            $entity = explode(':', $key)[0];
            if ($conflict = $this->conflict($entity, $model)) {
                $failed[] = ['key' => $key, 'label' => $model->auditLabel(), 'reason' => $conflict];

                continue;
            }

            DB::transaction(fn () => AuditLog::withReason($reason, fn () => $model->restore()));
            $restored[] = ['key' => $key, 'entity' => $entity, 'id' => $model->getKey(), 'label' => $model->auditLabel()];
        }

        return ['restored' => $restored, 'failed' => $failed];
    }

    /** @return Collection<int, Model> */
    private function trashed(string $entity): Collection
    {
        $query = self::ENTITIES[$entity][0]::onlyTrashed();

        return match ($entity) {
            'barang' => $query->with('category')->get(),
            'kategori' => $query->withCount('products')->get(),
            default => $query->get(),
        };
    }

    /** @return array<string, mixed> */
    public function toArray(string $entity, Model $m): array
    {
        $purgeAt = $m->purgeAt();
        $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');

        return [
            'key' => "{$entity}:{$m->getKey()}",
            'entity' => $entity,
            'subject_id' => $m->getKey(),
            'label' => $m->auditLabel(),
            'detail' => match ($entity) {
                'barang' => ($m->category?->name ?? 'Tanpa kategori')." · {$rp($m->price)} · stok {$m->stock}",
                'kategori' => "{$m->products_count} barang",
                'supplier' => trim(($m->contact ? $m->contact.' · ' : '').$m->phone.' · '.$m->termsLabel()),
                default => ($m->type === 'guru' ? 'Guru' : 'Siswa '.$m->class)." · saldo tabungan {$rp($m->balance)}",
            },
            'deleted_at' => $m->deleted_at->toDateTimeString(),
            'deleted_by' => $m->deleted_by ? ($this->userNames[$m->deleted_by] ??= User::whereKey($m->deleted_by)->value('name')) : null,
            'reason' => $m->delete_reason,
            'purge_at' => $purgeAt->toDateTimeString(),
            'days_left' => max(0, (int) ceil(now()->diffInSeconds($purgeAt, false) / 86400)),
            'conflict' => $this->conflict($entity, $m),
        ];
    }
}
