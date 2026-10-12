<?php

namespace App\Livewire\Pengaturan;

use App\Services\LogoService;
use App\Support\MockData;
use App\Support\OpeningHours;
use App\Livewire\Concerns\NotifiesSave;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Form Profil Koperasi: nama, nama singkat, madrasah, alamat, kontak, motto, logo,
 * dan jam buka per hari; dengan pratinjau langsung di struk & beranda.
 * Disimpan ke tabel settings lewat SettingsService; logo sebagai berkas lewat LogoService.
 */
#[Layout('components.layouts.app')]
#[Title('Profil Koperasi')]
class Profile extends Component
{
    use NotifiesSave;
    use WithFileUploads;

    public string $name = '';

    public string $short_name = '';

    public string $school = '';

    public string $address = '';

    public string $phone = '';

    public string $email = '';

    public string $motto = '';

    /** URL logo yang tersimpan (untuk pratinjau). */
    public ?string $logo = null;

    /** Logo tersimpan ditandai untuk dihapus saat disimpan. */
    public bool $logoRemoved = false;

    /** @var TemporaryUploadedFile|null */
    public $logoUpload = null;

    /** @var array<int, array{open: bool, from: string, to: string}> */
    public array $schedule = [];

    public bool $saved = false;

    public function mount(): void
    {
        $p = MockData::profile();
        $this->fill([
            'name' => $p['name'], 'short_name' => $p['short_name'], 'school' => $p['school'],
            'address' => $p['address'] ?? '', 'phone' => $p['phone'] ?? '', 'email' => $p['email'] ?? '',
            'motto' => $p['motto'] ?? '', 'logo' => $p['logo'] ?? null,
        ]);
        foreach (OpeningHours::DAYS as $d => $label) {
            $h = $p['schedule'][$d] ?? null;
            $this->schedule[$d] = ['open' => (bool) $h, 'from' => $h['open'] ?? '07:00', 'to' => $h['close'] ?? '14:00'];
        }
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'short_name' => ['required', 'string', 'max:30'],
            'school' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{6,20}$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'motto' => ['nullable', 'string', 'max:150'],
            'logoUpload' => ['nullable', ...array_slice(LogoService::rules(), 1)],
            'schedule.*.open' => ['boolean'],
            'schedule.*.from' => ['required_if:schedule.*.open,true', 'date_format:H:i'],
            'schedule.*.to' => ['required_if:schedule.*.open,true', 'date_format:H:i', function ($attr, $value, $fail) {
                $day = $this->schedule[(int) explode('.', $attr)[1]] ?? null;
                if ($day && $day['open'] && $value <= $day['from']) {
                    $fail('Jam tutup harus setelah jam buka.');
                }
            }],
        ];
    }

    protected function messages(): array
    {
        return [
            'required' => 'Wajib diisi.',
            'max' => 'Maksimal :max karakter.',
            'phone.regex' => 'Nomor telepon tidak valid.',
            'email.email' => 'Format email belum benar.',
            ...LogoService::messages('logoUpload'),
            'date_format' => 'Format jam HH:MM.',
        ];
    }

    public function updatedLogoUpload(): void
    {
        $this->validateOnly('logoUpload');
    }

    public function removeLogo(): void
    {
        $this->logo = null;
        $this->logoUpload = null;
        $this->logoRemoved = true;
    }

    public function save(): void
    {
        $this->saved = false;
        $this->saveWithToast(function () {
            $this->validate();

            $logos = app(LogoService::class);
            if ($this->logoUpload) {
                $logos->store($this->logoUpload);
                $this->logoUpload = null;
            } elseif ($this->logoRemoved) {
                $logos->delete();
            }
            $this->logoRemoved = false;

            MockData::saveSettings('profile', [
                'name' => trim($this->name), 'short_name' => trim($this->short_name), 'school' => trim($this->school),
                'address' => trim($this->address), 'phone' => trim($this->phone), 'email' => trim($this->email) ?: null,
                'motto' => trim($this->motto),
                'schedule' => collect($this->schedule)->map(fn ($h) => $h['open'] ? ['open' => $h['from'], 'close' => $h['to']] : null)->all(),
            ]);
            $this->logo = MockData::profile()['logo'];
            $this->saved = true;
        }, 'Profil koperasi disimpan. Struk dan beranda langsung memakai data baru.');
        $this->dispatch('profile-saved');
    }

    /** Teks jam buka dari isian form (untuk pratinjau). */
    public function previewHours(): array
    {
        return OpeningHours::group(collect($this->schedule)->map(fn ($h) => $h['open'] && $h['from'] && $h['to'] ? ['open' => $h['from'], 'close' => $h['to']] : null)->all());
    }

    public function render()
    {
        return view('livewire.pengaturan.profile');
    }
}
