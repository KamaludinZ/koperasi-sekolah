<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pelunasan bon anggota (tunai atau potong tabungan), dibagi ke bon lewat alokasi. */
class MemberCreditPayment extends Model
{
    public const METHODS = ['tunai' => 'Tunai', 'tabungan' => 'Potong saldo tabungan'];

    protected $fillable = ['number', 'member_id', 'amount', 'method', 'savings_transaction_id', 'note', 'paid_by', 'paid_at'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function savingsTransaction(): BelongsTo
    {
        return $this->belongsTo(SavingsTransaction::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(MemberCreditAllocation::class);
    }
}
