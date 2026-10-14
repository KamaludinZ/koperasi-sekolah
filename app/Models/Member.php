<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\SoftDeletesWithReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Anggota koperasi (siswa/guru). `balance` = saldo tabungan saat ini;
 * hanya boleh diubah lewat layanan tabungan agar selalu cocok dengan mutasinya.
 */
class Member extends Model implements AuthenticatableContract
{
    use Authenticatable; // guard `member` (portal anggota)
    use Auditable;
    use HasFactory;
    use SoftDeletesWithReason;

    /** Audit data master; saldo dicatat lewat mutasi tabungan. */
    public const AUDIT = [
        'type' => 'member', 'category' => 'anggota', 'noun' => 'anggota',
        'fields' => ['member_code', 'name', 'type', 'class', 'phone', 'is_active'],
    ];

    public const TYPES = ['siswa' => 'Siswa', 'guru' => 'Guru'];

    protected $fillable = [
        'member_code', 'name', 'type', 'class', 'phone', 'is_active', 'joined_at', 'credit_limit',
    ];

    protected $attributes = [
        'type' => 'siswa',
        'balance' => 0,
        'is_active' => true,
    ];

    /** Kata sandi portal tidak pernah ikut terserialisasi (JSON/array). */
    protected $hidden = ['password', 'pin', 'remember_token'];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'credit_limit' => 'integer',
            'is_active' => 'boolean',
            'joined_at' => 'date',
            'password' => 'hashed',
            'pin' => 'hashed',
            'portal_last_login_at' => 'datetime',
        ];
    }

    /** Anggota tidak memakai "ingat saya" (tidak ada kolom remember_token). */
    public function getRememberTokenName()
    {
        return '';
    }

    public function hasPin(): bool
    {
        return filled($this->getAttributes()['pin'] ?? null);
    }

    public function hasPortalAccess(): bool
    {
        return $this->is_active && ! $this->trashed() && filled($this->getAttributes()['password'] ?? null);
    }

    public function credits(): HasMany
    {
        return $this->hasMany(MemberCredit::class);
    }

    public function creditPayments(): HasMany
    {
        return $this->hasMany(MemberCreditPayment::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Mutasi tabungan (setor/tarik). */
    /** Saldo simpanan per jenis (pokok/wajib/sukarela). */
    public function savingAccounts(): HasMany
    {
        return $this->hasMany(MemberSavingAccount::class);
    }

    public function savingEntries(): HasMany
    {
        return $this->hasMany(MemberSavingEntry::class);
    }

    public function savings(): HasMany
    {
        return $this->hasMany(SavingsTransaction::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, ?string $type): void
    {
        $query->when($type, fn ($q) => $q->where('type', $type));
    }

    /** Cari nama, kode anggota, atau kelas (tanpa membedakan huruf besar/kecil). */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = mb_strtolower(trim((string) $term));
        if ($term === '') {
            return;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $query->where(fn (Builder $w) => $w
            ->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])
            ->orWhereRaw("LOWER(member_code) LIKE ? ESCAPE '\\'", [$like])
            ->orWhereRaw("LOWER(class) LIKE ? ESCAPE '\\'", [$like]));
    }

    /** Label tampilan, mis. "Andi Pratama (7A)" atau "Pak Budi (Guru)". */
    public function auditLabel(): string
    {
        return "{$this->member_code} · {$this->name}";
    }

    public function auditTitle(): string
    {
        return "{$this->display_name} ({$this->member_code})";
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->name.' ('.($this->type === 'guru' ? 'Guru' : $this->class).')';
    }

    /** Kode harus unik tanpa membedakan huruf: selalu simpan huruf besar. */
    protected function setMemberCodeAttribute(string $value): void
    {
        $this->attributes['member_code'] = mb_strtoupper(trim($value));
    }
}
