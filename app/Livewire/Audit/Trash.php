<?php

namespace App\Livewire\Audit;

use App\Livewire\Concerns\NotifiesSave;
use App\Services\TrashService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Audit → Data terhapus (admin & pengurus): barang, kategori, dan anggota yang dihapus lunak,
 * siapa & kapan menghapus, serta sisa masa simpan sebelum dibersihkan permanen. Admin dapat
 * memulihkan satu atau beberapa data sekaligus (dengan konfirmasi); data yang bentrok
 * (mis. kode sudah dipakai) ditolak dengan alasannya. Data dari TrashService
 * (kontrak sama dengan GET /audit/terhapus/data).
 */
#[Layout('components.layouts.app')]
#[Title('Data Terhapus')]
class Trash extends Component
{
    use NotifiesSave;

    public const ENTITIES = TrashService::LABELS;

    /** Sisa hari ≤ batas ini ditandai "segera dibersihkan". */
    public const PURGE_SOON_DAYS = 7;

    #[Url(as: 'jenis', except: '')]
    public string $entity = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** @var list<string> kunci "jenis:id" yang dipilih */
    public array $selected = [];

    /** @var list<string> data yang menunggu konfirmasi pulihkan */
    public array $confirming = [];

    public function setEntity(string $entity): void
    {
        $this->entity = array_key_exists($entity, self::ENTITIES) ? $entity : '';
        $this->selected = [];
    }

    #[Computed]
    public function canRestore(): bool
    {
        return Gate::allows('pulihkan-data');
    }

    public static function daysLeft(array $row): int
    {
        return $row['days_left'];
    }

    #[Computed]
    public function items(): array
    {
        return app(TrashService::class)->list(['entity' => $this->entity, 'search' => $this->search]);
    }

    #[Computed]
    public function summary(): array
    {
        return app(TrashService::class)->summary(self::PURGE_SOON_DAYS);
    }

    /** Baris yang sedang dikonfirmasi untuk dipulihkan. */
    #[Computed]
    public function pending(): array
    {
        return collect(app(TrashService::class)->list())->whereIn('key', $this->confirming)->values()->all();
    }

    public function toggleAll(): void
    {
        $keys = array_column(array_filter($this->items, fn ($r) => ! $r['conflict']), 'key');
        $this->selected = array_diff($keys, $this->selected) === [] ? [] : $keys;
    }

    public function confirmRestore(?string $key = null): void
    {
        if (! $this->canRestore) {
            $this->toast('error', 'Hanya Admin yang dapat memulihkan data.');

            return;
        }
        $this->confirming = $key !== null ? [$key] : array_values(array_map('strval', $this->selected));
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

        $result = app(TrashService::class)->restore($this->confirming);
        $this->confirming = [];
        $this->selected = array_values(array_diff($this->selected, array_column($result['restored'], 'key')));
        unset($this->items, $this->summary, $this->pending);

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
