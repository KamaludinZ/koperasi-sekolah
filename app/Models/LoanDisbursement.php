<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pencairan dana pinjaman (tanggal, nominal, bukti serah terima). */
class LoanDisbursement extends Model
{
    protected $fillable = ['loan_id', 'disbursed_on', 'amount', 'proof', 'proof_file', 'proof_mime', 'proof_size', 'proof_sha256', 'user_id'];

    protected static function booted(): void
    {
        // jaring pengaman jejak audit: dibuat/diubah di luar alur resmi tercatat otomatis (LoanAutoAudit)
        static::created(fn (self $m) => \App\Services\LoanAutoAudit::captureRecord($m, 'disbursement'));
        static::updated(fn (self $m) => \App\Services\LoanAutoAudit::captureRecord($m, 'disbursement'));
    }

    protected function casts(): array
    {
        return ['disbursed_on' => 'date', 'amount' => 'decimal:2', 'proof_size' => 'integer'];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
