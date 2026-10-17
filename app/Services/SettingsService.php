<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Support\OpeningHours;
use App\Support\PriceCalculator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pengaturan koperasi dari tabel settings, dikelompokkan per bagian:
 * profile (identitas & jam buka), receipt (struk), tax (pajak & pembulatan).
 * Nilai yang belum pernah disimpan memakai bawaan di DEFAULTS. Seluruh isi
 * di-cache (kunci "settings:all") dan dibersihkan setiap kali disimpan.
 */
class SettingsService
{
    public const SECTIONS = ['profile', 'receipt', 'tax', 'discount', 'security', 'credit', 'returns', 'cashier'];

    public const SECTION_LABELS = ['profile' => 'Profil koperasi', 'receipt' => 'Pengaturan struk', 'tax' => 'Pajak & pembulatan', 'discount' => 'Diskon manual kasir', 'security' => 'Keamanan & retensi', 'credit' => 'Bon anggota', 'returns' => 'Retur & pembatalan', 'cashier' => 'Layar kasir'];

    public const CACHE_KEY = 'settings:all';

    public const DEFAULTS = [
        'profile' => [
            'app_name' => null,      // kosong = ikut nama singkat
            'name' => 'Koperasi Siswa Al-Hikmah',
            'short_name' => 'Kopsis Al-Hikmah',
            'school' => 'Madrasah Tsanawiyah Negeri 1 Contoh',
            'address' => 'Jl. Pendidikan No. 1, Kota Contoh',
            'phone' => '0812-3456-7890',
            'email' => null,
            'logo' => null,
            'qris' => null,          // gambar QRIS statis untuk pembayaran non-tunai
            'schedule' => OpeningHours::DEFAULT,
            'motto' => 'Jujur, amanah, dan saling menolong dalam kebaikan.',
        ],
        'receipt' => [
            'header' => null,       // kosong = ikut nama koperasi
            'subheader' => null,    // kosong = ikut nama madrasah
            'show_logo' => false,   // logo koperasi di kepala struk (bila ada)
            'show_address' => true,
            'show_phone' => false,
            'show_cashier' => true,
            'show_member' => true,
            'note' => null,
            'footer' => 'Terima kasih, semoga berkah. Jazakumullah khairan.',
            'paper' => '58mm',
        ],
        'tax' => [
            'label' => 'Pajak',
            'enabled' => false,
            'rate' => 0,
            'included' => false,     // harga sudah termasuk pajak
            'rounding' => 'none',    // none | up | nearest | down
            'rounding_unit' => 100,  // 100 | 500 | 1000
            'cash_only' => true,     // pembulatan hanya untuk tunai
        ],
        'discount' => [
            'enabled' => true,          // kasir boleh memberi diskon manual
            'max_percent' => 10,        // batas persen per transaksi tanpa admin
            'max_amount' => 20000,      // batas rupiah per transaksi tanpa admin
            'reason_required' => true,  // alasan wajib diisi
        ],
        'security' => [
            'trash_days' => 30,          // data master terhapus disimpan sebelum dibersihkan permanen
            'export_days' => 7,          // berkas ekspor laporan/rekap
            'notification_days' => 90,   // notifikasi yang sudah dibaca
            'idle_minutes' => 60,        // keluar otomatis bila tidak aktif
            'max_attempts' => 5,         // gagal masuk sebelum dikunci (tingkat 1)
            'logo_max_kb' => 512,
            'logo_types' => ['png', 'jpg', 'webp'],
            'import_max_kb' => 5120,
            'import_types' => ['csv', 'xlsx'],
        ],
        'credit' => [
            'enabled' => true,           // kasir boleh menerima bon (bayar nanti)
            'limit_siswa' => 50000,      // batas bon bawaan siswa (rupiah)
            'limit_guru' => 300000,      // batas bon bawaan guru (rupiah)
            'default_days' => 14,        // jatuh tempo yang dipilih otomatis di kasir
            'max_days' => 60,            // jatuh tempo terlama
            'block_overdue' => true,     // tolak bon baru bila masih ada bon lewat jatuh tempo
        ],
        'returns' => [
            'limit_days' => 3,           // retur/pembatalan paling lambat sekian hari setelah pembelian
            'void_auto_max' => 20000,    // pembatalan ≤ nilai ini oleh kasir yang sama disetujui otomatis (0 = selalu perlu persetujuan)
            'void_auto_minutes' => 30,   // ... dan diajukan paling lama sekian menit setelah transaksi
        ],
        'cashier' => [
            'picker_limit' => 60,        // jumlah barang paling banyak di daftar pilih kasir (sisanya lewat cari/kategori/scan)
            'bestseller_days' => 30,     // periode hitung barang paling laku (hari)
            'bestseller_badges' => 8,    // jumlah barang teratas yang diberi label "Terlaris" (0 = tanpa label)
        ],
    ];

    /** Batas aman angka pengaturan layar kasir: [min, max]. */
    public const CASHIER_LIMITS = [
        'picker_limit' => [20, 200],
        'bestseller_days' => [7, 90],
        'bestseller_badges' => [0, 20],
    ];

    /** Batas aman angka pengaturan retur & pembatalan: [min, max]. */
    public const RETURN_LIMITS = [
        'limit_days' => [1, 30],
        'void_auto_max' => [0, 1_000_000],
        'void_auto_minutes' => [0, 240],
    ];

    /** Batas aman angka pengaturan bon: [min, max]. */
    public const CREDIT_LIMITS = [
        'limit_siswa' => [0, 5_000_000],
        'limit_guru' => [0, 10_000_000],
        'default_days' => [1, 180],
        'max_days' => [7, 180],
    ];

    /** Batas aman angka pengaturan keamanan & retensi: [min, max]. */
    public const SECURITY_LIMITS = [
        'trash_days' => [7, 365],
        'export_days' => [1, 30],
        'notification_days' => [30, 365],
        'idle_minutes' => [5, 480],
        'max_attempts' => [3, 10],
        'logo_max_kb' => [64, 2048],
        'import_max_kb' => [256, 10240],
    ];

    /** Jenis berkas yang boleh dipilih untuk tiap unggahan. */
    public const UPLOAD_TYPES = [
        'logo' => ['png' => 'PNG', 'jpg' => 'JPG/JPEG', 'webp' => 'WEBP'],
        'import' => ['csv' => 'CSV', 'xlsx' => 'Excel (.xlsx)'],
    ];

    /** Field yang tidak dicatat nilainya di audit (terlalu besar). */
    private const AUDIT_SKIP_VALUE = ['logo', 'qris'];

    /**
     * Semua pengaturan + kapan & oleh siapa terakhir diubah.
     *
     * @return array{profile: array, receipt: array, tax: array, discount: array, security: array, credit: array, returns: array, cashier: array, updated_at: ?string, updated_by: ?string}
     */
    public function all(): array
    {
        $stored = $this->stored();

        return [
            ...collect(self::SECTIONS)->mapWithKeys(fn ($s) => [$s => [...self::DEFAULTS[$s], ...($stored['values'][$s] ?? [])]])->all(),
            'updated_at' => $stored['updated_at'],
            'updated_by' => $stored['updated_by'],
        ];
    }

    public function section(string $section): array
    {
        return $this->all()[$section];
    }

    /** Profil koperasi (logo sebagai URL) + jam buka yang sudah dikelompokkan + tulisan penutup struk. */
    public function profile(): array
    {
        $all = $this->all();
        $profile = $all['profile'];
        $profile['schedule'] = $this->normalizeSchedule($profile['schedule']);
        $profile['logo'] = LogoService::url($profile['logo']);
        $profile['app_name'] = $profile['app_name'] ?: $profile['short_name']; // nama berkas → URL siap pakai di <img>
        $profile['hours'] = OpeningHours::group($profile['schedule']);
        $profile['receipt_footer'] = $all['receipt']['footer'];

        return $profile;
    }

    public function receipt(): array
    {
        return $this->section('receipt');
    }

    public function tax(): array
    {
        return $this->section('tax');
    }

    /** Aturan diskon manual kasir: enabled, max_percent, max_amount, reason_required. */
    public function discount(): array
    {
        return $this->section('discount');
    }

    /** Masa retensi, batas sesi & login, serta batas berkas unggahan. */
    public function security(): array
    {
        return $this->section('security');
    }

    /** Bon anggota: enabled, limit_siswa, limit_guru, default_days, max_days, block_overdue. */
    public function credit(): array
    {
        return $this->section('credit');
    }

    /** Retur & pembatalan: limit_days, void_auto_max, void_auto_minutes. */
    public function returns(): array
    {
        return $this->section('returns');
    }

    /** Pengaturan layar kasir (batas daftar barang & barang terlaris). */
    public function cashier(): array
    {
        return $this->section('cashier');
    }

    /**
     * Simpan sebagian/seluruh isi satu bagian. Hanya kunci yang dikenal & berubah
     * yang ditulis; perubahan dicatat di audit. Mengembalikan pengaturan terbaru.
     */
    public function save(string $section, array $values, ?User $by = null): array
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);
        $by ??= auth()->user();

        $before = $this->section($section);
        $values = array_intersect_key($values, self::DEFAULTS[$section]);
        $changed = array_filter($values, fn ($v, $k) => $v !== $before[$k], ARRAY_FILTER_USE_BOTH);

        if ($changed) {
            DB::transaction(function () use ($section, $changed, $before, $by) {
                foreach ($changed as $key => $value) {
                    Setting::set("{$section}.{$key}", $value, $by?->id);
                }

                $changes = collect($changed)->mapWithKeys(fn ($v, $k) => [$k => in_array($k, self::AUDIT_SKIP_VALUE, true)
                    ? ['from' => $before[$k] ? '(gambar)' : null, 'to' => $v ? '(gambar baru)' : null]
                    : ['from' => $before[$k], 'to' => $v]])->all();

                AuditLog::record('setting.updated', 'pengaturan',
                    self::SECTION_LABELS[$section].' diubah: '.implode(', ', array_keys($changed)),
                    self::subject($section), ['section' => $section, 'changes' => $changes], null, $this->actor($by),
                    action: 'diubah',
                    old: collect($changes)->mapWithKeys(fn ($c, $k) => ["{$section}.{$k}" => $c['from']])->all(),
                    new: collect($changes)->mapWithKeys(fn ($c, $k) => ["{$section}.{$k}" => $c['to']])->all(),
                    reason: AuditLog::reason());
            });
            $this->flush();
        }

        return $this->all();
    }

    /**
     * Aturan validasi isian API per bagian. Semua kunci memakai `sometimes` sehingga
     * penyimpanan sebagian diperbolehkan. Aturan lintas kunci ada di crossCheck().
     */
    public static function rules(string $section, array $input = []): array
    {
        $maxLines = fn ($a, $v, $fail) => substr_count((string) $v, "\n") > 2 ? $fail('Maksimal 3 baris.') : null;

        return match ($section) {
            'profile' => [
                'app_name' => ['sometimes', 'nullable', 'string', 'max:60'],
                'name' => ['sometimes', 'required', 'string', 'max:100'],
                'short_name' => ['sometimes', 'required', 'string', 'max:30'],
                'school' => ['sometimes', 'required', 'string', 'max:120'],
                'address' => ['sometimes', 'nullable', 'string', 'max:200'],
                'phone' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{6,20}$/'],
                'email' => ['sometimes', 'nullable', 'email', 'max:150'],
                'motto' => ['sometimes', 'nullable', 'string', 'max:150'],
                'schedule' => ['sometimes', 'array:1,2,3,4,5,6,7'],
                'schedule.*' => ['nullable', 'array:open,close', 'required_array_keys:open,close'],
                'schedule.*.open' => ['sometimes', 'date_format:H:i'],
                'schedule.*.close' => ['sometimes', 'date_format:H:i', function ($attr, $value, $fail) use ($input) {
                    $open = data_get($input, str_replace('.close', '.open', $attr));
                    if (is_string($open) && is_string($value) && $value <= $open) {
                        $fail('Jam tutup harus setelah jam buka.');
                    }
                }],
            ],
            'receipt' => [
                'header' => ['sometimes', 'nullable', 'string', 'max:40'],
                'subheader' => ['sometimes', 'nullable', 'string', 'max:60'],
                'show_logo' => ['sometimes', 'boolean'],
                'show_address' => ['sometimes', 'boolean'],
                'show_phone' => ['sometimes', 'boolean'],
                'show_cashier' => ['sometimes', 'boolean'],
                'show_member' => ['sometimes', 'boolean'],
                'note' => ['sometimes', 'nullable', 'string', 'max:160', $maxLines],
                'footer' => ['sometimes', 'required', 'string', 'max:160', $maxLines],
                'paper' => ['sometimes', 'required', 'in:58mm,80mm'],
            ],
            'tax' => [
                'enabled' => ['sometimes', 'boolean'],
                'label' => ['sometimes', 'required', 'string', 'max:20'],
                'rate' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
                'included' => ['sometimes', 'boolean'],
                'rounding' => ['sometimes', 'required', 'in:'.implode(',', array_keys(PriceCalculator::ROUNDING))],
                'rounding_unit' => ['sometimes', 'required', 'integer', 'in:'.implode(',', PriceCalculator::UNITS)],
                'cash_only' => ['sometimes', 'boolean'],
            ],
            'discount' => [
                'enabled' => ['sometimes', 'boolean'],
                'max_percent' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
                'max_amount' => ['sometimes', 'required', 'integer', 'min:0', 'max:10000000'],
                'reason_required' => ['sometimes', 'boolean'],
            ],
            'security' => collect(self::SECURITY_LIMITS)
                ->map(fn ($l) => ['sometimes', 'required', 'integer', 'min:'.$l[0], 'max:'.$l[1]])->all() + [
                    'logo_types' => ['sometimes', 'required', 'array', 'min:1'],
                    'logo_types.*' => ['in:'.implode(',', array_keys(self::UPLOAD_TYPES['logo']))],
                    'import_types' => ['sometimes', 'required', 'array', 'min:1'],
                    'import_types.*' => ['in:'.implode(',', array_keys(self::UPLOAD_TYPES['import']))],
                ],
            'returns' => collect(self::RETURN_LIMITS)
                ->map(fn ($l) => ['sometimes', 'required', 'integer', 'min:'.$l[0], 'max:'.$l[1]])->all(),
            'cashier' => collect(self::CASHIER_LIMITS)
                ->map(fn ($l) => ['sometimes', 'required', 'integer', 'min:'.$l[0], 'max:'.$l[1]])->all(),
            'credit' => collect(self::CREDIT_LIMITS)
                ->map(fn ($l) => ['sometimes', 'required', 'integer', 'min:'.$l[0], 'max:'.$l[1]])->all() + [
                    'enabled' => ['sometimes', 'boolean'],
                    'block_overdue' => ['sometimes', 'boolean'],
                ],
            default => abort(404),
        };
    }

    /**
     * Pemeriksaan lintas kunci memakai isian + nilai tersimpan, untuk Validator::after():
     * pajak yang aktif wajib bertarif > 0; jatuh tempo bon bawaan ≤ jatuh tempo terlama.
     *
     * @return array<string, string> [kunci => pesan]
     */
    public static function crossCheck(string $section, array $input, array $current): array
    {
        if ($section === 'tax') {
            $enabled = filter_var($input['enabled'] ?? $current['enabled'] ?? false, FILTER_VALIDATE_BOOL);
            $rate = $input['rate'] ?? $current['rate'] ?? 0;
            if ($enabled && is_numeric($rate) && (float) $rate <= 0) {
                return ['rate' => 'Isi tarif pajak lebih dari 0, atau matikan pajak.'];
            }
        }
        if ($section === 'credit') {
            $default = $input['default_days'] ?? $current['default_days'] ?? 14;
            $max = $input['max_days'] ?? $current['max_days'] ?? 60;
            if (is_numeric($default) && is_numeric($max) && (int) $default > (int) $max) {
                return ['default_days' => 'Jatuh tempo bawaan tidak boleh melebihi jatuh tempo terlama.'];
            }
        }

        return [];
    }

    public static function messages(): array
    {
        return [
            'required' => 'Wajib diisi.',
            'max' => 'Maksimal :max karakter.',
            'rate.max' => 'Tarif maksimal 100%.',
            'max_percent.max' => 'Batas persen maksimal 100%.',
            'max_percent.min' => 'Batas persen minimal 1%.',
            'max_amount.max' => 'Batas rupiah terlalu besar.',
            'rate.min' => 'Tarif tidak boleh negatif.',
            'rate.numeric' => 'Tarif harus berupa angka.',
            'rate.decimal' => 'Tarif maksimal 2 angka di belakang koma.',
            'phone.regex' => 'Nomor telepon tidak valid.',
            'email.email' => 'Format email belum benar.',
            'date_format' => 'Format jam HH:MM.',
            'paper.in' => 'Pilih kertas 58mm atau 80mm.',
            'in' => 'Pilihan tidak dikenal.',
            'schedule.array' => 'Jadwal memakai hari 1 (Senin) sampai 7 (Minggu).',
            'schedule.*.required_array_keys' => 'Isi jam buka dan jam tutup, atau kosongkan (null) bila tutup.',
        ];
    }

    /** Rapikan isian yang sudah valid: trim, teks kosong → null, tipe bool/int/float. */
    public static function normalize(string $section, array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $default = self::DEFAULTS[$section][$key] ?? null;
            $out[$key] = match (true) {
                $key === 'schedule' => collect(range(1, 7))->mapWithKeys(fn ($d) => [$d => isset($value[$d]) ? ['open' => $value[$d]['open'], 'close' => $value[$d]['close']] : null])->all(),
                $key === 'rate' => round((float) $value, 2),
                str_ends_with($key, '_types') => array_values(array_intersect(
                    array_keys(self::UPLOAD_TYPES[str_replace('_types', '', $key)] ?? []), (array) $value)),
                is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOL),
                is_int($default) => (int) $value,
                is_string($value) => trim($value) === '' && ! in_array($key, ['name', 'short_name', 'school', 'footer', 'label', 'paper', 'rounding'], true) ? null : trim($value),
                default => $value,
            };
        }

        return $out;
    }

    /** Subjek audit satu bagian pengaturan (id = urutan bagian, stabil untuk riwayat objek). */
    private static function subject(string $section): array
    {
        return ['type' => 'setting', 'id' => array_search($section, self::SECTIONS, true) + 1, 'label' => self::SECTION_LABELS[$section]];
    }

    /** Kembalikan satu bagian ke nilai bawaan (hapus baris yang tersimpan). */
    public function reset(string $section, ?User $by = null): array
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);

        $deleted = Setting::where('group', $section)->pluck('key');
        if ($deleted->isNotEmpty()) {
            $logo = $section === 'profile' ? $this->section('profile')['logo'] : null;
            $before = $this->section($section);
            $reverted = collect(self::DEFAULTS[$section])
                ->filter(fn ($v, $k) => ($before[$k] ?? null) !== $v && ! in_array($k, self::AUDIT_SKIP_VALUE, true));
            Setting::whereIn('key', $deleted)->delete();
            $deleted->each(fn ($k) => Cache::forget("setting:{$k}"));
            AuditLog::record('setting.reset', 'pengaturan', self::SECTION_LABELS[$section].' dikembalikan ke bawaan',
                self::subject($section), ['section' => $section], null, $this->actor($by),
                action: 'diubah',
                old: $reverted->mapWithKeys(fn ($v, $k) => ["{$section}.{$k}" => $before[$k] ?? null])->all() ?: null,
                new: $reverted->mapWithKeys(fn ($v, $k) => ["{$section}.{$k}" => $v])->all() ?: null,
                reason: AuditLog::reason());
            $this->flush();
            if ($logo && preg_match('/^'.LogoService::FILE_PATTERN.'$/', $logo)) {
                Storage::disk(LogoService::DISK)->delete(LogoService::path($logo));
            }
        }

        return $this->all();
    }

    /** Petugas untuk audit: yang diberikan, atau (null) petugas yang sedang login. */
    private function actor(?User $by): ?array
    {
        return $by && $by->id !== auth()->id() ? ['id' => $by->id, 'name' => $by->name, 'role' => $by->roleName()] : null;
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Nilai tersimpan per bagian. Bila database tidak bisa dihubungi (mis. halaman
     * error/maintenance), kembalikan kosong agar bawaan yang dipakai.
     */
    private function stored(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, function () {
                $rows = Setting::with('editor:id,name')->whereIn('group', self::SECTIONS)->get();
                $latest = $rows->sortByDesc('updated_at')->first();

                return [
                    'values' => $rows->groupBy('group')->map(fn ($group, $section) => $group->mapWithKeys(fn (Setting $s) => [
                        substr($s->key, strlen($section) + 1) => $s->value === null ? null : Setting::decode($s->value, $s->type),
                    ])->all())->all(),
                    'updated_at' => $latest?->updated_at?->toDateTimeString(),
                    'updated_by' => $latest?->editor?->name,
                ];
            });
        } catch (Throwable $e) {
            report($e);

            return ['values' => [], 'updated_at' => null, 'updated_by' => null];
        }
    }

    /** JSON mengubah kunci hari menjadi string; kembalikan ke [isoDay => ['HH:MM','HH:MM']|null] urut 1–7. */
    private function normalizeSchedule(mixed $schedule): array
    {
        $schedule = is_array($schedule) ? $schedule : [];

        return collect(range(1, 7))->mapWithKeys(fn ($d) => [$d => $schedule[$d] ?? $schedule[(string) $d] ?? null])->all();
    }
}
