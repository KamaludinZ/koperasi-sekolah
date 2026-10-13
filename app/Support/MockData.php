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

    /** Batas bon bawaan per jenis anggota (tiruan; backend menyusul dari pengaturan). */
    public const CREDIT_LIMITS = ['siswa' => 50_000, 'guru' => 300_000];

    /**
     * Status bon (piutang) anggota tiruan. Kontrak backend: GET /anggota/{id}/bon →
     * {limit, outstanding, available, open_bills, oldest_due_at, overdue}.
     *
     * @return array{limit: int, outstanding: int, available: int, open_bills: int, oldest_due_at: ?string, overdue: bool}
     */
    public static function memberCredit(int $memberId, string $type = 'siswa'): array
    {
        $limit = self::CREDIT_LIMITS[$type] ?? self::CREDIT_LIMITS['siswa'];
        // Anggota ber-id genap punya bon contoh yang belum lunas; kelipatan 4 sudah lewat jatuh tempo.
        $outstanding = $memberId % 2 === 0 ? min($limit, 12_000 + ($memberId % 5) * 4_000) : 0;
        $overdue = $outstanding > 0 && $memberId % 4 === 0;

        return [
            'limit' => $limit,
            'outstanding' => $outstanding,
            'available' => max(0, $limit - $outstanding),
            'open_bills' => $outstanding > 0 ? 1 + ($memberId % 3 === 0 ? 1 : 0) : 0,
            'oldest_due_at' => $outstanding > 0 ? now()->addDays($overdue ? -3 : 9)->toDateString() : null,
            'overdue' => $overdue,
        ];
    }

    /**
     * Bon belum lunas milik anggota (tiruan), jatuh tempo terlama dulu. Jumlahnya cocok dengan
     * memberCredit(). Kontrak backend: GET /anggota/{id}/bon → bills[].
     *
     * @return list<array{id: string, number: string, invoice: string, created_at: string, due_at: string, amount: int, remaining: int, overdue: bool}>
     */
    public static function memberBills(int $memberId, string $type = 'siswa'): array
    {
        $credit = self::memberCredit($memberId, $type);
        if ($credit['outstanding'] === 0) {
            return [];
        }

        $first = $credit['open_bills'] > 1 ? (int) (round($credit['outstanding'] * 0.4 / 1000) * 1000) : $credit['outstanding'];
        $amounts = array_values(array_filter([$first, $credit['outstanding'] - $first]));
        $bills = [];
        foreach ($amounts as $i => $amount) {
            $due = \Illuminate\Support\Carbon::parse($credit['oldest_due_at'])->addDays($i * 7);
            $bills[] = [
                'id' => "B{$memberId}-".($i + 1),
                'number' => 'BON-'.$due->copy()->subDays(14)->format('Ymd').'-'.str_pad((string) ($memberId * 10 + $i + 1), 4, '0', STR_PAD_LEFT),
                'invoice' => 'INV-'.$due->copy()->subDays(14)->format('Ymd').'-'.str_pad((string) ($memberId * 7 + $i), 4, '0', STR_PAD_LEFT),
                'created_at' => $due->copy()->subDays(14)->toDateString(),
                'due_at' => $due->toDateString(),
                'amount' => $amount,
                'remaining' => $amount,
                'overdue' => $due->isPast() && ! $due->isToday(),
            ];
        }

        return $bills;
    }
}
