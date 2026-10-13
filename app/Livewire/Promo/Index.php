<?php

namespace App\Livewire\Promo;

use App\Support\MockData;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Diskon & Promo: ringkasan promo otomatis (per barang/kategori/semua, beli X gratis Y,
 * harga grosir, jam tertentu), kupon & voucher dengan kuota, serta batas diskon manual kasir.
 * Kasir melihat promo yang berlaku; admin & pengurus mengelolanya.
 * Tahap frontend: data tiruan MockData::promos/coupons/manualDiscountRules.
 */
#[Layout('components.layouts.app')]
#[Title('Diskon & Promo')]
class Index extends Component
{
    public const STATUSES = [
        'aktif' => 'Berlaku',
        'terjadwal' => 'Terjadwal',
        'berakhir' => 'Berakhir',
        'nonaktif' => 'Nonaktif',
        'semua' => 'Semua',
    ];

    public const DAY_LABELS = [1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'];

    #[Url(as: 'status', except: 'aktif')]
    public string $status = 'aktif';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        if (! array_key_exists($this->status, self::STATUSES)) {
            $this->status = 'aktif';
        }
    }

    public function setStatus(string $status): void
    {
        if (array_key_exists($status, self::STATUSES)) {
            $this->status = $status;
        }
    }

    /** Admin & pengurus boleh membuat/mengubah promo dan kupon; kasir hanya melihat. */
    #[Computed]
    public function canManage(): bool
    {
        return (bool) auth()->user()?->hasRole('admin', 'pengurus');
    }

    #[Computed]
    public function promos(): array
    {
        $q = mb_strtolower(trim($this->search));

        return array_values(array_filter(MockData::promos(), fn (array $p) => ($this->status === 'semua' || $p['status'] === $this->status)
            && ($q === '' || str_contains(mb_strtolower($p['name'].' '.implode(' ', $p['targets'])), $q))));
    }

    /** Jumlah promo per status, untuk label saringan. */
    #[Computed]
    public function counts(): array
    {
        $counts = collect(MockData::promos())->countBy('status');

        return collect(self::STATUSES)->keys()->mapWithKeys(fn ($s) => [$s => $s === 'semua' ? $counts->sum() : ($counts[$s] ?? 0)])->all();
    }

    #[Computed]
    public function coupons(): array
    {
        $order = ['aktif' => 0, 'terjadwal' => 1, 'habis' => 2, 'berakhir' => 3, 'nonaktif' => 4];

        return collect(MockData::coupons())->sortBy(fn ($c) => $order[$c['status']] ?? 9)->values()->all();
    }

    #[Computed]
    public function stats(): array
    {
        $promos = collect(MockData::promos());
        $coupons = collect(MockData::coupons());
        $manual = MockData::manualDiscountRules();

        return [
            'active_promos' => $promos->where('status', 'aktif')->count(),
            'scheduled_promos' => $promos->where('status', 'terjadwal')->count(),
            'active_coupons' => $coupons->where('status', 'aktif')->count(),
            'coupon_uses' => $coupons->sum('used'),
            'promo_uses' => $promos->sum('usage_count'),
            'discount_given' => $promos->sum('discount_given') + $manual['given_7_days'],
        ];
    }

    #[Computed]
    public function manual(): array
    {
        return MockData::manualDiscountRules();
    }

    /** Ringkasan aturan promo dalam satu kalimat, mis. "Potongan 10% · kategori Alat Tulis". */
    public static function describe(array $p): string
    {
        $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
        $rule = match ($p['type']) {
            'persen' => 'Potongan '.$p['value'].'%',
            'nominal' => 'Potongan '.$rp($p['value']).' per barang',
            'beli_gratis' => 'Beli '.$p['buy_qty'].' gratis '.$p['free_qty'],
            'grosir' => 'Harga '.$rp($p['value']).'/pcs mulai '.$p['min_qty'].' pcs',
            default => $p['type'],
        };
        $scope = match ($p['scope']) {
            'kategori' => 'kategori '.implode(', ', $p['targets']),
            'barang' => implode(', ', $p['targets']),
            default => 'semua barang',
        };

        return $rule.' · '.$scope.($p['min_purchase'] ? ' · min. belanja '.$rp($p['min_purchase']) : '');
    }

    /** Hari & jam berlaku, mis. "Sen–Jum · 13.00–14.30" atau "Setiap hari buka". */
    public static function schedule(array $p): string
    {
        $days = $p['days'];
        $label = match (true) {
            $days === [1, 2, 3, 4, 5, 6] => 'Setiap hari buka',
            $days === [1, 2, 3, 4, 5] => 'Sen–Jum',
            default => implode(', ', array_map(fn ($d) => self::DAY_LABELS[$d], $days)),
        };

        return $label.($p['time_from'] ? ' · '.str_replace(':', '.', $p['time_from']).'–'.str_replace(':', '.', $p['time_to']) : '');
    }

    /** Sisa hari promo/kupon berlaku (untuk status aktif), atau hari menuju mulai (terjadwal). */
    public static function daysLeft(array $row): ?string
    {
        return match ($row['status']) {
            'aktif', 'habis' => ($d = (int) today()->diffInDays(Carbon::parse($row['ends_at']))) === 0 ? 'Berakhir hari ini' : 'Sisa '.$d.' hari',
            'terjadwal' => 'Mulai '.(int) today()->diffInDays(Carbon::parse($row['starts_at'])).' hari lagi',
            default => null,
        };
    }

    public function render()
    {
        return view('livewire.promo.index');
    }
}
