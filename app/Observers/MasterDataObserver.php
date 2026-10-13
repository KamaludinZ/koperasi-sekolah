<?php

namespace App\Observers;

use App\Services\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Pencatat audit untuk data master ber-trait Auditable (barang, kategori, anggota):
 * - created → `{type}.created`, aksi "dibuat", nilai sesudah = kolom yang terisi;
 * - updated → `{type}.updated` (atau `.activated`/`.deactivated` bila hanya status aktif),
 *   aksi "diubah", hanya kolom dipantau yang benar-benar berubah;
 * - deleted → `{type}.deleted` aksi "dihapus" (hapus lunak) atau `{type}.purged` (permanen),
 *   nilai sebelum disimpan agar data tetap bisa ditelusuri;
 * - restored → `{type}.restored`, aksi "dipulihkan".
 * Alasan diambil dari AuditLog::withReason(); pencatatan dilewati di dalam AuditLog::quietly().
 */
class MasterDataObserver
{
    public function created(Model $model): void
    {
        $values = array_filter($this->values($model), fn ($v) => $v !== null && $v !== '');
        $this->record($model, 'created', 'dibuat', null, $values, ucfirst($this->noun($model))." baru {$model->auditTitle()}");
    }

    public function updated(Model $model): void
    {
        $fields = array_values(array_intersect($model::auditConfig()['fields'], array_keys($model->getChanges())));
        if ($fields === []) {
            return;
        }

        $old = $model->auditValues(collect($fields)->mapWithKeys(fn ($f) => [$f => $model->getOriginal($f)])->all());
        $new = array_intersect_key($this->values($model), array_flip($fields));
        $event = $fields === ['is_active'] ? ($model->getAttribute('is_active') ? 'activated' : 'deactivated') : 'updated';
        $description = match ($event) {
            'activated' => ucfirst($this->noun($model))." {$model->auditTitle()} diaktifkan",
            'deactivated' => ucfirst($this->noun($model))." {$model->auditTitle()} dinonaktifkan",
            default => "Data {$this->noun($model)} {$model->auditTitle()} diubah: ".implode(', ', $fields),
        };

        $this->record($model, $event, 'diubah', $old, $new, $description);
    }

    public function deleted(Model $model): void
    {
        $permanent = ! in_array(SoftDeletes::class, class_uses_recursive($model), true) || $model->isForceDeleting();
        $this->record($model, $permanent ? 'purged' : 'deleted', 'dihapus', $this->values($model), null,
            ucfirst($this->noun($model))." {$model->auditTitle()} dihapus".($permanent ? ' permanen' : ''), ['permanent' => $permanent]);
    }

    public function restored(Model $model): void
    {
        $this->record($model, 'restored', 'dipulihkan', null, $this->values($model),
            ucfirst($this->noun($model))." {$model->auditTitle()} dipulihkan");
    }

    /** @return array<string, mixed> kolom dipantau dalam bentuk terbaca */
    private function values(Model $model): array
    {
        return $model->auditValues($model->only($model::auditConfig()['fields']));
    }

    private function noun(Model $model): string
    {
        return $model::auditConfig()['noun'];
    }

    private function record(Model $model, string $event, string $action, ?array $old, ?array $new, string $description, array $extra = []): void
    {
        if (AuditLog::isQuiet()) {
            return;
        }

        $config = $model::auditConfig();
        $changes = collect(array_keys(($old ?? []) + ($new ?? [])))
            ->mapWithKeys(fn ($k) => [$k => ['from' => $old[$k] ?? null, 'to' => $new[$k] ?? null]])->all();

        AuditLog::record("{$config['type']}.{$event}", $config['category'], Str::limit($description, 250),
            ['type' => $config['type'], 'id' => $model->getKey(), 'label' => $model->auditLabel()],
            array_filter(['changes' => $action === 'diubah' ? $changes : null] + $extra, fn ($v) => $v !== null),
            action: $action, old: $old ?: null, new: $new ?: null, reason: AuditLog::reason());
    }
}
