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

    /** Menu yang wajib dimiliki admin agar tidak terkunci dari pengelolaan akun. */
    public const ADMIN_REQUIRED = ['pengguna'];

    protected $fillable = ['name', 'label', 'permissions'];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
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
