<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu panduan di Pusat Panduan (tabel guide_entries). Bentuk array-nya (toGuide) sama dengan entri
 * App\Support\GuideCatalog sehingga tampilan bisa memakai keduanya.
 */
class GuideEntry extends Model
{
    public const AUDIENCES = ['petugas' => 'Petugas', 'anggota' => 'Anggota portal'];

    public const SOURCES = ['auto' => 'Otomatis', 'manual' => 'Manual'];

    protected $fillable = [
        'key', 'menu_key', 'audience', 'title', 'summary', 'steps', 'tips', 'related', 'keywords',
        'required_permissions', 'source', 'is_stale', 'stale_reason', 'stale_at', 'generated_at', 'position',
    ];

    protected function casts(): array
    {
        return [
            'steps' => 'array', 'tips' => 'array', 'related' => 'array', 'required_permissions' => 'array',
            'is_stale' => 'boolean', 'stale_at' => 'datetime', 'generated_at' => 'datetime', 'position' => 'integer',
        ];
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(GuideOverride::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(GuideVersion::class)->orderByDesc('version');
    }

    public function scopeFor(Builder $q, string $audience): void
    {
        $q->where('audience', $audience)->orderBy('position')->orderBy('id');
    }

    /** @return array{menu: ?string, audience: string, title: string, summary: string, steps: list<array>, tips: list<string>, related: array<string, string>, keywords: ?string} */
    public function toGuide(): array
    {
        return [
            'menu' => $this->menu_key, 'audience' => $this->audience, 'title' => $this->title, 'summary' => $this->summary,
            'steps' => array_values($this->steps ?? []), 'tips' => array_values($this->tips ?? []), 'related' => $this->related ?? [],
            'keywords' => $this->keywords,
        ];
    }
}
