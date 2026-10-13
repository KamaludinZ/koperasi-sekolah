<?php

namespace App\Livewire\Audit;

use App\Livewire\Concerns\NotifiesSave;
use App\Support\MockData;
use App\Support\Petugas;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Audit → Data terhapus (admin & pengurus): barang, kategori, dan anggota yang dihapus lunak,
 * siapa & kapan menghapus, serta sisa masa simpan sebelum dibersihkan permanen. Admin dapat
 * memulihkan satu atau beberapa data sekaligus (dengan konfirmasi); data yang bentrok
 * (mis. kode sudah dipakai) ditolak dengan alasannya. Tahap ini memakai MockData::trashed().
 */
#[Layout('components.layouts.app')]
#[Title('Data Terhapus')]
class Trash extends Component
{
    use NotifiesSave;

    public const ENTITIES = [
        'barang' => 'Barang',
        'kategori' => 'Kategori',
        'anggota' => 'Anggota',
    ];

    /** Sisa hari ≤ batas ini ditandai "segera dibersihkan". */
    public const PURGE_SOON_DAYS = 7;

    #[Url(as: 'jenis', except: '')]
    public string $entity = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** @var list<int> */
    public array $selected = [];

    /** @var list<int> data yang menunggu konfirmasi pulihkan */
    public array $confirming = [];

    public function setEntity(string $entity): void
    {
        $this->entity = array_key_exists($entity, self::ENTITIES) ? $entity : '';
        $this->selected = [];
    }

    #[Computed]
    public function canRestore(): bool
    {
        return (Petugas::current()['role'] ?? null) === 'admin';
    }

    public static function daysLeft(array $row): int
    {
        return max(0, (int) ceil(now()->diffInDays(Carbon::parse($row['purge_at']), false)));
    }

    #[Computed]
    public function all(): array
    {
        return MockData::trashed();
    }

    #[Computed]
    public function items(): array
    {
        $term = mb_strtolower(trim($this->search));

        return collect($this->all)
            ->when($this->entity !== '', fn ($c) => $c->where('entity', $this->entity))
            ->when($term !== '', fn ($c) => $c->filter(fn ($r) => str_contains(mb_strtolower($r['label'].' '.$r['deleted_by'].' '.($r['reason'] ?? '')), $term)))
            ->sortByDesc('deleted_at')->values()->all();
    }

    #[Computed]
    public function summary(): array
    {
        $all = collect($this->all);

        return [
            'total' => $all->count(),
            'by_entity' => collect(self::ENTITIES)->map(fn ($l, $k) => $all->where('entity', $k)->count())->all(),
            'purge_soon' => $all->filter(fn ($r) => self::daysLeft($r) <= self::PURGE_SOON_DAYS)->count(),
            'conflicts' => $all->whereNotNull('conflict')->count(),
        ];
    }

    /** Baris yang sedang dikonfirmasi untuk dipulihkan. */
    #[Computed]
    public function pending(): array
    {
        return collect($this->all)->whereIn('id', $this->confirming)->values()->all();
    }

    public function toggleAll(): void
    {
        $ids = array_column(array_filter($this->items, fn ($r) => ! $r['conflict']), 'id');
        $this->selected = array_diff($ids, $this->selected) === [] ? [] : $ids;
    }

    public function confirmRestore(?int $id = null): void
    {
        if (! $this->canRestore) {
            $this->toast('error', 'Hanya Admin yang dapat memulihkan data.');

            return;
        }
        $this->confirming = $id !== null ? [$id] : array_values(array_map('intval', $this->selected));
    }

    public function cancelRestore(): void
    {
        $this->confirming = [];
    }

    public function restore(): void
    {
        if (! $this->canRestore || $this->confirming === []) {
            $this->confirming = [];

            return;
        }

        $result = MockData::restoreTrashed($this->confirming);
        $this->confirming = [];
        $this->selected = array_values(array_diff($this->selected, array_column($result['restored'], 'id')));
        unset($this->all, $this->items, $this->summary, $this->pending);

        if ($result['restored'] !== []) {
            $n = count($result['restored']);
            $this->toast('success', $n === 1 ? $result['restored'][0]['label'].' dipulihkan.' : $n.' data dipulihkan.');
        }
        foreach ($result['failed'] as $f) {
            $this->toast('error', $f['label'].' belum dapat dipulihkan: '.$f['reason']);
        }
    }

    public function render()
    {
        return view('livewire.audit.trash');
    }
}
