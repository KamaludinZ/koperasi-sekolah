<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * Notifikasi & pengumuman koperasi (tabel notifications). Status dibaca per petugas
 * ada di tabel notification_reads (relasi readers).
 */
class Notification extends Model
{
    use HasFactory;

    public const TYPES = ['stok' => 'Stok', 'pengumuman' => 'Pengumuman', 'sistem' => 'Sistem'];

    public const TARGETS = ['semua' => 'Semua petugas', 'admin' => 'Admin', 'kasir' => 'Kasir', 'pengurus' => 'Pengurus', 'anggota' => 'Anggota (disampaikan petugas)'];

    protected $fillable = ['type', 'title', 'message', 'target', 'url', 'product_id', 'dedupe_key', 'show_public', 'expires_at', 'created_by'];

    protected function casts(): array
    {
        return [
            'show_public' => 'boolean',
            'expires_at' => 'date',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'notification_reads')->withPivot(['read_at', 'dismissed_at']);
    }

    /** Masih berlaku (tanpa tanggal berakhir, atau belum lewat). */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn ($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', now()->toDateString()));
    }

    /** Untuk petugas berperan $role: Admin melihat semua; lainnya target semua, anggota, atau perannya. */
    public function scopeForRole(Builder $query, ?string $role): void
    {
        if ($role !== 'admin') {
            $query->whereIn('target', array_filter(['semua', 'anggota', $role]));
        }
    }

    /**
     * Kotak notifikasi petugas: sesuai peran, masih berlaku, tidak disembunyikan, terbaru dulu,
     * dengan kolom tambahan is_read (bool) & read_at milik petugas itu.
     */
    public function scopeInboxFor(Builder $query, User $user): void
    {
        $reads = fn ($column) => DB::table('notification_reads')->select($column)
            ->whereColumn('notification_reads.notification_id', 'notifications.id')
            ->where('notification_reads.user_id', $user->id)->limit(1);

        $query->select('notifications.*')
            ->selectSub($reads('read_at'), 'read_at')
            ->forRole($user->roleName())
            ->active()
            ->whereNotExists(fn ($q) => $q->from('notification_reads')
                ->whereColumn('notification_reads.notification_id', 'notifications.id')
                ->where('notification_reads.user_id', $user->id)
                ->whereNotNull('dismissed_at'))
            ->orderByDesc('notifications.created_at')->orderByDesc('notifications.id');
    }

    /** Hanya yang belum dibaca petugas (dipakai bersama inboxFor). */
    public function scopeUnread(Builder $query, User $user): void
    {
        $query->whereNotExists(fn ($q) => $q->from('notification_reads')
            ->whereColumn('notification_reads.notification_id', 'notifications.id')
            ->where('notification_reads.user_id', $user->id)
            ->whereNotNull('read_at'));
    }

    /** Status dibaca untuk baris hasil inboxFor (read_at dari subquery). */
    public function getIsReadAttribute(): bool
    {
        return isset($this->attributes['read_at']) && $this->attributes['read_at'] !== null;
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function getTargetLabelAttribute(): string
    {
        return self::TARGETS[$this->target] ?? $this->target;
    }

    /** Tandai dibaca / belum dibaca untuk satu petugas (upsert pivot). */
    public static function markFor(User $user, array $ids, bool $read = true): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }
        $now = now();
        $existing = DB::table('notification_reads')->where('user_id', $user->id)->whereIn('notification_id', $ids)->pluck('notification_id')->all();

        DB::table('notification_reads')->where('user_id', $user->id)->whereIn('notification_id', $existing)
            ->update(['read_at' => $read ? $now : null]);
        if ($read) {
            $new = static::whereKey(array_diff($ids, $existing))->pluck('id');
            DB::table('notification_reads')->insert($new->map(fn ($id) => ['notification_id' => $id, 'user_id' => $user->id, 'read_at' => $now])->all());
        }

        return count($ids);
    }

    /** Sembunyikan dari kotak notifikasi petugas (tetap tersimpan untuk petugas lain). */
    public static function dismissFor(User $user, array $ids): void
    {
        $now = now();
        foreach (static::whereKey($ids)->pluck('id') as $id) {
            DB::table('notification_reads')->updateOrInsert(
                ['notification_id' => $id, 'user_id' => $user->id],
                ['dismissed_at' => $now],
            );
        }
    }

    public function scopePublic(Builder $query): void
    {
        $query->where('type', 'pengumuman')->where('show_public', true)->active();
    }
}
