<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pembayaran angsuran (uang yang benar-benar diterima) beserta alokasinya ke angsuran; koreksi menandai voided_at. */
class LoanPayment extends Model
{
    protected $fillable = ['loan_id', 'paid_at', 'amount', 'payment_method', 'reference', 'note', 'allocations', 'user_id', 'voided_at', 'voided_by', 'void_reason', 'replaces_id', 'client_ref'];

    /** True bila objek ini dikembalikan dari kiriman ulang (kunci idempoten sama), bukan pembayaran baru. */
    public bool $wasReplayed = false;

    protected function casts(): array
    {
        return ['paid_at' => 'date', 'amount' => 'decimal:2', 'allocations' => 'array', 'voided_at' => 'datetime'];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeValid(Builder $query): void
    {
        $query->whereNull('voided_at');
    }
}
