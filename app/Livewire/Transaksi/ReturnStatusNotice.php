<?php

namespace App\Livewire\Transaksi;

use App\Models\TransactionReturn;
use App\Services\TransactionReturnService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Pemberitahuan status pengajuan retur/pembatalan milik pengguna yang login (biasanya kasir):
 * pengajuan yang sudah diputus sejak terakhir dilihat tampil sebagai banner (disetujui/ditolak
 * beserta alasan penolakan), pengajuan yang masih menunggu ditampilkan jumlahnya.
 * "Sudah dilihat" disimpan di sesi. Data dari transaction_returns (keputusan otomatis atas pembatalan
 * sendiri tidak ditampilkan); notifikasi tersimpan dikirim TransactionReturnService ke lonceng.
 */
class ReturnStatusNotice extends Component
{
    public const SEEN_KEY = 'returns_seen_at';

    public bool $compact = false;

    /** @return list<array<string, mixed>> pengajuan milik pengguna ini, terbaru dulu */
    #[Computed]
    public function mine(): array
    {
        return TransactionReturn::with(['items', 'transaction.user:id,name', 'requester:id,name', 'decider:id,name'])
            ->where('requested_by', auth()->id())
            ->where(fn ($q) => $q->where('status', 'menunggu')->orWhereNotNull('decided_by'))
            ->where('requested_at', '>=', now()->subDays(30))
            ->latest('requested_at')->latest('id')->limit(50)->get()
            ->map(fn (TransactionReturn $r) => TransactionReturnService::toArray($r))->all();
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
