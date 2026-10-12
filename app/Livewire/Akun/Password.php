<?php

namespace App\Livewire\Akun;

use App\Services\AuthService;
use App\Services\PasswordService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Ganti kata sandi akun yang sedang masuk (semua peran), lewat PasswordService:
 * sandi lama wajib benar, sandi baru kuat & berbeda; perangkat lain otomatis dikeluarkan.
 */
#[Layout('components.layouts.app')]
#[Title('Ganti Kata Sandi')]
class Password extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $saved = false;

    public int $revoked = 0;

    public function save(PasswordService $passwords): void
    {
        $this->saved = false;
        $this->resetValidation();

        try {
            $this->revoked = $passwords->change(auth()->user(), $this->only('current_password', 'password', 'password_confirmation'));
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
            $this->reset('current_password');

            return;
        }

        $this->reset('current_password', 'password', 'password_confirmation');
        $this->saved = true;
    }

    public function render()
    {
        return view('livewire.akun.password', ['user' => auth()->user() ? AuthService::toArray(auth()->user()) : null]);
    }
}
