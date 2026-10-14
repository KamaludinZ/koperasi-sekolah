<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Setoran simpanan bulanan (potong gaji) anggota guru/karyawan per periode. */
class SavingDue extends Model
{
    public const STATUSES = ['belum' => 'Belum disetor', 'disetor' => 'Sudah disetor'];

    protected $fillable = ['member_id', 'saving_type_id', 'period', 'amount', 'status', 'recorded_by', 'posted_at', 'entry_id', 'note'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'posted_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function savingType(): BelongsTo
    {
        return $this->belongsTo(SavingType::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
