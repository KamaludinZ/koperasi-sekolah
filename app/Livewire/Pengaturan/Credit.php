<?php

namespace App\Livewire\Pengaturan;

use App\Livewire\Concerns\GuardsSettings;
use App\Livewire\Concerns\NotifiesSave;
use App\Models\Member;
use App\Services\SettingsService;
use Illuminate\Validation\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Form Bon Anggota: aktif/tidaknya pembayaran bon di kasir, batas bon bawaan siswa & guru,
 * jatuh tempo bawaan & terlama, dan blokir bon baru bila masih ada bon lewat jatuh tempo.
 * Batas khusus per anggota diatur di form anggota (members.credit_limit). Disimpan ke tabel
 * settings bagian `credit` lewat SettingsService (tercatat di audit).
 */
#[Layout('components.layouts.app')]
#[Title('Bon Anggota')]
class Credit extends Component
{
    use GuardsSettings;
    use NotifiesSave;

    public const LIMITS = SettingsService::CREDIT_LIMITS;

    public bool $enabled = true;

    public int|string|null $limit_siswa = 50000;

    public int|string|null $limit_guru = 300000;

    public int|string|null $default_days = 14;

    public int|string|null $max_days = 60;

    public bool $block_overdue = true;

    public function mount(): void
    {
        $this->fill(app(SettingsService::class)->credit());
    }

    protected function rules(): array
    {
        return collect(self::LIMITS)->map(fn ($l) => ['required', 'integer', 'min:'.$l[0], 'max:'.$l[1]])->all() + [
            'enabled' => ['boolean'],
            'block_overdue' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return ['required' => 'Wajib diisi.', 'integer' => 'Isi dengan angka bulat.', 'min' => 'Minimal :min.', 'max' => 'Maksimal :max.'];
    }

    public function boot(): void
    {
        $this->withValidator(function (Validator $v) {
            $v->after(function (Validator $v) {
                foreach (SettingsService::crossCheck('credit', $this->current(), []) as $key => $message) {
                    $v->errors()->add($key, $message);
                }
            });
        });
    }

    public function updated(string $property): void
    {
        if (array_key_exists($property, self::LIMITS)) {
            $this->validateOnly($property);
        }
    }

    /** Anggota yang memakai batas khusus (bukan batas bawaan jenisnya). */
    #[Computed]
    public function overrides(): array
    {
        return Member::whereNotNull('credit_limit')->orderByRaw('LOWER(name)')->limit(20)->get(['id', 'name', 'member_code', 'type', 'class', 'credit_limit'])
            ->map(fn (Member $m) => ['id' => $m->id, 'name' => $m->display_name, 'member_code' => $m->member_code, 'type' => $m->type, 'limit' => $m->credit_limit])->all();
    }

    public function current(): array
    {
        return collect(self::LIMITS)->keys()->mapWithKeys(fn ($k) => [$k => (int) $this->{$k}])->all()
            + ['enabled' => $this->enabled, 'block_overdue' => $this->block_overdue];
    }

    public function save(): void
    {
        if (! $this->ensureCanEdit('credit')) {
            return;
        }
        $this->saveWithToast(function () {
            $this->validate();
            $this->fill(app(SettingsService::class)->save('credit', $this->current())['credit']);
        }, 'Pengaturan bon anggota disimpan.');
    }

    public function restoreDefaults(): void
    {
        if (! $this->ensureCanEdit('credit')) {
            return;
        }
        $this->resetValidation();
        $this->fill(SettingsService::DEFAULTS['credit']);
        $this->toast('success', 'Isian dikembalikan ke bawaan. Tekan Simpan untuk menerapkan.');
    }

    public function render()
    {
        return view('livewire.pengaturan.credit');
    }
}
