<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mutasi simpanan per jenis: setor | tarik | potong (bayar belanja). */
class MemberSavingEntry extends Model
{
    public const TYPES = ['setor' => 'Setoran', 'tarik' => 'Penarikan', 'potong' => 'Potong belanja'];

    protected $fillable = ['member_id', 'saving_type_id', 'type', 'amount', 'balance_before', 'balance_after', 'period', 'note',
        'source_type', 'source_id', 'client_ref', 'user_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'balance_before' => 'decimal:2', 'balance_after' => 'decimal:2'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function savingType(): BelongsTo
    {
        return $this->belongsTo(SavingType::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
