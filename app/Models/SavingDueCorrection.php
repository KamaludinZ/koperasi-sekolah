<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu koreksi setoran bulanan (nominal lama → baru, selisih, alasan, petugas). */
class SavingDueCorrection extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = ['ubah' => 'Nominal dikoreksi', 'batal' => 'Posting dibatalkan', 'draf' => 'Catatan diubah'];

    protected $fillable = ['saving_due_id', 'action', 'old_amount', 'new_amount', 'delta', 'reason', 'entry_id', 'user_id', 'created_at'];

    protected function casts(): array
    {
        return ['old_amount' => 'decimal:2', 'new_amount' => 'decimal:2', 'delta' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function due(): BelongsTo
    {
        return $this->belongsTo(SavingDue::class, 'saving_due_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
