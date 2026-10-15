<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\AuditLog;
use App\Services\PermissionMerger;
use App\Services\RoleAuditService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\RoutesNotifications;
use Illuminate\Support\Facades\DB;

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
    protected static function booted(): void
    {
        // peran utama selalu tercatat juga di pivot (akun dibuat lewat seeder/factory/perintah lama tetap konsisten)
        static::saved(function (User $user) {
            if ($user->role_id && $user->wasChanged('role_id') || ($user->wasRecentlyCreated && $user->role_id)) {
                $changed = $user->wasChanged('role_id'); // diganti (bukan baru dibuat; wasRecentlyCreated tetap true pada objek yang sama)
                $before = $changed ? $user->roles()->pluck('roles.name')->all() : [];
                $inserted = DB::table('user_roles')->insertOrIgnore(['user_id' => $user->id, 'role_id' => $user->role_id, 'created_at' => now(), 'granted_by' => UserRole::currentGranter()]);
                $user->unsetRelation('roles');
                // peran utama diganti langsung lewat model di LUAR alur resmi (UserService) → tetap tercatat otomatis
                if ($changed && ! UserRole::inOfficialFlow() && ! AuditLog::isQuiet()) {
                    $after = $user->roles()->pluck('roles.name')->all();
                    $oldPrimary = Role::whereKey($user->getOriginal('role_id'))->value('name');
                    app(RoleAuditService::class)->recordUserRoles($user, $before, $after, [
                        'source' => 'otomatis', 'event' => 'user.primary_role_changed', 'primary_from' => $oldPrimary, 'primary_to' => $user->role()->value('name'),
                        'other_changes' => ['primary_role' => ['from' => $oldPrimary, 'to' => $user->role()->value('name')]],
                        'description' => "Peran utama {$user->name} diganti di luar halaman Pengguna".($inserted ? ' (peran baru ikut dipasang)' : ''),
                    ]);
                }
            }
        });
    }

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

    /** Akun yang memegang salah satu peran ini di pivot user_roles (mis. User::holdingRole('admin')->active()). */
    public function scopeHoldingRole(Builder $query, string ...$names): void
    {
        $query->whereHas('roles', fn ($r) => $r->whereIn('roles.name', $names));
    }

    /** Peran petugas (admin, kasir, pengurus). */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** Semua peran yang dipegang akun (pivot user_roles); role() = peran utama. */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->using(UserRole::class)->withPivot('created_at', 'granted_by')->orderBy('roles.id');
    }

    /**
     * Nama seluruh peran akun, urut prioritas (admin, pengurus, kasir). Peran utama selalu ikut.
     *
     * @return list<string>
     */
    public function roleNames(): array
    {
        return Role::sortNames(array_filter([...$this->roles->pluck('name')->all(), $this->role?->name]));
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

    /** Memegang salah satu peran yang disebut (dari SELURUH peran akun, bukan hanya peran utama). */
    public function hasRole(string ...$names): bool
    {
        return array_intersect($this->roleNames(), $names) !== [];
    }

    /**
     * Gabungan izin menu dari semua peran yang dipegang (tidak saling menimpa, tanpa duplikat). Urutan: izin peran
     * utama lebih dulu (urutannya dipakai menentukan halaman awal setelah masuk), lalu tambahan dari peran lain
     * menurut prioritas peran.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return app(PermissionMerger::class)->forUser($this)['permissions'];
    }

    /** Boleh membuka menu tertentu (kunci App\Support\Menu::ITEMS) lewat salah satu perannya. */
    public function canOpen(string $menu): bool
    {
        return (bool) $this->is_active && in_array($menu, $this->permissions(), true);
    }
}
