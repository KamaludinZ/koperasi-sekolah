<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Support\OpeningHours;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pengaturan koperasi dari tabel settings, dikelompokkan per bagian:
 * profile (identitas & jam buka), receipt (struk), tax (pajak & pembulatan).
 * Nilai yang belum pernah disimpan memakai bawaan di DEFAULTS. Seluruh isi
 * di-cache (kunci "settings:all") dan dibersihkan setiap kali disimpan.
 */
class SettingsService
{
    public const SECTIONS = ['profile', 'receipt', 'tax'];

    public const SECTION_LABELS = ['profile' => 'Profil koperasi', 'receipt' => 'Pengaturan struk', 'tax' => 'Pajak & pembulatan'];

    public const CACHE_KEY = 'settings:all';

    public const DEFAULTS = [
        'profile' => [
            'name' => 'Koperasi Siswa Al-Hikmah',
            'short_name' => 'Kopsis Al-Hikmah',
            'school' => 'Madrasah Tsanawiyah Negeri 1 Contoh',
            'address' => 'Jl. Pendidikan No. 1, Kota Contoh',
            'phone' => '0812-3456-7890',
            'email' => null,
            'logo' => null,
            'schedule' => OpeningHours::DEFAULT,
            'motto' => 'Jujur, amanah, dan saling menolong dalam kebaikan.',
        ],
        'receipt' => [
            'header' => null,       // kosong = ikut nama koperasi
            'subheader' => null,    // kosong = ikut nama madrasah
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
    ];

    /** Field yang tidak dicatat nilainya di audit (terlalu besar). */
    private const AUDIT_SKIP_VALUE = ['logo'];

    /**
     * Semua pengaturan + kapan & oleh siapa terakhir diubah.
     *
     * @return array{profile: array, receipt: array, tax: array, updated_at: ?string, updated_by: ?string}
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

    /** Profil koperasi + jam buka yang sudah dikelompokkan + tulisan penutup struk. */
    public function profile(): array
    {
        $all = $this->all();
        $profile = $all['profile'];
        $profile['schedule'] = $this->normalizeSchedule($profile['schedule']);
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

    /** Tarif pajak efektif dalam persen (0 bila pajak dimatikan). */
    public function taxRate(): float
    {
        $tax = $this->tax();

        return $tax['enabled'] ? (float) $tax['rate'] : 0.0;
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
                    null, ['section' => $section, 'changes' => $changes], null, $this->actor($by));
            });
            $this->flush();
        }

        return $this->all();
    }

    /** Kembalikan satu bagian ke nilai bawaan (hapus baris yang tersimpan). */
    public function reset(string $section, ?User $by = null): array
    {
        abort_unless(in_array($section, self::SECTIONS, true), 404);

        $deleted = Setting::where('group', $section)->pluck('key');
        if ($deleted->isNotEmpty()) {
            Setting::whereIn('key', $deleted)->delete();
            $deleted->each(fn ($k) => Cache::forget("setting:{$k}"));
            AuditLog::record('setting.reset', 'pengaturan', self::SECTION_LABELS[$section].' dikembalikan ke bawaan',
                null, ['section' => $section], null, $this->actor($by));
            $this->flush();
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
