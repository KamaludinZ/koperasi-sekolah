<?php

namespace App\Livewire\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Notifikasi toast untuk aksi simpan: berhasil (hijau) atau gagal validasi (merah,
 * dengan jumlah isian yang perlu diperbaiki & fokus ke isian pertama).
 * Toast ditampilkan komponen <x-toasts> di layout lewat event browser `toast`.
 */
trait NotifiesSave
{
    /**
     * @param  callable(): void  $save
     *
     * @throws ValidationException diteruskan agar pesan per-isian tetap tampil
     */
    protected function saveWithToast(callable $save, string $success): void
    {
        try {
            $save();
        } catch (ValidationException $e) {
            $count = count($e->errors());
            $this->toast('error', $count > 1
                ? "Belum tersimpan: ada {$count} isian yang perlu diperbaiki."
                : 'Belum tersimpan: periksa isian yang ditandai merah.');
            $this->dispatch('focus-first-error');

            throw $e;
        }

        $this->toast('success', $success);
    }

    protected function toast(string $type, string $message): void
    {
        $this->dispatch('toast', type: $type, message: $message);
    }
}
