<?php

namespace App\Livewire\Transaksi;

use App\Support\MockData;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Pemberitahuan status pengajuan retur/pembatalan milik pengguna yang login (biasanya kasir):
 * pengajuan yang sudah diputus sejak terakhir dilihat tampil sebagai banner (disetujui/ditolak
 * beserta alasan penolakan), pengajuan yang masih menunggu ditampilkan jumlahnya.
 * "Sudah dilihat" disimpan di sesi. Tahap ini dari MockData::transactionReturns(); backend
 * menyusul dengan notifikasi tersimpan.
 */
class ReturnStatusNotice extends Component
{
    public const SEEN_KEY = 'returns_seen_at';

    public bool $compact = false;

    /** @return list<array<string, mixed>> pengajuan milik pengguna ini, terbaru dulu */
    #[Computed]
    public function mine(): array
    {
        $name = auth()->user()->name;

        return array_values(array_filter(MockData::transactionReturns(), fn ($r) => $r['requested_by'] === $name));
    }

    /** Pengajuan yang diputus setelah terakhir dilihat. */
    #[Computed]
    public function unseen(): array
    {
        $seen = session(self::SEEN_KEY);

        return array_values(array_filter($this->mine, fn ($r) => $r['decided_at'] && (! $seen || Carbon::parse($r['decided_at'])->gt(Carbon::parse($seen)))));
    }

    #[Computed]
    public function pending(): int
    {
        return count(array_filter($this->mine, fn ($r) => $r['status'] === 'menunggu'));
    }

    public function markSeen(): void
    {
        session([self::SEEN_KEY => now()->toDateTimeString()]);
        unset($this->unseen);
    }

    public function render()
    {
        return view('livewire.transaksi.return-status-notice');
    }
}
