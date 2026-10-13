<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Bon (piutang) anggota dari satu transaksi kasir bermetode bon. */
class MemberCredit extends Model
{
    public const STATUSES = ['belum' => 'Belum dibayar', 'sebagian' => 'Dibayar sebagian', 'lunas' => 'Lunas', 'dibatalkan' => 'Dibatalkan'];

    protected $fillable = ['number', 'member_id', 'transaction_id', 'amount', 'paid', 'due_at', 'status', 'note', 'created_by', 'paid_off_at'];

    protected $attributes = ['paid' => 0, 'status' => 'belum'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid' => 'integer', 'due_at' => 'date', 'paid_off_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(MemberCreditAllocation::class);
    }

    /** Bon yang masih harus dibayar (belum / sebagian). */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', ['belum', 'sebagian']);
    }

    public function scopeOverdue(Builder $query): void
    {
        $query->open()->whereDate('due_at', '<', today());
    }

    public function remaining(): int
    {
        return $this->status === 'dibatalkan' ? 0 : max(0, $this->amount - $this->paid);
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, ['belum', 'sebagian'], true) && $this->due_at->lt(today());
    }

    /** Selaraskan paid & status dengan jumlah alokasi pelunasan. */
    public function syncPaid(): void
    {
        if ($this->status === 'dibatalkan') {
            return;
        }
        $paid = (int) $this->allocations()->sum('amount');
        $this->forceFill([
            'paid' => $paid,
            'status' => $paid >= $this->amount ? 'lunas' : ($paid > 0 ? 'sebagian' : 'belum'),
            'paid_off_at' => $paid >= $this->amount ? ($this->paid_off_at ?? now()) : null,
        ])->save();
    }
}
