<?php

namespace App\Support;

use App\Models\Notification;
use App\Services\AnnouncementService;
use App\Services\NotificationService;
use App\Services\SettingsService;

/**
 * Data contoh & pengaturan sementara. Barang/kategori kini ada di database
 * (lihat ProductSeeder); pengaturan koperasi diteruskan ke SettingsService (tabel settings).
 */
class MockData
{
    public static function categories(): array
    {
        return ['Alat Tulis', 'Makanan', 'Minuman', 'Seragam'];
    }

    /** Identitas koperasi, jam buka & tulisan penutup struk (tabel settings, lihat SettingsService). */
    public static function profile(): array
    {
        return app(SettingsService::class)->profile();
    }

    /** Pengaturan koperasi dari tabel settings: profile, receipt, tax, updated_at, updated_by. */
    public static function settings(): array
    {
        return app(SettingsService::class)->all();
    }

    /** Pengaturan struk bawaan. Judul/sub-judul kosong = ikut nama koperasi & madrasah. */
    public static function defaultReceipt(): array
    {
        return SettingsService::DEFAULTS['receipt'];
    }

    public static function tax(): array
    {
        return app(SettingsService::class)->tax();
    }

    public static function receipt(): array
    {
        return app(SettingsService::class)->receipt();
    }

    public static function saveSettings(string $section, array $values): array
    {
        return app(SettingsService::class)->save($section, $values);
    }

    /**
     * Kotak notifikasi petugas yang sedang masuk (tabel notifications, lihat NotificationService).
     *
     * @return list<array{id: int, type: string, title: string, message: string, target: string, is_read: bool, created_at: string, url: ?string}>
     */
    public static function notifications(): array
    {
        $user = auth()->user();

        return $user ? app(NotificationService::class)->list($user) : [];
    }

    /**
     * Pengumuman buatan petugas, terbaru dulu (lihat AnnouncementService).
     *
     * @return list<array{id: int, title: string, message: string, target: string, show_public: bool, expires_at: ?string, author: ?string, created_at: string}>
     */
    public static function announcements(): array
    {
        return app(AnnouncementService::class)->list();
    }

    public static function saveAnnouncement(array $data): array
    {
        return AnnouncementService::toArray(app(AnnouncementService::class)->create($data, auth()->user()));
    }

    public static function deleteAnnouncement(int $id): bool
    {
        $n = Notification::where('type', 'pengumuman')->find($id);
        if ($n) {
            app(AnnouncementService::class)->delete($n, auth()->user());
        }

        return (bool) $n;
    }

    /** Pengumuman aktif untuk beranda publik. */
    public static function publicAnnouncements(): array
    {
        return app(AnnouncementService::class)->publicList();
    }

    public static function markNotifications(array $ids, bool $read = true): void
    {
        if ($user = auth()->user()) {
            app(NotificationService::class)->mark($user, $ids, $read);
        }
    }

    public static function unreadNotifications(): int
    {
        $user = auth()->user();

        return $user ? app(NotificationService::class)->unreadCount($user) : 0;
    }

    /** Persentase pajak penjualan efektif (0 bila pajak dimatikan). */
    public static function taxRate(): float
    {
        return app(SettingsService::class)->taxRate();
    }

    /**
     * Transaksi tiruan (terbaru dulu) untuk halaman Riwayat Transaksi tahap frontend.
     * Deterministik: data sama setiap dimuat, tanggal relatif terhadap hari ini.
     */
    public static function transactions(int $days = 14): array
    {
        mt_srand(20261012);
        $products = self::seedProducts();
        $cashiers = ['Bu Rina', 'Pak Dedi', 'Kasir Demo'];
        $members = [null, null, null, 'Andi (7A)', 'Siti (8B)', 'Pak Budi (Guru)'];
        $rows = [];
        $id = 0;

        for ($d = $days - 1; $d >= 0; $d--) {
            $date = now()->startOfDay()->subDays($d);
            if ($date->isSunday()) {
                continue;
            }

            $count = mt_rand(2, 6);
            $times = [];
            for ($i = 0; $i < $count; $i++) {
                $times[] = $date->copy()->setTime(mt_rand(7, 14), mt_rand(0, 59), mt_rand(0, 59));
            }
            sort($times);

            foreach ($times as $seq => $time) {
                if ($time->isFuture()) {
                    continue;
                }

                $items = [];
                foreach ((array) array_rand($products, mt_rand(1, 4)) as $key) {
                    $p = $products[$key];
                    $qty = mt_rand(1, 3);
                    $items[] = ['product_name' => $p['name'], 'price' => $p['price'], 'quantity' => $qty, 'subtotal' => $p['price'] * $qty];
                }

                $total = array_sum(array_column($items, 'subtotal'));
                $method = mt_rand(1, 5) === 1 ? 'qris' : 'tunai';
                $paid = $method === 'tunai' ? (int) (ceil($total / 5000) * 5000) : $total;

                $rows[] = [
                    'id' => ++$id,
                    'invoice_number' => 'INV-'.$time->format('Ymd').'-'.str_pad((string) ($seq + 1), 4, '0', STR_PAD_LEFT),
                    'created_at' => $time->toDateTimeString(),
                    'cashier' => $cashiers[array_rand($cashiers)],
                    'member' => $members[array_rand($members)],
                    'items' => $items,
                    'item_count' => array_sum(array_column($items, 'quantity')),
                    'subtotal' => $total,
                    'tax' => 0,
                    'discount' => 0,
                    'total' => $total,
                    'paid' => $paid,
                    'change' => $paid - $total,
                    'payment_method' => $method,
                ];
            }
        }
        mt_srand();

        return array_reverse($rows);
    }

    public static function findTransaction(int $id): ?array
    {
        return collect(self::transactions())->firstWhere('id', $id);
    }

    /**
     * Rekap kas laci tiruan satu hari untuk halaman Kas Harian tahap frontend: shift kasir,
     * setoran/penarikan kas, penjualan per metode, dan selisih kas. Deterministik per tanggal;
     * Minggu libur (tanpa shift). Hari ini: shift terakhir masih dibuka (belum dihitung).
     * Kas seharusnya = modal awal + penjualan tunai + kas masuk − kas keluar.
     *
     * @return array{date: string, is_today: bool, closed_day: bool, shifts: list<array>, movements: list<array>, sales: array, summary: array}
     */
    public static function cashDay(string $date): array
    {
        $date = \Illuminate\Support\Carbon::parse($date)->toDateString();
        if ($stored = session("mock.cash.{$date}")) {
            return $stored + ['summary' => self::cashSummary($stored['shifts'], $stored['sales'])];
        }

        return self::seedCashDay($date);
    }

    /**
     * Buka shift tiruan hari ini (disimpan ke session). Penjualan shift baru mulai dari nol.
     *
     * @param  array{name: string, cashier: string, opening_cash: int}  $data
     */
    public static function openShift(array $data): array
    {
        $day = self::cashDay(today()->toDateString());
        $shift = [
            'id' => (collect($day['shifts'])->max('id') ?? 0) + 1, 'name' => $data['name'], 'cashier' => $data['cashier'], 'status' => 'open',
            'opened_at' => now()->toDateTimeString(), 'closed_at' => null, 'opening_cash' => $data['opening_cash'], 'transactions' => 0,
            'cash_sales' => 0, 'qris_sales' => 0, 'transfer_sales' => 0, 'cash_in' => 0, 'cash_out' => 0,
            'expected' => $data['opening_cash'], 'counted' => null, 'difference' => null, 'note' => null,
        ];
        $day['shifts'][] = $shift;
        self::storeCashDay($day);

        return $shift;
    }

    /** Tutup shift tiruan hari ini dengan hitungan uang akhir; selisih = dihitung − seharusnya. */
    public static function closeShift(int $id, int $counted, ?string $note): ?array
    {
        $day = self::cashDay(today()->toDateString());
        foreach ($day['shifts'] as &$shift) {
            if ($shift['id'] === $id && $shift['status'] === 'open') {
                $shift = [...$shift, 'status' => 'closed', 'closed_at' => now()->toDateTimeString(), 'counted' => $counted,
                    'difference' => $counted - $shift['expected'], 'note' => $note];
                self::storeCashDay($day);

                return $shift;
            }
        }

        return null;
    }

    /**
     * Catat setoran (in) / penarikan (out) kas laci tiruan pada shift yang sedang berjalan hari ini;
     * kas masuk/keluar & kas seharusnya shift ikut diperbarui. Null bila tidak ada shift berjalan.
     */
    public static function recordCashMovement(string $type, int $amount, string $reason, string $cashier): ?array
    {
        $day = self::cashDay(today()->toDateString());
        foreach ($day['shifts'] as &$shift) {
            if ($shift['status'] !== 'open') {
                continue;
            }
            $shift[$type === 'in' ? 'cash_in' : 'cash_out'] += $amount;
            $shift['expected'] += $type === 'in' ? $amount : -$amount;
            $movement = [
                'id' => (collect($day['movements'])->max('id') ?? (int) today()->format('ymd') * 10) + 1,
                'shift_id' => $shift['id'], 'type' => $type, 'amount' => $amount, 'reason' => $reason,
                'cashier' => $cashier, 'created_at' => now()->toDateTimeString(),
            ];
            array_unshift($day['movements'], $movement);
            self::storeCashDay($day);

            return $movement;
        }

        return null;
    }

    private static function storeCashDay(array $day): void
    {
        unset($day['summary']);
        session(["mock.cash.{$day['date']}" => $day]);
    }

    private static function seedCashDay(string $date): array
    {
        $day = \Illuminate\Support\Carbon::parse($date)->startOfDay();
        $isToday = $day->isToday();
        $empty = ['tunai' => 0, 'qris' => 0, 'transfer' => 0, 'count' => 0];
        $result = ['date' => $day->toDateString(), 'is_today' => $isToday, 'closed_day' => $day->isSunday(), 'shifts' => [], 'movements' => [], 'sales' => $empty];

        if ($day->isSunday() || $day->isFuture()) {
            return $result + ['summary' => self::cashSummary([], $empty)];
        }

        mt_srand((int) $day->format('Ymd'));
        $plans = [
            ['name' => 'Shift pagi', 'open' => [6, 45], 'close' => [10, 30]],
            ['name' => 'Shift siang', 'open' => [10, 30], 'close' => [14, 15]],
        ];
        $cashiers = ['Bu Rina', 'Pak Dedi', 'Kasir Demo'];
        $reasonsIn = ['Tambah uang kembalian', 'Tukar pecahan dari bendahara', 'Setoran iuran kegiatan'];
        $reasonsOut = ['Beli plastik & kantong', 'Setor ke bendahara', 'Bayar galon air', 'Ongkos angkut barang'];
        $differences = [0, 0, 0, -500, -2000, 1000, 0, -1000];
        $movementId = (int) $day->format('ymd') * 10;

        foreach ($plans as $i => $plan) {
            $opened = $day->copy()->setTime(...$plan['open'])->addMinutes(mt_rand(0, 10));
            if ($opened->isFuture()) {
                break;
            }
            $closed = $day->copy()->setTime(...$plan['close'])->addMinutes(mt_rand(0, 15));
            $isOpen = $closed->isFuture();
            $cashier = $cashiers[($i + (int) $day->format('j')) % count($cashiers)];
            $count = mt_rand(18, 45);
            $tunai = mt_rand(30, 90) * 5000;
            $qris = mt_rand(0, 12) * 5000;
            $transfer = mt_rand(0, 3) === 0 ? mt_rand(1, 4) * 10000 : 0;
            if ($isOpen) {
                // Shift berjalan: penjualan sebanding dengan waktu yang sudah lewat.
                $share = max(0.1, $opened->diffInMinutes(now()) / max(1, $opened->diffInMinutes($closed)));
                [$count, $tunai, $qris, $transfer] = [(int) ceil($count * $share), (int) round($tunai * $share / 500) * 500, (int) round($qris * $share / 500) * 500, 0];
            }

            $in = $out = 0;
            for ($n = mt_rand(0, 2); $n > 0; $n--) {
                $type = mt_rand(0, 2) === 0 ? 'in' : 'out';
                $amount = $type === 'in' ? mt_rand(1, 4) * 25000 : mt_rand(1, 8) * 5000;
                $at = $opened->copy()->addMinutes(mt_rand(15, 200));
                if ($at->isFuture() || (! $isOpen && $at->gt($closed))) {
                    continue;
                }
                $type === 'in' ? $in += $amount : $out += $amount;
                $result['movements'][] = [
                    'id' => ++$movementId, 'shift_id' => $i + 1, 'type' => $type, 'amount' => $amount,
                    'reason' => $type === 'in' ? $reasonsIn[array_rand($reasonsIn)] : $reasonsOut[array_rand($reasonsOut)],
                    'cashier' => $cashier, 'created_at' => $at->toDateTimeString(),
                ];
            }

            $opening = $i === 0 ? 200000 : 150000;
            $expected = $opening + $tunai + $in - $out;
            $counted = $isOpen ? null : $expected + $differences[array_rand($differences)];

            $result['shifts'][] = [
                'id' => $i + 1, 'name' => $plan['name'], 'cashier' => $cashier, 'status' => $isOpen ? 'open' : 'closed',
                'opened_at' => $opened->toDateTimeString(), 'closed_at' => $isOpen ? null : $closed->toDateTimeString(),
                'opening_cash' => $opening, 'transactions' => $count,
                'cash_sales' => $tunai, 'qris_sales' => $qris, 'transfer_sales' => $transfer,
                'cash_in' => $in, 'cash_out' => $out, 'expected' => $expected,
                'counted' => $counted, 'difference' => $counted === null ? null : $counted - $expected,
                'note' => $counted !== null && $counted < $expected ? 'Kemungkinan salah kembalian.' : null,
            ];
            $result['sales']['tunai'] += $tunai;
            $result['sales']['qris'] += $qris;
            $result['sales']['transfer'] += $transfer;
            $result['sales']['count'] += $count;
        }
        mt_srand();

        usort($result['movements'], fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $result + ['summary' => self::cashSummary($result['shifts'], $result['sales'])];
    }

    /** Ringkasan kas sehari dari daftar shift (lihat cashDay). */
    private static function cashSummary(array $shifts, array $sales): array
    {
        $sum = fn (string $key) => array_sum(array_column($shifts, $key));
        $closed = array_filter($shifts, fn ($s) => $s['status'] === 'closed');

        return [
            'opening_cash' => $sum('opening_cash'),
            'cash_sales' => $sum('cash_sales'),
            'cash_in' => $sum('cash_in'),
            'cash_out' => $sum('cash_out'),
            'expected' => $sum('expected'),
            'counted' => array_sum(array_column($closed, 'counted')),
            'difference' => array_sum(array_column($closed, 'difference')),
            'non_cash' => $sales['qris'] + $sales['transfer'],
            'revenue' => $sales['tunai'] + $sales['qris'] + $sales['transfer'],
            'open_shifts' => count($shifts) - count($closed),
            'closed_shifts' => count($closed),
        ];
    }

    /**
     * Rekap kas beberapa hari terakhir sampai $date (lama → baru) untuk tren selisih.
     *
     * @return list<array{date: string, closed_day: bool, revenue: int, cash_sales: int, difference: int, shifts: int, open_shifts: int}>
     */
    public static function cashWeek(string $date, int $days = 7): array
    {
        $end = \Illuminate\Support\Carbon::parse($date);

        return collect(range($days - 1, 0))->map(function ($ago) use ($end) {
            $d = self::cashDay($end->copy()->subDays($ago)->toDateString());

            return [
                'date' => $d['date'], 'closed_day' => $d['closed_day'], 'revenue' => $d['summary']['revenue'],
                'cash_sales' => $d['summary']['cash_sales'], 'difference' => $d['summary']['difference'],
                'shifts' => count($d['shifts']), 'open_shifts' => $d['summary']['open_shifts'],
            ];
        })->all();
    }

    /**
     * Rekap kas tiruan per hari untuk rentang tanggal (lama → baru), tanpa hari di masa depan.
     *
     * @return list<array{date: string, closed_day: bool, shifts: int, open_shifts: int, transactions: int, opening_cash: int, cash_sales: int, non_cash: int, revenue: int, cash_in: int, cash_out: int, expected: int, counted: int, difference: int, cashiers: list<string>}>
     */
    public static function cashRecap(string $from, string $to): array
    {
        $rows = [];
        $end = min(\Illuminate\Support\Carbon::parse($to)->startOfDay(), today());
        for ($d = \Illuminate\Support\Carbon::parse($from)->startOfDay(); $d->lte($end); $d->addDay()) {
            $day = self::cashDay($d->toDateString());
            $s = $day['summary'];
            $rows[] = [
                'date' => $day['date'], 'closed_day' => $day['closed_day'], 'shifts' => count($day['shifts']),
                'open_shifts' => $s['open_shifts'], 'transactions' => $day['sales']['count'],
                ...collect($s)->only(['opening_cash', 'cash_sales', 'non_cash', 'revenue', 'cash_in', 'cash_out', 'expected', 'counted', 'difference'])->all(),
                'cashiers' => array_values(array_unique(array_column($day['shifts'], 'cashier'))),
            ];
        }

        return $rows;
    }

    /**
     * Semua shift tiruan dalam rentang tanggal (terbaru dulu), masing-masing dengan tanggal & jumlah
     * catatan setoran/penarikan kasnya — untuk halaman Riwayat & Selisih Shift.
     *
     * @return list<array>
     */
    public static function shiftHistory(string $from, string $to): array
    {
        $rows = [];
        $end = min(\Illuminate\Support\Carbon::parse($to)->startOfDay(), today());
        for ($d = \Illuminate\Support\Carbon::parse($from)->startOfDay(); $d->lte($end); $d->addDay()) {
            $day = self::cashDay($d->toDateString());
            $moves = collect($day['movements'])->countBy('shift_id');
            foreach ($day['shifts'] as $shift) {
                $rows[] = ['date' => $day['date'], 'key' => $day['date'].'-'.$shift['id'], 'movements' => $moves[$shift['id']] ?? 0, ...$shift];
            }
        }

        return array_reverse($rows);
    }

    /** Jenis promo otomatis. */
    public const PROMO_TYPES = [
        'persen' => 'Potongan persen',
        'nominal' => 'Potongan rupiah',
        'beli_gratis' => 'Beli X gratis Y',
        'grosir' => 'Harga grosir',
    ];

    /**
     * Promo otomatis tiruan (session `mock.promos` bila sudah diubah lewat form). Status dihitung
     * dari tanggal & saklar aktif: aktif | terjadwal | berakhir | nonaktif.
     *
     * @return list<array{id: int, name: string, type: string, value: int, buy_qty: ?int, free_qty: ?int, min_qty: int, min_purchase: int, scope: string, targets: list<string>, starts_at: string, ends_at: string, days: list<int>, time_from: ?string, time_to: ?string, is_active: bool, usage_count: int, discount_given: int, created_by: string, status: string}>
     */
    public static function promos(): array
    {
        $today = today();
        $rows = session('mock.promos') ?? [
            ['id' => 1, 'name' => 'Diskon Alat Tulis Awal Semester', 'type' => 'persen', 'value' => 10, 'buy_qty' => null, 'free_qty' => null, 'min_qty' => 1, 'min_purchase' => 0,
                'scope' => 'kategori', 'targets' => ['Alat Tulis'], 'starts_at' => $today->copy()->subDays(10)->toDateString(), 'ends_at' => $today->copy()->addDays(20)->toDateString(),
                'days' => [1, 2, 3, 4, 5, 6], 'time_from' => null, 'time_to' => null, 'is_active' => true, 'usage_count' => 142, 'discount_given' => 61500, 'created_by' => 'Admin Koperasi'],
            ['id' => 2, 'name' => 'Beli 2 Gratis 1 Biskuit Kelapa', 'type' => 'beli_gratis', 'value' => 0, 'buy_qty' => 2, 'free_qty' => 1, 'min_qty' => 3, 'min_purchase' => 0,
                'scope' => 'barang', 'targets' => ['Biskuit Kelapa'], 'starts_at' => $today->copy()->subDays(3)->toDateString(), 'ends_at' => $today->copy()->addDays(4)->toDateString(),
                'days' => [1, 2, 3, 4, 5, 6], 'time_from' => null, 'time_to' => null, 'is_active' => true, 'usage_count' => 37, 'discount_given' => 74000, 'created_by' => 'Pak Budi'],
            ['id' => 3, 'name' => 'Happy Hour Minuman Pulang Sekolah', 'type' => 'nominal', 'value' => 500, 'buy_qty' => null, 'free_qty' => null, 'min_qty' => 1, 'min_purchase' => 0,
                'scope' => 'kategori', 'targets' => ['Minuman'], 'starts_at' => $today->copy()->subDays(30)->toDateString(), 'ends_at' => $today->copy()->addDays(60)->toDateString(),
                'days' => [1, 2, 3, 4, 5], 'time_from' => '13:00', 'time_to' => '14:30', 'is_active' => true, 'usage_count' => 389, 'discount_given' => 194500, 'created_by' => 'Admin Koperasi'],
            ['id' => 4, 'name' => 'Grosir Buku Tulis (isi 10)', 'type' => 'grosir', 'value' => 3500, 'buy_qty' => null, 'free_qty' => null, 'min_qty' => 10, 'min_purchase' => 0,
                'scope' => 'barang', 'targets' => ['Buku Tulis 38 Lembar'], 'starts_at' => $today->copy()->subDays(60)->toDateString(), 'ends_at' => $today->copy()->addDays(120)->toDateString(),
                'days' => [1, 2, 3, 4, 5, 6], 'time_from' => null, 'time_to' => null, 'is_active' => true, 'usage_count' => 18, 'discount_given' => 90000, 'created_by' => 'Pak Budi'],
            ['id' => 5, 'name' => 'Promo Seragam Tahun Ajaran Baru', 'type' => 'persen', 'value' => 15, 'buy_qty' => null, 'free_qty' => null, 'min_qty' => 1, 'min_purchase' => 50000,
                'scope' => 'kategori', 'targets' => ['Seragam'], 'starts_at' => $today->copy()->addDays(5)->toDateString(), 'ends_at' => $today->copy()->addDays(35)->toDateString(),
                'days' => [1, 2, 3, 4, 5, 6], 'time_from' => null, 'time_to' => null, 'is_active' => true, 'usage_count' => 0, 'discount_given' => 0, 'created_by' => 'Admin Koperasi'],
            ['id' => 6, 'name' => 'Diskon Hari Guru', 'type' => 'persen', 'value' => 20, 'buy_qty' => null, 'free_qty' => null, 'min_qty' => 1, 'min_purchase' => 10000,
                'scope' => 'semua', 'targets' => [], 'starts_at' => $today->copy()->subDays(40)->toDateString(), 'ends_at' => $today->copy()->subDays(38)->toDateString(),
                'days' => [1, 2, 3, 4, 5, 6], 'time_from' => null, 'time_to' => null, 'is_active' => true, 'usage_count' => 96, 'discount_given' => 158000, 'created_by' => 'Admin Koperasi'],
            ['id' => 7, 'name' => 'Potongan Roti Coklat Sore', 'type' => 'nominal', 'value' => 1000, 'buy_qty' => null, 'free_qty' => null, 'min_qty' => 1, 'min_purchase' => 0,
                'scope' => 'barang', 'targets' => ['Roti Coklat'], 'starts_at' => $today->copy()->subDays(7)->toDateString(), 'ends_at' => $today->copy()->addDays(7)->toDateString(),
                'days' => [1, 2, 3, 4, 5], 'time_from' => '12:00', 'time_to' => '14:30', 'is_active' => false, 'usage_count' => 12, 'discount_given' => 12000, 'created_by' => 'Pak Budi'],
        ];

        return array_map(fn (array $p) => ['status' => self::periodStatus($p['starts_at'], $p['ends_at'], $p['is_active'])] + $p, $rows);
    }

    /**
     * Kupon & voucher tiruan (session `mock.coupons` bila sudah diubah). Status: aktif | terjadwal |
     * berakhir | habis (kuota terpakai) | nonaktif.
     *
     * @return list<array{id: int, code: string, name: string, type: string, value: int, max_discount: ?int, min_purchase: int, quota: ?int, used: int, per_member: ?int, starts_at: string, ends_at: string, is_active: bool, status: string}>
     */
    public static function coupons(): array
    {
        $today = today();
        $rows = session('mock.coupons') ?? [
            ['id' => 1, 'code' => 'MPLS2026', 'name' => 'Voucher siswa baru (MPLS)', 'type' => 'nominal', 'value' => 5000, 'max_discount' => null, 'min_purchase' => 20000, 'quota' => 200, 'used' => 154, 'per_member' => 1,
                'starts_at' => $today->copy()->subDays(14)->toDateString(), 'ends_at' => $today->copy()->addDays(16)->toDateString(), 'is_active' => true],
            ['id' => 2, 'code' => 'GURUHEBAT', 'name' => 'Potongan khusus guru', 'type' => 'persen', 'value' => 10, 'max_discount' => 10000, 'min_purchase' => 0, 'quota' => null, 'used' => 61, 'per_member' => null,
                'starts_at' => $today->copy()->subDays(45)->toDateString(), 'ends_at' => $today->copy()->addDays(75)->toDateString(), 'is_active' => true],
            ['id' => 3, 'code' => 'PRAMUKA25', 'name' => 'Voucher lomba pramuka', 'type' => 'nominal', 'value' => 3000, 'max_discount' => null, 'min_purchase' => 10000, 'quota' => 50, 'used' => 50, 'per_member' => 1,
                'starts_at' => $today->copy()->subDays(20)->toDateString(), 'ends_at' => $today->copy()->addDays(10)->toDateString(), 'is_active' => true],
            ['id' => 4, 'code' => 'HARIBATIK', 'name' => 'Diskon Hari Batik', 'type' => 'persen', 'value' => 15, 'max_discount' => 15000, 'min_purchase' => 25000, 'quota' => 100, 'used' => 38, 'per_member' => 1,
                'starts_at' => $today->copy()->subDays(14)->toDateString(), 'ends_at' => $today->copy()->subDays(11)->toDateString(), 'is_active' => true],
            ['id' => 5, 'code' => 'OSIS100', 'name' => 'Hadiah pengurus OSIS', 'type' => 'nominal', 'value' => 10000, 'max_discount' => null, 'min_purchase' => 30000, 'quota' => 30, 'used' => 0, 'per_member' => 1,
                'starts_at' => $today->copy()->addDays(3)->toDateString(), 'ends_at' => $today->copy()->addDays(33)->toDateString(), 'is_active' => true],
        ];

        return array_map(function (array $c) {
            $status = self::periodStatus($c['starts_at'], $c['ends_at'], $c['is_active']);

            return ['status' => $status === 'aktif' && $c['quota'] !== null && $c['used'] >= $c['quota'] ? 'habis' : $status] + $c;
        }, $rows);
    }

    /** Status berlaku dari rentang tanggal (inklusif) & saklar aktif. */
    private static function periodStatus(string $start, string $end, bool $active): string
    {
        return match (true) {
            ! $active => 'nonaktif',
            today()->lt(\Illuminate\Support\Carbon::parse($start)) => 'terjadwal',
            today()->gt(\Illuminate\Support\Carbon::parse($end)) => 'berakhir',
            default => 'aktif',
        };
    }

    /** Aturan diskon manual kasir tiruan: batas potongan tanpa persetujuan admin. */
    public static function manualDiscountRules(): array
    {
        return session('mock.manual_discount') ?? ['enabled' => true, 'max_percent' => 10, 'max_amount' => 20000, 'reason_required' => true, 'used_7_days' => 23, 'given_7_days' => 46500];
    }

    /** Anggota koperasi tiruan (siswa & guru) dengan saldo tabungan, untuk tahap frontend. */
    public static function members(): array
    {
        return session('mock.members') ?? self::seedMembers();
    }

    public static function findMember(int $id): ?array
    {
        return collect(self::members())->firstWhere('id', $id);
    }

    /** Simpan anggota baru (tanpa id) atau perbarui yang ada ke session. */
    public static function saveMember(array $data): array
    {
        $members = collect(self::members())->keyBy('id');
        $data['id'] ??= ($members->keys()->max() ?? 0) + 1;
        $members[$data['id']] = [
            'balance' => 0,
            'joined_at' => now()->toDateString(),
            ...($members[$data['id']] ?? []),
            ...$data,
        ];
        session(['mock.members' => $members->values()->all()]);

        return $members[$data['id']];
    }

    /**
     * Mutasi tabungan tiruan satu anggota (lama → baru). Deterministik per anggota,
     * saldo tidak pernah negatif, dan saldo akhir sama dengan saldo anggota.
     */
    public static function savings(int $memberId): array
    {
        return session("mock.savings.{$memberId}") ?? self::seedSavings($memberId);
    }

    /** Catat setor/tarik tiruan: tambah mutasi & perbarui saldo anggota. */
    public static function recordSaving(int $memberId, string $type, int $amount, ?string $note, string $user = 'Kasir Demo'): array
    {
        $member = self::findMember($memberId);
        $ledger = self::savings($memberId);
        $balance = $member['balance'] + ($type === 'setor' ? $amount : -$amount);

        $entry = [
            'id' => $memberId * 100 + count($ledger) + 1,
            'member_id' => $memberId,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $balance,
            'note' => $note ?: ($type === 'setor' ? 'Setoran tabungan' : 'Penarikan tabungan'),
            'user' => $user,
            'created_at' => now()->toDateTimeString(),
        ];

        session(["mock.savings.{$memberId}" => [...$ledger, $entry]]);
        self::saveMember(['id' => $memberId, 'balance' => $balance]);

        return $entry;
    }

    public static function seedSavings(int $memberId): array
    {
        $member = self::findMember($memberId);
        if (! $member || $member['balance'] <= 0 && $memberId % 2 === 1) {
            return [];
        }

        mt_srand(1000 + $memberId);
        $target = $member['balance'];
        $staff = ['Bu Rina', 'Pak Dedi'];
        $rows = [];
        $balance = 0;
        $n = mt_rand(3, 8);
        $day = 120;

        for ($i = 0; $i < $n; $i++) {
            $day -= mt_rand(5, 15);
            $withdraw = $balance > 10000 && mt_rand(1, 3) === 1;
            $amount = $withdraw
                ? mt_rand(1, (int) floor($balance / 1000 * 0.6)) * 1000
                : mt_rand(1, $target > 300000 ? 60 : 12) * 5000;
            $balance += $withdraw ? -$amount : $amount;
            $rows[] = [$day, $withdraw ? 'tarik' : 'setor', $amount, $balance];
        }

        // Mutasi penutup agar saldo akhir = saldo anggota.
        if ($balance !== $target) {
            $diff = $target - $balance;
            $balance = $target;
            $rows[] = [max(1, $day - mt_rand(1, 4)), $diff > 0 ? 'setor' : 'tarik', abs($diff), $balance];
        }
        mt_srand();

        return array_map(fn ($r, $i) => [
            'id' => $memberId * 100 + $i + 1,
            'member_id' => $memberId,
            'type' => $r[1],
            'amount' => $r[2],
            'balance_after' => $r[3],
            'note' => $r[1] === 'setor' ? 'Setoran tabungan' : 'Penarikan tabungan',
            'user' => $staff[$i % 2],
            'created_at' => now()->subDays($r[0])->setTime(9 + $i % 5, (13 * $i) % 60)->toDateTimeString(),
        ], $rows, array_keys($rows));
    }

    /** Kode anggota berikutnya, mis. S-2026-013 / G-2026-005. */
    public static function nextMemberCode(string $type): string
    {
        $prefix = ($type === 'guru' ? 'G' : 'S').'-'.now()->year.'-';
        $count = collect(self::members())->filter(fn ($m) => str_starts_with($m['member_code'], $type === 'guru' ? 'G-' : 'S-'))->count();

        do {
            $code = $prefix.str_pad((string) ++$count, 3, '0', STR_PAD_LEFT);
        } while (collect(self::members())->contains('member_code', $code));

        return $code;
    }

    public static function seedMembers(): array
    {
        $rows = [
            ['S-2024-001', 'Andi Pratama', 'siswa', '7A', '0812-1111-2001', 85000, true],
            ['S-2024-002', 'Siti Nurhaliza', 'siswa', '8B', '0812-1111-2002', 142500, true],
            ['S-2024-003', 'Budi Santoso', 'siswa', '9C', null, 0, true],
            ['S-2024-004', 'Dewi Lestari', 'siswa', '7A', '0812-1111-2004', 37000, true],
            ['S-2024-005', 'Rizky Ramadhan', 'siswa', '8A', '0812-1111-2005', 210000, true],
            ['S-2024-006', 'Putri Ayu', 'siswa', '9A', null, 55000, true],
            ['S-2024-007', 'Fajar Nugroho', 'siswa', '7B', '0812-1111-2007', 12000, true],
            ['S-2024-008', 'Nabila Zahra', 'siswa', '8B', '0812-1111-2008', 98000, true],
            ['S-2024-009', 'Galih Saputra', 'siswa', '9B', null, 0, false],
            ['S-2024-010', 'Intan Permata', 'siswa', '7C', '0812-1111-2010', 64500, true],
            ['S-2024-011', 'Yoga Prasetyo', 'siswa', '8C', '0812-1111-2011', 175000, true],
            ['S-2024-012', 'Maya Sari', 'siswa', '9C', '0812-1111-2012', 23000, true],
            ['G-2024-001', 'Pak Budi Hartono', 'guru', null, '0813-2222-3001', 750000, true],
            ['G-2024-002', 'Bu Rina Wulandari', 'guru', null, '0813-2222-3002', 1250000, true],
            ['G-2024-003', 'Pak Dedi Kurniawan', 'guru', null, '0813-2222-3003', 420000, true],
            ['G-2024-004', 'Bu Sri Mulyani', 'guru', null, null, 0, false],
        ];

        return array_map(fn ($r, $i) => [
            'id' => $i + 1,
            'member_code' => $r[0],
            'name' => $r[1],
            'type' => $r[2],
            'class' => $r[3],
            'phone' => $r[4],
            'balance' => $r[5],
            'is_active' => $r[6],
            'joined_at' => now()->subDays(200 - $i * 9)->toDateString(),
        ], $rows, array_keys($rows));
    }

    /** Data contoh awal — juga dipakai ProductSeeder. */
    public static function seedProducts(): array
    {
        return [
            ['id' => 1, 'code' => 'ATK001', 'name' => 'Buku Tulis 38 Lembar', 'category' => 'Alat Tulis', 'price' => 4000, 'cost_price' => 3200, 'stock' => 120, 'min_stock' => 20, 'unit' => 'pcs'],
            ['id' => 2, 'code' => 'ATK002', 'name' => 'Pulpen Hitam', 'category' => 'Alat Tulis', 'price' => 3000, 'cost_price' => 2400, 'stock' => 85, 'min_stock' => 20, 'unit' => 'pcs'],
            ['id' => 3, 'code' => 'ATK003', 'name' => 'Pensil 2B', 'category' => 'Alat Tulis', 'price' => 2500, 'cost_price' => 2000, 'stock' => 12, 'min_stock' => 15, 'unit' => 'pcs'],
            ['id' => 4, 'code' => 'ATK004', 'name' => 'Penghapus', 'category' => 'Alat Tulis', 'price' => 1500, 'cost_price' => 1200, 'stock' => 60, 'min_stock' => 10, 'unit' => 'pcs'],
            ['id' => 5, 'code' => 'ATK005', 'name' => 'Penggaris 30 cm', 'category' => 'Alat Tulis', 'price' => 3500, 'cost_price' => 2800, 'stock' => 0, 'min_stock' => 5, 'unit' => 'pcs'],
            ['id' => 6, 'code' => 'MKN001', 'name' => 'Roti Coklat', 'category' => 'Makanan', 'price' => 5000, 'cost_price' => 4000, 'stock' => 24, 'min_stock' => 10, 'unit' => 'pcs'],
            ['id' => 7, 'code' => 'MKN002', 'name' => 'Biskuit Kelapa', 'category' => 'Makanan', 'price' => 2000, 'cost_price' => 1600, 'stock' => 48, 'min_stock' => 10, 'unit' => 'bks'],
            ['id' => 8, 'code' => 'MKN003', 'name' => 'Keripik Singkong', 'category' => 'Makanan', 'price' => 3000, 'cost_price' => 2400, 'stock' => 6, 'min_stock' => 10, 'unit' => 'bks'],
            ['id' => 9, 'code' => 'MNM001', 'name' => 'Air Mineral 600 ml', 'category' => 'Minuman', 'price' => 3500, 'cost_price' => 2800, 'stock' => 72, 'min_stock' => 24, 'unit' => 'btl'],
            ['id' => 10, 'code' => 'MNM002', 'name' => 'Teh Kotak', 'category' => 'Minuman', 'price' => 4500, 'cost_price' => 3600, 'stock' => 30, 'min_stock' => 12, 'unit' => 'kotak'],
            ['id' => 11, 'code' => 'MNM003', 'name' => 'Susu UHT Coklat', 'category' => 'Minuman', 'price' => 6000, 'cost_price' => 4800, 'stock' => 18, 'min_stock' => 12, 'unit' => 'kotak'],
            ['id' => 12, 'code' => 'SRG001', 'name' => 'Dasi Sekolah', 'category' => 'Seragam', 'price' => 15000, 'cost_price' => 12000, 'stock' => 25, 'min_stock' => 5, 'unit' => 'pcs'],
            ['id' => 13, 'code' => 'SRG002', 'name' => 'Topi Sekolah', 'category' => 'Seragam', 'price' => 20000, 'cost_price' => 16000, 'stock' => 4, 'min_stock' => 5, 'unit' => 'pcs'],
            ['id' => 14, 'code' => 'SRG003', 'name' => 'Ikat Pinggang', 'category' => 'Seragam', 'price' => 18000, 'cost_price' => 14400, 'stock' => 14, 'min_stock' => 5, 'unit' => 'pcs'],
        ];
    }
}
