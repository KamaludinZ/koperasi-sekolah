<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pinjaman anggota (simpan pinjam): pengajuan → persetujuan → pencairan → angsuran → lunas. */
class Loan extends Model
{
    use HasFactory;

    public const STATUSES = [
        'diajukan' => 'Diajukan', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak', 'dibatalkan' => 'Dibatalkan',
        'berjalan' => 'Berjalan', 'menunggak' => 'Menunggak', 'lunas' => 'Lunas',
    ];

    public const METHODS = ['potong_gaji' => 'Potong gaji', 'transfer' => 'Transfer', 'tunai' => 'Tunai'];

    /** Status pinjaman yang sudah dicairkan & masih punya sisa. */
    public const ACTIVE = ['berjalan', 'menunggak'];

    protected $fillable = ['code', 'member_id', 'principal', 'interest', 'tenor_months', 'payment_method', 'status', 'purpose', 'decision_note',
        'outstanding', 'applied_at', 'decided_at', 'decided_by', 'disbursed_at', 'paid_off_at', 'created_by'];

    protected function casts(): array
    {
        return ['principal' => 'decimal:2', 'interest' => 'decimal:2', 'outstanding' => 'decimal:2', 'tenor_months' => 'integer',
            'applied_at' => 'date', 'decided_at' => 'datetime', 'disbursed_at' => 'date', 'paid_off_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('sequence');
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(LoanDisbursement::class)->orderBy('disbursed_on')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Sudah dicairkan & masih punya sisa (berjalan/menunggak). */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', self::ACTIVE);
    }

    /** Kode pinjaman berikutnya per tahun: PJM-2026-0001, PJM-2026-0002, … (panggil di dalam transaksi). */
    public static function nextCode(?\DateTimeInterface $at = null): string
    {
        $prefix = 'PJM-'.($at ?? now())->format('Y').'-';
        $last = static::query()->where('code', 'like', $prefix.'%')->lockForUpdate()->orderByDesc('code')->value('code');
        $n = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** Total yang harus dikembalikan (pokok + bunga). */
    public function totalDue(): int
    {
        return (int) round((float) $this->principal + (float) $this->interest);
    }
}
