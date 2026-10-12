<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Ganti kata sandi petugas sendiri: kata sandi lama wajib benar; yang baru min. 8 karakter,
 * berisi huruf & angka, berbeda dari yang lama, dan diulang dengan benar. Setelah berhasil,
 * sesi di perangkat lain diakhiri dan kejadian dicatat di audit.
 */
class PasswordService
{
    public static function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:100', 'regex:/[A-Za-z]/', 'regex:/\d/', 'different:current_password', 'confirmed'],
        ];
    }

    public static function messages(): array
    {
        return [
            'current_password.required' => 'Masukkan kata sandi yang sekarang.',
            'password.required' => 'Masukkan kata sandi baru.',
            'password.min' => 'Minimal 8 karakter.',
            'password.regex' => 'Gunakan gabungan huruf dan angka.',
            'password.different' => 'Kata sandi baru harus berbeda dari yang sekarang.',
            'password.confirmed' => 'Ulangan kata sandi baru tidak sama.',
        ];
    }

    /**
     * @param  array{current_password?: string, password?: string, password_confirmation?: string}  $input
     * @return int jumlah sesi lain yang diakhiri
     *
     * @throws ValidationException
     */
    public function change(User $user, array $input): int
    {
        $data = Validator::make($input, self::rules(), self::messages())->validate();

        if (! Hash::check($data['current_password'], $user->password)) {
            AuditLog::record('user.password_change_failed', 'pengguna', "{$user->name} gagal mengganti kata sandi (kata sandi lama salah)",
                ['type' => 'user', 'id' => $user->id, 'label' => "{$user->name} · {$user->email}"]);

            throw ValidationException::withMessages(['current_password' => 'Kata sandi yang sekarang salah.']);
        }

        $user->forceFill(['password' => $data['password'], 'password_changed_at' => now()])->save();

        // Keluarkan perangkat lain: "remember me" lama tidak berlaku & sesi lain dihapus.
        if (Auth::id() === $user->id) {
            Auth::logoutOtherDevices($data['password']);
        }
        $revoked = DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', session()->getId())->delete();

        AuditLog::record('user.password_changed', 'pengguna', "{$user->name} mengganti kata sandinya sendiri",
            ['type' => 'user', 'id' => $user->id, 'label' => "{$user->name} · {$user->email}"], ['other_sessions_ended' => $revoked]);

        return $revoked;
    }
}
