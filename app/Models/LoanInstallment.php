<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Angsuran pinjaman: jadwal (ke-n, jatuh tempo, nominal) & pembayarannya. */
class LoanInstallment extends Model
{
    public const STATUSES = ['belum' => 'Belum dibayar', 'dibayar' => 'Dibayar', 'menunggak' => 'Menunggak'];

    protected $fillable = ['loan_id', 'sequence', 'due_date', 'amount', 'paid_amount', 'paid_at', 'payment_method', 'status', 'note', 'user_id'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'paid_at' => 'date', 'amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'sequence' => 'integer'];
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
