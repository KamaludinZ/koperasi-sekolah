<?php

namespace App\Livewire\Forms;

use App\Support\MockData;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * Form tambah/ubah anggota. Kolom mengikuti tabel members di PRD.
 * Tahap frontend: disimpan ke data tiruan (session).
 */
class MemberForm extends Form
{
    public ?int $id = null;

    public string $member_code = '';

    public string $name = '';

    public string $type = 'siswa';

    public ?string $class = '';

    public ?string $phone = '';

    public bool $is_active = true;

    protected function rules(): array
    {
        $codes = collect(MockData::members())->reject(fn ($m) => $m['id'] === $this->id)
            ->pluck('member_code')->map(fn ($c) => mb_strtoupper($c))->all();

        return [
            'member_code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-]+$/', function ($attr, $value, $fail) use ($codes) {
                if (in_array(mb_strtoupper($value), $codes, true)) {
                    $fail('Kode anggota sudah dipakai.');
                }
            }],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(['siswa', 'guru'])],
            'class' => ['nullable', 'required_if:type,siswa', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]{8,20}$/'],
            'is_active' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'required' => 'Wajib diisi.',
            'class.required_if' => 'Kelas wajib diisi untuk siswa.',
            'member_code.regex' => 'Hanya huruf, angka, dan tanda -.',
            'phone.regex' => 'Nomor HP tidak valid.',
            'max' => 'Maksimal :max karakter.',
        ];
    }

    public function blank(string $type = 'siswa'): void
    {
        $this->reset();
        $this->resetValidation();
        $this->type = $type;
        $this->member_code = MockData::nextMemberCode($type);
    }

    public function load(array $member): void
    {
        $this->reset();
        $this->resetValidation();
        $this->fill(collect($member)->only(array_keys($this->all()))->map(fn ($v) => $v ?? '')->all());
    }

    /** Jenis diganti saat menambah: perbarui saran kode & kosongkan kelas untuk guru. */
    public function switchType(): void
    {
        if ($this->type === 'guru') {
            $this->class = '';
        }
        if ($this->id === null) {
            $this->member_code = MockData::nextMemberCode($this->type);
        }
    }

    public function save(): array
    {
        $data = $this->validate();
        $data['member_code'] = mb_strtoupper(trim($data['member_code']));
        $data['class'] = $data['type'] === 'guru' ? null : mb_strtoupper(trim((string) $data['class']));
        $data['phone'] = filled($data['phone']) ? trim($data['phone']) : null;

        return MockData::saveMember(['id' => $this->id, ...$data]);
    }
}
