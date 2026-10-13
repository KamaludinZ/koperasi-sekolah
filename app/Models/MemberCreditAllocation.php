<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bagian satu pelunasan yang dipakai untuk satu bon. */
class MemberCreditAllocation extends Model
{
    public $timestamps = false;

    protected $fillable = ['member_credit_payment_id', 'member_credit_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(MemberCreditPayment::class, 'member_credit_payment_id');
    }

    public function credit(): BelongsTo
    {
        return $this->belongsTo(MemberCredit::class, 'member_credit_id');
    }
}
