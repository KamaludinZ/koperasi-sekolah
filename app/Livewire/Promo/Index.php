<?php

namespace App\Livewire\Promo;

use App\Models\Coupon;
use App\Models\Promo;
use App\Services\PromoManager;
use App\Services\PromoService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Diskon & Promo: ringkasan promo otomatis (per barang/kategori/semua, beli X gratis Y,
 * harga grosir, jam tertentu), kupon & voucher dengan kuota, serta batas diskon manual kasir.
 * Kasir melihat promo yang berlaku; admin & pengurus mengelolanya.
 * Data dari database lewat PromoService (status, pemakaian & potongan sungguhan).
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
        return Gate::allows('kelola-promo');
    }

    /** Nyalakan/matikan promo dari daftar (tercatat di audit). */
    public function togglePromo(int $id): void
    {
        Gate::authorize('kelola-promo');
        $promo = Promo::findOrFail($id);
        app(PromoManager::class)->toggle($promo, ! $promo->is_active);
        unset($this->allPromos, $this->promos, $this->counts, $this->stats);
        $this->dispatch('toast', type: 'success', message: "Promo {$promo->name} ".($promo->is_active ? 'diaktifkan.' : 'dinonaktifkan.'));
    }

    /** Nyalakan/matikan kupon dari daftar (tercatat di audit). */
    public function toggleCoupon(int $id): void
    {
        Gate::authorize('kelola-promo');
        $coupon = Coupon::findOrFail($id);
        app(PromoManager::class)->toggle($coupon, ! $coupon->is_active);
        unset($this->coupons, $this->stats);
        $this->dispatch('toast', type: 'success', message: "Kupon {$coupon->code} ".($coupon->is_active ? 'diaktifkan.' : 'dinonaktifkan.'));
    }

    /** Semua promo dari database (PromoService), sekali per permintaan. */
    #[Computed]
    public function allPromos(): array
    {
        return app(PromoService::class)->all();
    }

    #[Computed]
    public function promos(): array
    {
        $q = mb_strtolower(trim($this->search));

        return array_values(array_filter($this->allPromos, fn (array $p) => ($this->status === 'semua' || $p['status'] === $this->status)
            && ($q === '' || str_contains(mb_strtolower($p['name'].' '.implode(' ', $p['targets'])), $q))));
    }

    /** Jumlah promo per status, untuk label saringan. */
    #[Computed]
    public function counts(): array
    {
        $counts = collect($this->allPromos)->countBy('status');

        return collect(self::STATUSES)->keys()->mapWithKeys(fn ($s) => [$s => $s === 'semua' ? $counts->sum() : ($counts[$s] ?? 0)])->all();
    }

    #[Computed]
    public function coupons(): array
    {
        return app(PromoService::class)->coupons();
    }

    #[Computed]
    public function stats(): array
    {
        return app(PromoService::class)->stats($this->allPromos, $this->coupons);
    }

    #[Computed]
    public function manual(): array
    {
        return app(PromoService::class)->manualRules();
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
