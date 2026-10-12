<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kotak notifikasi petugas (tabel notifications + notification_reads): daftar terbaru
 * dengan saringan, jumlah per saringan, jumlah belum dibaca, dan tandai dibaca. Juga
 * menyelaraskan peringatan stok (satu notifikasi per barang menipis/habis).
 */
class NotificationService
{
    public const FILTERS = ['semua', 'belum', 'stok', 'pengumuman', 'sistem'];

    /** @var array<int, true> barang yang menunggu pemeriksaan peringatan stok */
    private array $pendingStock = [];

    /**
     * Antre pemeriksaan peringatan stok untuk barang-barang ini; dijalankan sekali
     * setelah transaksi database selesai (langsung bila tidak sedang dalam transaksi).
     * Dipanggil dari StockService, MinStockService, dan event model Product.
     */
    public function queueStockCheck(int ...$productIds): void
    {
        foreach ($productIds as $id) {
            $this->pendingStock[$id] = true;
        }

        // Callback pertama yang jalan memproses seluruh antrean; sisanya tidak berbuat apa-apa.
        // (Bila transaksi dibatalkan, callback ikut dibuang dan antrean diproses pada commit berikutnya.)
        if ($productIds !== []) {
            DB::afterCommit(function () {
                if ($this->pendingStock === []) {
                    return;
                }
                $ids = array_keys($this->pendingStock);
                $this->pendingStock = [];
                $this->syncStockAlerts(Product::whereKey($ids)->get(), $ids);
            });
        }
    }

    /**
     * @return list<array{id: int, type: string, type_label: string, title: string, message: string, target: string, target_label: string, url: ?string, is_read: bool, author: ?string, show_public: bool, expires_at: ?string, created_at: string}>
     */
    public function list(User $user, string $filter = 'semua', ?int $limit = null): array
    {
        return $user->inbox()
            ->with('author:id,name')
            ->when($filter === 'belum', fn ($q) => $q->unread($user))
            ->when(in_array($filter, ['stok', 'pengumuman', 'sistem'], true), fn ($q) => $q->where('type', $filter))
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn (Notification $n) => self::toArray($n))
            ->all();
    }

    /** Jumlah per saringan untuk tab halaman Notifikasi. */
    public function counts(User $user): array
    {
        $byType = $user->inbox()->reorder()->pluck('notifications.type')->countBy();

        return [
            'semua' => (int) $byType->sum(),
            'belum' => $this->unreadCount($user),
            'stok' => (int) ($byType['stok'] ?? 0),
            'pengumuman' => (int) ($byType['pengumuman'] ?? 0),
            'sistem' => (int) ($byType['sistem'] ?? 0),
        ];
    }

    public function unreadCount(User $user): int
    {
        return $user->inbox()->unread($user)->reorder()->count();
    }

    /**
     * Tandai dibaca/belum dibaca, hanya untuk notifikasi yang memang ada di kotak petugas.
     *
     * @return list<int> id yang benar-benar ditandai
     */
    public function mark(User $user, array $ids, bool $read = true): array
    {
        $ids = $user->inbox()->reorder()->whereIn('notifications.id', array_map('intval', $ids))->pluck('notifications.id')->all();
        Notification::markFor($user, $ids, $read);

        return $ids;
    }

    /** Tandai semua (opsional: satu saringan) dibaca; mengembalikan id yang berubah. */
    public function markAll(User $user, string $filter = 'semua'): array
    {
        $ids = collect($this->list($user, $filter))->where('is_read', false)->pluck('id')->all();

        return $this->mark($user, $ids);
    }

    /**
     * Sembunyikan dari kotak petugas (hanya notifikasi miliknya). Peringatan stok tidak
     * bisa disembunyikan selama kondisinya masih berlaku — cukup ditandai dibaca.
     *
     * @return list<int>
     */
    public function dismiss(User $user, array $ids): array
    {
        $ids = $user->inbox()->reorder()->whereIn('notifications.id', array_map('intval', $ids))
            ->where('type', '!=', 'stok')->pluck('notifications.id')->all();
        Notification::dismissFor($user, $ids);

        return $ids;
    }

    public function find(User $user, int $id): ?Notification
    {
        return $user->inbox()->find($id);
    }

    /**
     * Selaraskan peringatan stok dengan kondisi barang saat ini: buat notifikasi untuk
     * barang aktif yang menipis/habis (sekali per kondisi, lewat dedupe_key), hapus
     * peringatan barang yang stoknya sudah aman. Mengembalikan jumlah notifikasi baru.
     */
    public function syncStockAlerts(?Collection $products = null, ?array $productIds = null): int
    {
        // Barang yang dihapus/dinonaktifkan tidak perlu diperingatkan lagi.
        Notification::where('type', 'stok')
            ->where(fn ($q) => $q->whereNull('product_id')->orWhereIn('product_id', Product::where('is_active', false)->select('id')))
            ->when($productIds !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('product_id')->orWhereIn('product_id', $productIds)))
            ->delete();

        $products ??= Product::active()->get();
        $created = 0;

        foreach ($products as $p) {
            $state = $p->is_active && $p->stock <= 0 ? 'habis' : ($p->is_active && $p->stock <= $p->min_stock ? 'menipis' : null);
            $stale = Notification::where('type', 'stok')->where('product_id', $p->id)
                ->when($state, fn ($q) => $q->where('dedupe_key', '!=', "stok:{$p->id}:{$state}"));
            $stale->delete();

            if ($state && ! Notification::where('dedupe_key', "stok:{$p->id}:{$state}")->exists()) {
                Notification::create([
                    'type' => 'stok',
                    'target' => 'pengurus',
                    'product_id' => $p->id,
                    'dedupe_key' => "stok:{$p->id}:{$state}",
                    'title' => $state === 'habis' ? "Stok {$p->name} habis" : "Stok {$p->name} menipis",
                    'message' => $state === 'habis'
                        ? "{$p->name} ({$p->code}) sudah habis. Segera tambah stok agar bisa dijual lagi."
                        : "Sisa {$p->stock} {$p->unit}, di bawah batas minimum {$p->min_stock} {$p->unit}.",
                    'url' => route('barang', ['status' => $state], false),
                ]);
                $created++;
            }
        }

        return $created;
    }

    public static function toArray(Notification $n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            'type_label' => $n->type_label,
            'title' => $n->title,
            'message' => $n->message,
            'target' => $n->target,
            'target_label' => $n->target_label,
            'url' => $n->url,
            'is_read' => $n->is_read,
            'author' => $n->author?->name,
            'show_public' => $n->show_public,
            'expires_at' => $n->expires_at?->toDateString(),
            'created_at' => $n->created_at->toDateTimeString(),
        ];
    }
}
