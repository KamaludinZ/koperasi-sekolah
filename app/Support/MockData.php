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

    /**
     * Ringkasan kategori tiruan untuk halaman Kategori & Impor. Kontrak yang diasumsikan untuk
     * backend: GET /barang/kategori/data → {id, name, color, position, products, active_products,
     * low_stock, stock_value, updated_at}, urut `position`.
     *
     * @return list<array{id: int, name: string, color: string, position: int, products: int, active_products: int, low_stock: int, stock_value: int, updated_at: string}>
     */
    public static function categoryOverview(): array
    {
        return session('mock.categories') ?? self::seedCategories();
    }

    /** Simpan kategori baru (tanpa id) atau ubah nama/warna yang ada (session). */
    public static function saveCategory(array $data): array
    {
        $rows = collect(self::categoryOverview())->keyBy('id');
        if (isset($data['id']) && $rows->has($data['id'])) {
            $rows[$data['id']] = [...$rows[$data['id']], 'name' => $data['name'], 'color' => $data['color'], 'updated_at' => now()->toDateTimeString()];
        } else {
            $id = ($rows->keys()->max() ?? 0) + 1;
            $rows[$id] = ['id' => $id, 'name' => $data['name'], 'color' => $data['color'], 'position' => $rows->count() + 1,
                'products' => 0, 'active_products' => 0, 'low_stock' => 0, 'stock_value' => 0, 'updated_at' => now()->toDateTimeString()];
        }
        session(['mock.categories' => $rows->sortBy('position')->values()->all()]);

        return $rows[$data['id'] ?? $id];
    }

    /** Hapus kategori; barangnya dipindah ke kategori $moveTo (session). */
    public static function deleteCategory(int $id, ?int $moveTo = null): void
    {
        $rows = collect(self::categoryOverview())->keyBy('id');
        $gone = $rows->pull($id);
        if ($gone && $moveTo && $rows->has($moveTo)) {
            $target = $rows[$moveTo];
            foreach (['products', 'active_products', 'low_stock', 'stock_value'] as $k) {
                $target[$k] += $gone[$k];
            }
            $rows[$moveTo] = $target;
        }
        session(['mock.categories' => self::renumber($rows->sortBy('position')->values()->all())]);
    }

    /** Simpan urutan kategori baru dari daftar id (session). */
    public static function reorderCategories(array $ids): void
    {
        $rows = collect(self::categoryOverview())->keyBy('id');
        $ordered = collect($ids)->filter(fn ($id) => $rows->has($id))->map(fn ($id) => $rows[$id])
            ->concat($rows->except($ids)->values())->values()->all();
        session(['mock.categories' => self::renumber($ordered)]);
    }

    private static function renumber(array $rows): array
    {
        return array_map(fn ($r, $i) => [...$r, 'position' => $i + 1], $rows, array_keys($rows));
    }

    private static function seedCategories(): array
    {
        $rows = [
            [1, 'Alat Tulis', 'emerald', 5, 5, 2, 1_284_000, 3],
            [2, 'Makanan', 'amber', 3, 3, 1, 302_400, 6],
            [3, 'Minuman', 'sky', 3, 3, 0, 504_000, 12],
            [4, 'Seragam', 'violet', 3, 3, 1, 638_400, 20],
            [5, 'Perlengkapan Ibadah', 'teal', 0, 0, 0, 0, 1],
        ];

        return array_map(fn ($r, $i) => [
            'id' => $r[0], 'name' => $r[1], 'color' => $r[2], 'position' => $i + 1,
            'products' => $r[3], 'active_products' => $r[4], 'low_stock' => $r[5], 'stock_value' => $r[6],
            'updated_at' => now()->subDays($r[7])->toDateTimeString(),
        ], $rows, array_keys($rows));
    }

    /**
     * Riwayat impor barang tiruan. Kontrak: GET /barang/impor/riwayat → {id, file_name, format,
     * status (selesai|sebagian|gagal|pratinjau), total_rows, created, updated, skipped, failed,
     * user, created_at, finished_at}, terbaru dulu.
     *
     * @return list<array{id: int, file_name: string, format: string, status: string, total_rows: int, created: int, updated: int, skipped: int, failed: int, user: string, created_at: string, finished_at: ?string}>
     */
    public static function importHistory(): array
    {
        $session = collect(session('mock.imports', []));

        return $session->concat(collect(self::seedImports())->whereNotIn('id', $session->pluck('id')))
            ->sortByDesc('created_at')->values()->all();
    }

    private static function seedImports(): array
    {
        $rows = [
            ['barang-semester-ganjil.xlsx', 'xlsx', 'pratinjau', 64, 0, 0, 0, 3, 'Pak Dedi', 0, null],
            ['harga-baru-oktober.csv', 'csv', 'selesai', 12, 0, 12, 0, 0, 'Admin Koperasi', 2, 1],
            ['seragam-baru.csv', 'csv', 'sebagian', 18, 14, 0, 1, 3, 'Pak Dedi', 6, 1],
            ['data-awal-koperasi.xlsx', 'xlsx', 'selesai', 14, 14, 0, 0, 0, 'Admin Koperasi', 29, 2],
            ['salah-format.csv', 'csv', 'gagal', 0, 0, 0, 0, 0, 'Bu Rina', 31, 0],
        ];

        return array_map(fn ($r, $i) => [
            'id' => 50 - $i, 'file_name' => $r[0], 'format' => $r[1], 'status' => $r[2], 'total_rows' => $r[3],
            'created' => $r[4], 'updated' => $r[5], 'skipped' => $r[6], 'failed' => $r[7], 'user' => $r[8],
            'created_at' => $at = now()->subDays($r[9])->setTime(9 + $i, 15)->toDateTimeString(),
            'finished_at' => $r[10] === null ? null : \Illuminate\Support\Carbon::parse($at)->addMinutes($r[10])->toDateTimeString(),
        ], $rows, array_keys($rows));
    }

    /**
     * Catat unggahan impor tiruan (status pratinjau) di session dan kembalikan barisnya.
     * Backend menyusul: POST /barang/impor → baca berkas, simpan baris ke tabel impor.
     */
    public static function createImport(string $fileName, string $format, string $mode, bool $updateStock, string $user): array
    {
        $import = [
            'id' => 100 + count(session('mock.imports', [])), 'file_name' => $fileName, 'format' => $format, 'status' => 'pratinjau',
            'mode' => $mode, 'update_stock' => $updateStock, 'total_rows' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0,
            'failed' => 0, 'user' => $user, 'created_at' => now()->toDateTimeString(), 'finished_at' => null,
        ];
        session(['mock.imports' => [$import, ...session('mock.imports', [])]]);

        return $import;
    }

    /**
     * Baris berkas impor tiruan (setelah dibaca). Kontrak backend: GET /barang/impor/{id}/baris →
     * {line, values: {kolom: teks}, skipped}. Suntingan & lewati disimpan di session.
     *
     * @return list<array{line: int, values: array<string, string>, skipped: bool}>
     */
    public static function importRows(int $importId): array
    {
        $rows = session("mock.import_rows.{$importId}");
        if ($rows !== null) {
            return $rows;
        }

        $raw = [
            ['ATK001', 'Buku Tulis 38 Lembar', 'Alat Tulis', '4.500', '3200', '120', '20', 'pcs', 'ya'],
            ['ATK002', 'Pulpen Hitam', 'Alat Tulis', '3000', '2400', '85', '20', 'pcs', 'ya'],
            ['atk010', 'Spidol Papan Tulis', 'Alat Tulis', '8000', '6500', '24', '5', 'pcs', 'ya'],
            ['ATK011', 'Map Plastik', 'Alat Tulis', 'Rp 2.500', '1800', '50', '10', 'pcs', ''],
            ['MKN004', 'Wafer Coklat', 'Makanan', '2500', '2000', '40', '10', 'bks', 'ya'],
            ['MKN005', 'Kacang Atom', 'Snack', '2000', '1500', '30', '10', 'bks', 'ya'],
            ['MNM004', 'Jus Jeruk Kotak', 'Minuman', '5000', '4000', '12', '6', 'kotak', 'ya'],
            ['MNM005', '', 'Minuman', '3000', '2500', '24', '6', 'btl', 'ya'],
            ['MNM006', 'Teh Botol', 'Minuman', 'tiga ribu', '2500', '24', '6', 'btl', 'ya'],
            ['SRG004', 'Bet Lokasi', 'Seragam', '6000', '4500', '40', '10', 'pcs', 'ya'],
            ['SRG005', 'Kaos Kaki Putih', 'Seragam', '9000', '9500', '20', '5', 'pasang', 'ya'],
            ['ATK010', 'Spidol Papan Tulis Biru', 'Alat Tulis', '8000', '6500', '12', '5', 'pcs', 'ya'],
            ['SRG002', 'Topi Sekolah', 'Seragam', '20000', '16000', '4', '5', 'pcs', 'tidak'],
        ];

        return array_map(fn ($r, $i) => [
            'line' => $i + 2,
            'values' => array_combine(ProductImportFormat::headers(), $r),
            'skipped' => false,
        ], $raw, array_keys($raw));
    }

    /** Simpan suntingan satu baris atau tanda lewati (session). */
    public static function updateImportRow(int $importId, int $line, ?array $values = null, ?bool $skipped = null): void
    {
        $rows = array_map(function ($r) use ($line, $values, $skipped) {
            if ($r['line'] !== $line) {
                return $r;
            }

            return [...$r, 'values' => $values ?? $r['values'], 'skipped' => $skipped ?? $r['skipped']];
        }, self::importRows($importId));
        session(["mock.import_rows.{$importId}" => $rows]);
    }

    /** Tandai impor tiruan selesai dengan hasil hitungannya (session). */
    public static function finishImport(int $importId, array $counts): void
    {
        $import = collect(self::importHistory())->firstWhere('id', $importId);
        if (! $import) {
            return;
        }
        $status = $counts['failed'] > 0 || $counts['skipped'] > 0 ? 'sebagian' : 'selesai';
        $done = [...$import, ...$counts, 'status' => $status, 'finished_at' => now()->toDateTimeString()];
        session(['mock.imports' => collect(session('mock.imports', []))->reject(fn ($i) => $i['id'] === $importId)->prepend($done)->values()->all()]);
    }
}
