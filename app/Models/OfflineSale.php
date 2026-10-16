<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Penjualan dari kasir offline yang dikirim perangkat (kotak masuk sinkron). */
class OfflineSale extends Model
{
    public const STATUSES = ['diterima' => 'Diterima', 'perlu_dicek' => 'Perlu dicek', 'ditolak' => 'Ditolak'];

    public const FLAGS = [
        'stok_kurang' => 'Stok di sistem kurang — cocokkan lewat stok opname',
        'tanpa_shift' => 'Tidak ada shift kas terbuka pada jam penjualan',
        'barang_tiada' => 'Barang sudah dihapus/nonaktif saat sinkron',
        'terlambat' => 'Dikirim lebih dari 3 hari setelah penjualan',
    ];

    protected $fillable = ['client_ref', 'catalog_id', 'user_id', 'device_id', 'local_number', 'sold_at', 'payload', 'total', 'payment_method',
        'status', 'flags', 'reason', 'transaction_id', 'received_at', 'reviewed_by', 'reviewed_at', 'review_note'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'flags' => 'array', 'sold_at' => 'datetime', 'received_at' => 'datetime', 'reviewed_at' => 'datetime', 'total' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
