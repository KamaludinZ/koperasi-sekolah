<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Buat (atau aktifkan ulang) akun admin pertama di server, mis. setelah deploy:
 *   php artisan koperasi:buat-admin --name="Admin Koperasi" --email=admin@madrasah.sch.id
 * Kata sandi ditanyakan secara tersembunyi (tidak tersimpan di riwayat shell).
 */
class CreateAdmin extends Command
{
    protected $signature = 'koperasi:buat-admin {--name= : Nama admin} {--email= : Email untuk masuk}';

    protected $description = 'Buat akun admin koperasi (atau jadikan akun yang ada sebagai admin aktif)';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Nama admin', 'Admin Koperasi');
        $email = mb_strtolower(trim($this->option('email') ?: $this->ask('Email untuk masuk')));
        $password = $this->secret('Kata sandi (min. 8 karakter, huruf & angka)');
        $confirm = $this->secret('Ulangi kata sandi');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirm],
            [
                'name' => ['required', 'string', 'max:100'],
                'email' => ['required', 'email', 'max:150'],
                'password' => ['required', 'string', 'min:8', 'regex:/[A-Za-z]/', 'regex:/\d/', 'confirmed'],
            ],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::updateOrCreate(['email' => $email], [
            'name' => $name,
            'password' => $password,
            'role_id' => Role::named(Role::ADMIN)->id,
            'is_active' => true,
            'password_changed_at' => now(),
        ]);

        $this->info(($user->wasRecentlyCreated ? 'Akun admin dibuat: ' : 'Akun diperbarui menjadi admin aktif: ').$user->email);

        return self::SUCCESS;
    }
}
