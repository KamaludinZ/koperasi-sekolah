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

    /**
     * Perpindahan status yang sah. Pengajuan: diajukan → disetujui/ditolak/dibatalkan; disetujui → diajukan (syarat diubah),
     * dibatalkan, atau berjalan (dicairkan). Setelah cair: berjalan ⇄ menunggak → lunas; lunas bisa kembali berjalan/menunggak
     * hanya lewat koreksi angsuran. Ditolak & dibatalkan bersifat final.
     */
    public const TRANSITIONS = [
        'diajukan' => ['disetujui', 'ditolak', 'dibatalkan'],
        'disetujui' => ['diajukan', 'dibatalkan', 'berjalan'],
        'berjalan' => ['menunggak', 'lunas'],
        'menunggak' => ['berjalan', 'lunas'],
        'lunas' => ['berjalan', 'menunggak'],
        'ditolak' => [],
        'dibatalkan' => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return $from === $to || in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** Setiap perubahan status lewat model diperiksa; lompatan tidak sah (mis. diajukan → berjalan) ditolak. */
    protected static function booted(): void
    {
        static::updating(function (Loan $loan) {
            if ($loan->isDirty('status') && ! self::canTransition((string) $loan->getOriginal('status'), (string) $loan->status)) {
                throw new \DomainException("Status pinjaman {$loan->code} tidak bisa berubah dari \"{$loan->getOriginal('status')}\" menjadi \"{$loan->status}\".");
            }
        });
    }

    /** Status pinjaman yang sudah dicairkan & masih punya sisa. */
    public const ACTIVE = ['berjalan', 'menunggak'];

    protected $fillable = ['code', 'member_id', 'principal', 'interest', 'tenor_months', 'payment_method', 'status', 'purpose', 'decision_note',
        'outstanding', 'applied_at', 'decided_at', 'decided_by', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'disbursed_at', 'paid_off_at', 'created_by'];

    protected function casts(): array
    {
        return ['principal' => 'decimal:2', 'interest' => 'decimal:2', 'outstanding' => 'decimal:2', 'tenor_months' => 'integer',
            'applied_at' => 'date', 'decided_at' => 'datetime', 'cancelled_at' => 'datetime', 'disbursed_at' => 'date', 'paid_off_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('sequence');
    }

    /** Riwayat perubahan seluruh angsuran pinjaman ini (append-only), terlama dulu. */
    /**
     * Hanya pinjaman milik anggota tertentu — satu-satunya pintu data pinjaman di portal anggota.
     * Tanpa anggota (null) tidak mengembalikan apa pun.
     */
    public function scopeOwnedBy(\Illuminate\Database\Eloquent\Builder $query, Member|int|null $member): void
    {
        $id = $member instanceof Member ? $member->getKey() : $member;
        $id === null ? $query->whereRaw('1 = 0') : $query->where('member_id', $id);
    }

    /**
     * Pinjaman aktif yang menunggak SAAT INI: berstatus menunggak, atau masih "berjalan" tetapi punya angsuran lewat
     * jatuh tempo (aturan LoanOverdue) yang belum tercermin karena status disegarkan terjadwal/saat pembayaran.
     */
    public function scopeEffectivelyOverdue(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->whereIn('status', self::ACTIVE)->where(fn ($q) => $q->where('status', 'menunggak')
            ->orWhereHas('installments', fn ($i) => \App\Support\LoanOverdue::scope($i)));
    }

    public function installmentChanges(): HasMany
    {
        return $this->hasMany(LoanInstallmentChange::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(LoanPayment::class)->orderBy('paid_at')->orderBy('id');
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(LoanDisbursement::class)->orderBy('disbursed_on')->orderBy('id');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
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
