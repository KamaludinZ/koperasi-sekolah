<?php

namespace App\Livewire\Audit;

use App\Services\AuditQuery;
use App\Support\MockData;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Audit → Perubahan data (admin & pengurus): riwayat dibuat/diubah/dihapus/dipulihkan untuk
 * barang, kategori, anggota, dan pengaturan beserta kolom sebelum → sesudah. Filter jenis data,
 * aksi, petugas, periode, kata kunci, dan riwayat satu objek. Tahap ini memakai
 * MockData::masterChanges(); backend menyusul dengan kontrak yang sama.
 */
#[Layout('components.layouts.app')]
#[Title('Perubahan Data')]
class Changes extends Component
{
    public const PER_PAGE = 15;

    public const ENTITIES = [
        'barang' => 'Barang',
        'kategori' => 'Kategori',
        'anggota' => 'Anggota',
        'pengaturan' => 'Pengaturan',
    ];

    public const ACTIONS = [
        'dibuat' => 'Dibuat',
        'diubah' => 'Diubah',
        'dihapus' => 'Dihapus',
        'dipulihkan' => 'Dipulihkan',
    ];

    public const FIELDS = [
        'code' => 'Kode', 'name' => 'Nama', 'category_id' => 'Kategori', 'price' => 'Harga jual', 'cost_price' => 'Harga modal',
        'min_stock' => 'Batas minimum', 'unit' => 'Satuan', 'is_active' => 'Aktif', 'member_code' => 'Kode anggota',
        'type' => 'Jenis', 'class' => 'Kelas', 'phone' => 'No. HP', 'email' => 'Email',
        'discount.max_percent' => 'Batas diskon (%)', 'discount.reason_required' => 'Alasan diskon wajib',
        'tax.rate' => 'Tarif pajak (%)', 'tax.rounding' => 'Pembulatan', 'profile.phone' => 'Telepon koperasi',
        'profile.hours' => 'Jam buka', 'receipt.paper' => 'Lebar kertas (mm)', 'receipt.footer' => 'Penutup struk',
    ];

    /** Kolom bernilai uang (ditampilkan sebagai Rupiah). */
    public const MONEY = ['price', 'cost_price'];

    /** Kolom sensitif: perubahannya (bukan pengisian awal) ditandai agar mudah ditinjau, begitu pula penghapusan. */
    public const SENSITIVE = ['price', 'cost_price', 'is_active', 'discount.max_percent', 'tax.rate'];

    #[Url(as: 'jenis', except: '')]
    public string $entity = '';

    #[Url(as: 'aksi', except: '')]
    public string $action = '';

    #[Url(as: 'periode', except: '30-hari')]
    public string $period = '30-hari';

    #[Url(as: 'petugas', except: '')]
    public string $actor = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Riwayat satu objek, format "entitas:id". */
    #[Url(as: 'objek', except: '')]
    public string $subject = '';

    public bool $sensitiveOnly = false;

    public int $limit = self::PER_PAGE;

    public ?int $selectedId = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['entity', 'action', 'actor', 'search', 'sensitiveOnly'], true)) {
            $this->limit = self::PER_PAGE;
        }
    }

    public function setEntity(string $entity): void
    {
        $this->entity = array_key_exists($entity, self::ENTITIES) ? $entity : '';
        $this->subject = '';
        $this->limit = self::PER_PAGE;
    }

    public function setAction(string $action): void
    {
        $this->action = $this->action === $action || ! array_key_exists($action, self::ACTIONS) ? '' : $action;
        $this->limit = self::PER_PAGE;
    }

    public function setPeriod(string $period): void
    {
        if (array_key_exists($period, AuditQuery::PERIODS)) {
            $this->period = $period;
            $this->limit = self::PER_PAGE;
        }
    }

    public function followSubject(string $entity, int $id): void
    {
        $this->subject = $entity.':'.$id;
        $this->selectedId = null;
        $this->limit = self::PER_PAGE;
    }

    public function resetFilters(): void
    {
        $this->reset('entity', 'action', 'period', 'actor', 'search', 'subject', 'sensitiveOnly', 'limit');
    }

    public function more(): void
    {
        $this->limit += self::PER_PAGE;
    }

    public function show(int $id): void
    {
        $this->selectedId = $id;
    }

    public function closeDetail(): void
    {
        $this->selectedId = null;
    }

    public static function isSensitive(array $row): bool
    {
        return $row['action'] === 'dihapus'
            || ($row['action'] !== 'dibuat' && array_intersect(array_keys($row['changes']), self::SENSITIVE) !== []);
    }

    /** Nilai kolom untuk ditampilkan: Rupiah, Ya/Tidak, atau "—" bila kosong. */
    public static function format(string $field, mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            is_bool($value) => $value ? 'Ya' : 'Tidak',
            in_array($field, self::MONEY, true) => 'Rp'.number_format((int) $value, 0, ',', '.'),
            default => (string) $value,
        };
    }

    /** @return list<array<string, mixed>> semua perubahan pada periode, terbaru dulu */
    #[Computed]
    public function all(): array
    {
        [$from] = AuditQuery::range($this->period);

        return collect(MockData::masterChanges())
            ->filter(fn ($r) => $from === null || Carbon::parse($r['at'])->gte($from))
            ->sortByDesc('at')->values()->all();
    }

    #[Computed]
    public function filtered(): array
    {
        $term = mb_strtolower(trim($this->search));

        return collect($this->all)
            ->when($this->entity !== '', fn ($c) => $c->where('entity', $this->entity))
            ->when($this->action !== '', fn ($c) => $c->where('action', $this->action))
            ->when($this->actor !== '', fn ($c) => $c->where('actor_name', $this->actor))
            ->when($this->subject !== '', fn ($c) => $c->filter(fn ($r) => $r['entity'].':'.$r['subject_id'] === $this->subject))
            ->when($this->sensitiveOnly, fn ($c) => $c->filter(fn ($r) => self::isSensitive($r)))
            ->when($term !== '', fn ($c) => $c->filter(fn ($r) => str_contains(mb_strtolower($r['subject_label'].' '.$r['actor_name'].' '.($r['reason'] ?? '')), $term)))
            ->values()->all();
    }

    /** Baris yang ditampilkan, dikelompokkan per tanggal. */
    #[Computed]
    public function days(): array
    {
        return collect($this->filtered)->take($this->limit)
            ->groupBy(fn ($r) => substr($r['at'], 0, 10))->all();
    }

    #[Computed]
    public function summary(): array
    {
        $all = collect($this->all);

        return [
            'total' => $all->count(),
            'by_entity' => collect(self::ENTITIES)->map(fn ($l, $k) => $all->where('entity', $k)->count())->all(),
            'price_changes' => $all->filter(fn ($r) => $r['action'] === 'diubah' && isset($r['changes']['price']))->count(),
            'deleted' => $all->where('action', 'dihapus')->count(),
            'restored' => $all->where('action', 'dipulihkan')->count(),
            'sensitive' => $all->filter(fn ($r) => self::isSensitive($r))->count(),
            'actors' => $all->countBy('actor_name')->sortDesc()->all(),
        ];
    }

    #[Computed]
    public function subjectLabel(): ?string
    {
        return collect(MockData::masterChanges())
            ->first(fn ($r) => $r['entity'].':'.$r['subject_id'] === $this->subject)['subject_label'] ?? null;
    }

    #[Computed]
    public function selected(): ?array
    {
        return $this->selectedId ? collect(MockData::masterChanges())->firstWhere('id', $this->selectedId) : null;
    }

    public function render()
    {
        return view('livewire.audit.changes');
    }
}
