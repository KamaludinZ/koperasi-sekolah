<?php

namespace App\Livewire\Concerns;

use App\Services\AuditLog;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

/**
 * Form pengaturan koperasi: hanya petugas dengan izin `ubah-pengaturan` (Admin)
 * yang boleh menyimpan. Peran lain yang diberi menu Pengaturan melihat form
 * dalam mode baca saja; percobaan menyimpan ditolak, diberi toast, dan dicatat di audit.
 */
trait GuardsSettings
{
    #[Computed]
    public function canEdit(): bool
    {
        return Gate::allows('ubah-pengaturan');
    }

    /** true bila boleh lanjut menyimpan; false (dan toast + audit) bila ditolak. */
    protected function ensureCanEdit(string $section): bool
    {
        if (Gate::allows('ubah-pengaturan')) {
            return true;
        }

        AuditLog::record('setting.denied', 'pengaturan', "Percobaan mengubah pengaturan ({$section}) ditolak: bukan Admin",
            null, ['section' => $section]);
        $this->toast('error', 'Hanya Admin yang dapat mengubah pengaturan koperasi.');

        return false;
    }
}
