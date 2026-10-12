<?php

namespace App\Livewire\Notifikasi;

use App\Support\MockData;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Daftar notifikasi petugas: peringatan stok, pengumuman, dan info sistem, dikelompokkan
 * per hari; saring (semua, belum dibaca, stok, pengumuman), tandai dibaca/belum, tandai semua.
 * Data dari tabel notifications lewat NotificationService; status dibaca per petugas.
 */
#[Layout('components.layouts.app')]
#[Title('Notifikasi')]
class Index extends Component
{
    public const FILTERS = [
        'semua' => 'Semua',
        'belum' => 'Belum dibaca',
        'stok' => 'Stok',
        'pengumuman' => 'Pengumuman',
    ];

    public const TYPES = ['stok' => 'Stok', 'pengumuman' => 'Pengumuman', 'sistem' => 'Sistem'];

    public const TARGETS = ['semua' => 'Semua', 'pengurus' => 'Pengurus', 'kasir' => 'Kasir', 'anggota' => 'Anggota'];

    #[Url(as: 'tampil', except: 'semua')]
    public string $filter = 'semua';

    /** @var list<int> notifikasi yang dicentang untuk aksi massal */
    public array $selected = [];

    #[Computed]
    public function all(): array
    {
        return MockData::notifications();
    }

    #[Computed]
    public function items(): array
    {
        return collect($this->all)->filter(fn ($n) => match ($this->filter) {
            'belum' => ! $n['is_read'],
            'stok' => $n['type'] === 'stok',
            'pengumuman' => $n['type'] === 'pengumuman',
            default => true,
        })->values()->all();
    }

    /** @return array<string, list<array>> tanggal (Y-m-d) => notifikasi */
    #[Computed]
    public function days(): array
    {
        return collect($this->items)->groupBy(fn ($n) => substr($n['created_at'], 0, 10))->map->all()->all();
    }

    #[Computed]
    public function counts(): array
    {
        $all = collect($this->all);

        return [
            'semua' => $all->count(),
            'belum' => $all->where('is_read', false)->count(),
            'stok' => $all->where('type', 'stok')->count(),
            'pengumuman' => $all->where('type', 'pengumuman')->count(),
        ];
    }

    public function setFilter(string $filter): void
    {
        if (array_key_exists($filter, self::FILTERS)) {
            $this->filter = $filter;
            $this->selected = [];
        }
    }

    public function toggleRead(int $id): void
    {
        $n = collect($this->all)->firstWhere('id', $id);
        if ($n) {
            MockData::markNotifications([$id], ! $n['is_read']);
            $this->refresh();
        }
    }

    /** Buka tautan notifikasi sekaligus menandainya dibaca. */
    public function open(int $id)
    {
        $n = collect($this->all)->firstWhere('id', $id);
        if (! $n) {
            return null;
        }
        MockData::markNotifications([$id]);
        $this->refresh();

        return $n['url'] ? $this->redirect($n['url'], navigate: true) : null;
    }

    public function markAllRead(): void
    {
        $ids = collect($this->items)->where('is_read', false)->pluck('id')->values()->all();
        MockData::markNotifications($ids);
        $this->refresh();
        $this->dispatch('toast', type: 'success', message: count($ids).' notifikasi ditandai sudah dibaca.',
            action: ['label' => 'Urungkan', 'event' => 'undo-mark-read', 'params' => ['ids' => $ids]]);
    }

    /** Batalkan "tandai semua dibaca" dari tombol Urungkan di toast. */
    #[On('undo-mark-read')]
    public function undoMarkRead(array $ids = []): void
    {
        $known = collect($this->all)->pluck('id')->all();
        MockData::markNotifications(array_values(array_intersect(array_map('intval', $ids), $known)), false);
        $this->refresh();
    }

    public function toggleSelectAll(): void
    {
        $visible = collect($this->items)->pluck('id')->all();
        $this->selected = count(array_intersect($visible, $this->selected)) === count($visible) ? [] : $visible;
    }

    /** Tandai notifikasi yang dicentang sebagai dibaca / belum dibaca. */
    public function markSelected(bool $read = true): void
    {
        $known = collect($this->all)->pluck('id')->all();
        $ids = array_values(array_intersect(array_map('intval', $this->selected), $known));
        if ($ids === []) {
            return;
        }

        MockData::markNotifications($ids, $read);
        $this->selected = [];
        $this->refresh();
        $this->dispatch('toast', type: 'success', message: count($ids).' notifikasi ditandai '.($read ? 'sudah' : 'belum').' dibaca.');
    }

    public static function dayLabel(string $date): string
    {
        $d = Carbon::parse($date);

        return $d->isToday() ? 'Hari ini' : ($d->isYesterday() ? 'Kemarin' : $d->translatedFormat('l, d F Y'));
    }

    private function refresh(): void
    {
        unset($this->all, $this->items, $this->days, $this->counts);
        $this->dispatch('notifications-changed');
    }

    public function render()
    {
        return view('livewire.notifikasi.index');
    }
}
