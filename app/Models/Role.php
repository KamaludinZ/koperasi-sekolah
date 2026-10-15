<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Peran petugas. `permissions` = daftar kunci menu (lihat App\Support\Menu::ITEMS)
 * yang boleh dibuka peran ini.
 */
class Role extends Model
{
    public const ADMIN = 'admin';

    public const KASIR = 'kasir';

    public const PENGURUS = 'pengurus';

    public const LABELS = [self::ADMIN => 'Admin', self::KASIR => 'Kasir', self::PENGURUS => 'Pengurus'];

    /** Urutan prioritas peran utama (users.role_id) bila akun memegang beberapa peran. */
    public const PRIORITY = [self::ADMIN, self::PENGURUS, self::KASIR];

    /**
     * Urutkan nama peran menurut prioritas (tanpa duplikat; nama tak dikenal di belakang).
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function sortNames(array $names): array
    {
        $names = array_values(array_unique($names));
        $rank = fn (string $n) => ($i = array_search($n, self::PRIORITY, true)) === false ? 99 : $i;
        usort($names, fn ($a, $b) => $rank($a) <=> $rank($b));

        return $names;
    }

    /** Menu yang wajib dimiliki admin agar tidak terkunci dari pengelolaan akun. */
    public const ADMIN_REQUIRED = ['pengguna', 'pengaturan'];

    /** Menu yang tidak boleh diberikan ke peran tertentu (simpanan hanya dikelola pengurus/admin). */
    public const FORBIDDEN = ['kasir' => ['simpanan', 'pinjaman']];

    protected $fillable = ['name', 'label', 'permissions'];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    /** Akun yang peran UTAMA-nya peran ini (users.role_id). */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Semua akun yang memegang peran ini (pivot user_roles). */
    public function members(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles');
    }

    public function allows(string $menu): bool
    {
        return in_array($menu, $this->permissions ?? [], true);
    }

    public static function named(string $name): self
    {
        return static::where('name', $name)->firstOrFail();
    }
}
