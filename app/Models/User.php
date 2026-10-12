<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\RoutesNotifications;

class User extends Authenticatable
{
    /**
     * RoutesNotifications (bukan Notifiable): notifikasi aplikasi memakai tabel
     * notifications milik koperasi (App\Models\Notification), bukan kanal database Laravel.
     *
     * @use HasFactory<UserFactory>
     */
    use HasFactory, RoutesNotifications;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    /** Peran petugas (admin, kasir, pengurus). */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** Notifikasi yang pernah dibaca/disembunyikan petugas ini (pivot notification_reads). */
    public function notificationReads(): BelongsToMany
    {
        return $this->belongsToMany(Notification::class, 'notification_reads')->withPivot(['read_at', 'dismissed_at']);
    }

    /** Pengumuman yang dibuat petugas ini. */
    public function authoredNotifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'created_by');
    }

    /** Kotak notifikasi petugas: sesuai peran, masih berlaku, belum disembunyikan, + status dibaca. */
    public function inbox(): Builder
    {
        return Notification::query()->inboxFor($this);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function roleName(): ?string
    {
        return $this->role?->name;
    }

    public function hasRole(string ...$names): bool
    {
        return in_array($this->roleName(), $names, true);
    }

    /** Boleh membuka menu tertentu (kunci App\Support\Menu::ITEMS). */
    public function canOpen(string $menu): bool
    {
        return (bool) $this->is_active && (bool) $this->role?->allows($menu);
    }
}
