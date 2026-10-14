<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Saldo satu jenis simpanan milik satu anggota. Hanya diubah lewat MemberSavingService. */
class MemberSavingAccount extends Model
{
    protected $fillable = ['member_id', 'saving_type_id', 'balance'];

    protected function casts(): array
    {
        return ['balance' => 'decimal:2'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function savingType(): BelongsTo
    {
        return $this->belongsTo(SavingType::class);
    }
}
