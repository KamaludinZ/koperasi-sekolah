<?php

namespace App\Livewire\Audit;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Services\AuditChangeQuery;
use App\Services\AuditQuery;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Audit → Perubahan data (admin & pengurus): riwayat dibuat/diubah/dihapus/dipulihkan untuk
 * barang, kategori, anggota, dan pengaturan beserta kolom sebelum → sesudah. Filter jenis data,
 * aksi, petugas, periode, kata kunci, perubahan sensitif, dan riwayat satu objek. Data dari
 * activity_logs lewat AuditChangeQuery (kontrak sama dengan GET /audit/perubahan/data).
 * Alamat IP hanya ditampilkan kepada Admin.
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
        'supplier' => 'Supplier',
        'retur' => 'Retur & pembatalan',
    ];

    public const ACTIONS = [
        'dibuat' => 'Dibuat',
        'diubah' => 'Diubah',
        'dihapus' => 'Dihapus',
        'dipulihkan' => 'Dipulihkan',
    ];

    public const FIELDS = [
        'code' => 'Kode', 'name' => 'Nama', 'contact' => 'Kontak', 'address' => 'Alamat', 'terms_days' => 'Tempo (hari)', 'note' => 'Catatan', 'color' => 'Warna', 'description' => 'Keterangan', 'urutan' => 'Urutan', 'category_id' => 'Kategori', 'price' => 'Harga jual', 'cost_price' => 'Harga modal',
        'min_stock' => 'Batas minimum', 'unit' => 'Satuan', 'is_active' => 'Aktif', 'member_code' => 'Kode anggota',
        'type' => 'Jenis', 'class' => 'Kelas', 'phone' => 'No. HP', 'email' => 'Email',
        'profile.app_name' => 'Nama aplikasi', 'profile.name' => 'Nama koperasi', 'profile.short_name' => 'Nama singkat',
        'profile.school' => 'Nama madrasah', 'profile.address' => 'Alamat', 'profile.phone' => 'Telepon koperasi',
        'profile.email' => 'Email koperasi', 'profile.logo' => 'Logo', 'profile.schedule' => 'Jam buka', 'profile.hours' => 'Jam buka',
        'profile.motto' => 'Motto',
        'receipt.header' => 'Judul struk', 'receipt.subheader' => 'Sub-judul struk', 'receipt.show_logo' => 'Logo di struk',
        'receipt.show_address' => 'Alamat di struk', 'receipt.show_phone' => 'Telepon di struk', 'receipt.show_cashier' => 'Nama kasir di struk',
        'receipt.show_member' => 'Anggota di struk', 'receipt.note' => 'Catatan struk', 'receipt.footer' => 'Penutup struk',
        'receipt.paper' => 'Lebar kertas',
        'tax.label' => 'Nama pajak', 'tax.enabled' => 'Pajak aktif', 'tax.rate' => 'Tarif pajak (%)', 'tax.included' => 'Pajak termasuk harga',
        'tax.rounding' => 'Pembulatan', 'tax.rounding_unit' => 'Kelipatan pembulatan', 'tax.cash_only' => 'Pembulatan hanya tunai',
        'discount.enabled' => 'Diskon manual aktif', 'discount.max_percent' => 'Batas diskon (%)', 'discount.max_amount' => 'Batas diskon (Rp)',
        'discount.reason_required' => 'Alasan diskon wajib',
        'kind' => 'Jenis', 'status' => 'Status', 'amount' => 'Nilai', 'quantity' => 'Jumlah barang', 'refund_method' => 'Pengembalian dana',
        'restock' => 'Kembali ke stok', 'reason' => 'Alasan', 'decided_by' => 'Diputus oleh',
    ];

    /** Kolom bernilai uang (ditampilkan sebagai Rupiah). */
    public const MONEY = ['price', 'cost_price', 'discount.max_amount', 'amount'];

    /** Label kolom; kolom tanpa label dibaca dari namanya (mis. "show_logo" → "Show logo"). */
    public static function label(string $field): string
    {
        return self::FIELDS[$field] ?? ucfirst(str_replace('_', ' ', \Illuminate\Support\Str::afterLast($field, '.')));
    }



    /** Kolom sensitif: perubahannya (bukan pengisian awal) ditandai agar mudah ditinjau, begitu pula penghapusan. */
    public const SENSITIVE = AuditChangeQuery::SENSITIVE;

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
        return AuditChangeQuery::isSensitive($row);
    }

    /** Nilai kolom untuk ditampilkan: Rupiah, Ya/Tidak, atau "—" bila kosong. */
    public static function format(string $field, mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            is_bool($value) => $value ? 'Ya' : 'Tidak',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            in_array($field, self::MONEY, true) => 'Rp'.number_format((int) $value, 0, ',', '.'),
            default => (string) $value,
        };
    }

    /** @return array{0: ?\Illuminate\Support\Carbon, 1: ?\Illuminate\Support\Carbon} */
    #[Computed]
    public function range(): array
    {
        return AuditQuery::range($this->period);
    }

    private function filters(): array
    {
        [$from, $to] = $this->range;

        return [
            'entity' => $this->entity, 'action' => $this->action, 'actor' => $this->actor, 'search' => $this->search,
            'subject' => $this->subject, 'sensitive' => $this->sensitiveOnly, 'from' => $from, 'to' => $to,
        ];
    }

    #[Computed]
    public function canSeeIp(): bool
    {
        return (bool) auth()->user()?->hasRole(Role::ADMIN);
    }

    /** Jumlah perubahan yang cocok dengan saringan. */
    #[Computed]
    public function total(): int
    {
        return app(AuditChangeQuery::class)->query($this->filters())->reorder()->count();
    }

    /** @return list<array<string, mixed>> baris yang ditampilkan (sebanyak $limit), terbaru dulu */
    #[Computed]
    public function rows(): array
    {
        return app(AuditChangeQuery::class)->query($this->filters())->limit($this->limit)->get()
            ->map(fn (ActivityLog $l) => AuditChangeQuery::toArray($l, $this->canSeeIp))->all();
    }

    /** Baris yang ditampilkan, dikelompokkan per tanggal. */
    #[Computed]
    public function days(): array
    {
        return collect($this->rows)->groupBy(fn ($r) => substr($r['at'], 0, 10))->all();
    }

    #[Computed]
    public function summary(): array
    {
        return app(AuditChangeQuery::class)->summary(...$this->range);
    }

    #[Computed]
    public function subjectLabel(): ?string
    {
        return $this->subject !== '' ? app(AuditChangeQuery::class)->subjectLabel($this->subject) : null;
    }

    #[Computed]
    public function selected(): ?array
    {
        $log = $this->selectedId ? ActivityLog::query()->masterChanges()->find($this->selectedId) : null;

        return $log ? AuditChangeQuery::toArray($log, $this->canSeeIp) : null;
    }

    public function render()
    {
        return view('livewire.audit.changes');
    }
}
