<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Satu baris riwayat perubahan angsuran (append-only): kolom yang berubah beserta nilai sebelum/sesudah, jenis
 * peristiwa, pembayaran terkait, alasan, dan pelaku. Tidak bisa diubah/dihapus lewat model; di PostgreSQL juga
 * dijaga trigger.
 */
class LoanInstallmentChange extends Model
{
    public const UPDATED_AT = null;

    public const EVENTS = ['pembayaran' => 'Pembayaran', 'koreksi' => 'Koreksi', 'status' => 'Perubahan status', 'jadwal' => 'Perubahan jadwal'];

    /** Kolom angsuran yang dilacak. */
    public const TRACKED = ['due_date', 'amount', 'principal_part', 'interest_part', 'paid_amount', 'paid_at', 'payment_method', 'status', 'note'];

    protected $fillable = ['loan_id', 'loan_installment_id', 'sequence', 'event', 'changes', 'loan_payment_id', 'reason', 'user_id', 'actor_name', 'created_at', 'checksum'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime', 'sequence' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            $c->created_at ??= now();
            $c->checksum = \App\Services\LoanAuditChain::changeChecksum($c->getAttributes());
        });
        static::created(fn (self $c) => app(\App\Services\LoanAuditChain::class)->append('installment_change', $c->id, (int) $c->loan_id, $c->checksum));
        static::updating(fn () => throw new LogicException('Riwayat perubahan angsuran tidak bisa diubah.'));
        static::deleting(fn () => throw new LogicException('Riwayat perubahan angsuran tidak bisa dihapus.'));
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(LoanInstallment::class, 'loan_installment_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(LoanPayment::class, 'loan_payment_id');
    }

    /** Nilai kolom dinormalkan untuk riwayat: tanggal → Y-m-d, nominal → rupiah bulat. */
    public static function normalize(string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (in_array($field, ['due_date', 'paid_at'], true)) {
            return substr((string) $value, 0, 10);
        }
        if (in_array($field, ['amount', 'principal_part', 'interest_part', 'paid_amount'], true)) {
            return (int) round((float) $value);
        }

        return $value;
    }

    /**
     * Selisih dua potret angsuran (kolom TRACKED) → {kolom: {from, to}}; kosong bila tidak ada yang berubah.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $out = [];
        foreach (self::TRACKED as $f) {
            $from = self::normalize($f, $before[$f] ?? null);
            $to = self::normalize($f, $after[$f] ?? null);
            if ($from !== $to) {
                $out[$f] = ['from' => $from, 'to' => $to];
            }
        }

        return $out;
    }
}
