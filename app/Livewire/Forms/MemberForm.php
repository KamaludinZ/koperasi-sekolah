<?php

namespace App\Livewire\Forms;

use App\Models\Member;
use App\Services\MemberCodeGenerator;
use App\Services\MemberService;
use Livewire\Form;

/**
 * Form tambah/ubah anggota. Aturan validasi sama dengan endpoint JSON (MemberService::rules).
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

    /** Batas bon khusus anggota ini (kosong = ikut batas bawaan jenis anggota). */
    public int|string|null $credit_limit = '';

    protected function rules(): array
    {
        return MemberService::rules($this->id);
    }

    protected function messages(): array
    {
        return MemberService::messages();
    }

    public function blank(string $type = 'siswa'): void
    {
        $this->reset();
        $this->resetValidation();
        $this->type = $type;
        $this->member_code = app(MemberCodeGenerator::class)->next($type);
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
            $this->member_code = app(MemberCodeGenerator::class)->next($this->type);
        }
    }

    public function save(): array
    {
        $data = $this->validate();
        $service = app(MemberService::class);

        $member = $this->id === null
            ? $service->create($data)
            : $service->update(Member::findOrFail($this->id), $data);

        return MemberService::toArray($member);
    }
}
