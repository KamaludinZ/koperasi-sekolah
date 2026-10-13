<?php

namespace App\Livewire\Pengaturan;

use App\Livewire\Concerns\GuardsSettings;
use App\Livewire\Concerns\NotifiesSave;
use App\Services\SettingsService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Form Keamanan & Retensi: lama simpan data terhapus, berkas ekspor, dan notifikasi; batas
 * waktu sesi menganggur & jumlah gagal masuk sebelum dikunci; ukuran & jenis berkas unggahan
 * (logo, impor barang). Menampilkan pemeriksaan kesiapan produksi (HTTPS, cookie aman) dari
 * konfigurasi server. Disimpan ke tabel settings bagian `security` lewat SettingsService (tercatat di audit).
 */
#[Layout('components.layouts.app')]
#[Title('Keamanan & Retensi')]
class Security extends Component
{
    use GuardsSettings;
    use NotifiesSave;

    public const LOGO_TYPES = SettingsService::UPLOAD_TYPES['logo'];

    public const IMPORT_TYPES = SettingsService::UPLOAD_TYPES['import'];

    /** Batas aman tiap isian angka: [min, max]. */
    public const LIMITS = SettingsService::SECURITY_LIMITS;

    public int|string|null $trash_days = 30;

    public int|string|null $export_days = 7;

    public int|string|null $notification_days = 90;

    public int|string|null $idle_minutes = 60;

    public int|string|null $max_attempts = 5;

    public int|string|null $logo_max_kb = 512;

    /** @var list<string> */
    public array $logo_types = [];

    public int|string|null $import_max_kb = 5120;

    /** @var list<string> */
    public array $import_types = [];

    public function mount(): void
    {
        $this->fill(app(SettingsService::class)->security());
    }

    protected function rules(): array
    {
        $rules = collect(self::LIMITS)->map(fn ($l) => ['required', 'integer', 'min:'.$l[0], 'max:'.$l[1]])->all();

        return $rules + [
            'logo_types' => ['required', 'array', 'min:1'],
            'logo_types.*' => ['in:'.implode(',', array_keys(self::LOGO_TYPES))],
            'import_types' => ['required', 'array', 'min:1'],
            'import_types.*' => ['in:'.implode(',', array_keys(self::IMPORT_TYPES))],
        ];
    }

    protected function messages(): array
    {
        return [
            'required' => 'Wajib diisi.',
            'integer' => 'Isi dengan angka bulat.',
            'min' => 'Minimal :min.',
            'max' => 'Maksimal :max.',
            'logo_types.required' => 'Pilih minimal satu jenis gambar.',
            'logo_types.min' => 'Pilih minimal satu jenis gambar.',
            'import_types.required' => 'Pilih minimal satu jenis berkas.',
            'import_types.min' => 'Pilih minimal satu jenis berkas.',
            'logo_types.*.in' => 'Jenis berkas tidak didukung.',
            'import_types.*.in' => 'Jenis berkas tidak didukung.',
        ];
    }

    public function updated(string $property): void
    {
        $field = explode('.', $property)[0];
        if (array_key_exists($field, self::LIMITS)) {
            $this->validateOnly($field);
        } elseif (in_array($field, ['logo_types', 'import_types'], true)) {
            $this->resetValidation([$field, ...array_map(fn ($i) => "{$field}.{$i}", array_keys($this->{$field}))]);
            $this->validateOnly($field);
            foreach (array_keys($this->{$field}) as $i) {
                $this->validateOnly("{$field}.{$i}");
            }
        }
    }

    /** Ukuran berkas yang mudah dibaca: 512 KB, 5 MB. */
    public static function size(int|string|null $kb): string
    {
        $kb = (int) $kb;

        return $kb >= 1024 ? rtrim(rtrim(number_format($kb / 1024, 1, ',', '.'), '0'), ',').' MB' : $kb.' KB';
    }

    /**
     * Pemeriksaan kesiapan produksi dari konfigurasi server (baca saja).
     *
     * @return list<array{label: string, ok: bool, detail: string}>
     */
    #[Computed]
    public function checks(): array
    {
        $https = str_starts_with((string) config('app.url'), 'https://');

        $force = (bool) config('koperasi.force_https');

        return [
            ['label' => 'Koneksi http dialihkan ke https', 'ok' => $force, 'detail' => $force ? 'Pengalihan paksa & HSTS aktif.' : 'Aktifkan FORCE_HTTPS (bawaan aktif di production).'],
            ['label' => 'Alamat aplikasi memakai HTTPS', 'ok' => $https, 'detail' => $https ? 'Koneksi terenkripsi.' : 'APP_URL masih http — atur ke https di server.'],
            ['label' => 'Cookie sesi hanya lewat HTTPS', 'ok' => (bool) config('session.secure'), 'detail' => config('session.secure') ? 'Cookie tidak terkirim lewat koneksi biasa.' : 'Aktifkan SESSION_SECURE_COOKIE di server produksi.'],
            ['label' => 'Cookie tidak bisa dibaca skrip', 'ok' => (bool) config('session.http_only', true), 'detail' => 'HttpOnly melindungi sesi dari pencurian lewat skrip.'],
            ['label' => 'Perlindungan permintaan lintas situs', 'ok' => in_array(config('session.same_site'), ['lax', 'strict'], true), 'detail' => 'SameSite = '.(config('session.same_site') ?: 'tidak diatur').'.'],
            ['label' => 'Mode debug dimatikan', 'ok' => ! config('app.debug'), 'detail' => config('app.debug') ? 'APP_DEBUG menyala — rincian galat bisa terlihat pengguna.' : 'Rincian galat tidak ditampilkan ke pengguna.'],
        ];
    }

    public function current(): array
    {
        return collect(self::LIMITS)->keys()->mapWithKeys(fn ($k) => [$k => (int) $this->{$k}])->all() + [
            'logo_types' => array_values(array_intersect(array_keys(self::LOGO_TYPES), $this->logo_types)),
            'import_types' => array_values(array_intersect(array_keys(self::IMPORT_TYPES), $this->import_types)),
        ];
    }

    public function save(): void
    {
        if (! $this->ensureCanEdit('security')) {
            return;
        }
        $this->saveWithToast(function () {
            $this->validate();
            $this->fill(app(SettingsService::class)->save('security', $this->current())['security']);
        }, 'Pengaturan keamanan & retensi disimpan.');
    }

    public function restoreDefaults(): void
    {
        if (! $this->ensureCanEdit('security')) {
            return;
        }
        $this->resetValidation();
        $this->fill(SettingsService::DEFAULTS['security']);
        $this->toast('success', 'Isian dikembalikan ke bawaan. Tekan Simpan untuk menerapkan.');
    }

    public function render()
    {
        return view('livewire.pengaturan.security');
    }
}
