<?php

namespace App\Livewire\Akun;

use App\Support\MockData;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Ganti kata sandi akun yang sedang masuk (semua peran).
 * Syarat: kata sandi lama benar, kata sandi baru min. 8 karakter berisi huruf & angka,
 * berbeda dari yang lama, dan diulang dengan benar.
 * Tahap frontend: memperbarui akun tiruan di session.
 */
#[Layout('components.layouts.app')]
#[Title('Ganti Kata Sandi')]
class Password extends Component
{
    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $saved = false;

    public function mount(): void
    {
        if (! MockData::currentUser()) {
            session()->put('url.intended', route('akun.password'));
            $this->redirectRoute('login');
        }
    }

    public function save(): void
    {
        $this->saved = false;
        $user = MockData::currentUser();
        abort_unless($user, 401);

        $this->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:100', 'regex:/[A-Za-z]/', 'regex:/\d/', 'different:current_password', 'confirmed'],
        ], [
            'current_password.required' => 'Masukkan kata sandi yang sekarang.',
            'password.required' => 'Masukkan kata sandi baru.',
            'password.min' => 'Minimal 8 karakter.',
            'password.regex' => 'Gunakan gabungan huruf dan angka.',
            'password.different' => 'Kata sandi baru harus berbeda dari yang sekarang.',
            'password.confirmed' => 'Ulangan kata sandi baru tidak sama.',
        ]);

        if (! MockData::attempt($user['email'], $this->current_password)) {
            $this->addError('current_password', 'Kata sandi yang sekarang salah.');

            return;
        }

        MockData::saveUser(['id' => $user['id'], 'password_hash' => Hash::make($this->password), 'password_changed_at' => now()->toDateTimeString()]);

        $this->reset('current_password', 'password', 'password_confirmation');
        $this->saved = true;
    }

    public function render()
    {
        return view('livewire.akun.password', ['user' => MockData::currentUser()]);
    }
}
