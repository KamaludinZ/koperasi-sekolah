<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Akun petugas demo untuk pengembangan (kata sandi: "password").
 * Jangan dijalankan di produksi — gunakan `php artisan koperasi:buat-admin`.
 */
class UserSeeder extends Seeder
{
    public const ACCOUNTS = [
        ['name' => 'Admin Koperasi', 'email' => 'admin@koperasi.test', 'role' => Role::ADMIN, 'is_active' => true],
        ['name' => 'Bu Rina', 'email' => 'kasir@koperasi.test', 'role' => Role::KASIR, 'is_active' => true],
        ['name' => 'Pak Dedi', 'email' => 'pengurus@koperasi.test', 'role' => Role::PENGURUS, 'is_active' => true],
        ['name' => 'Pak Galih', 'email' => 'nonaktif@koperasi.test', 'role' => Role::KASIR, 'is_active' => false],
    ];

    public function run(): void
    {
        foreach (self::ACCOUNTS as $a) {
            User::firstOrCreate(['email' => $a['email']], [
                'name' => $a['name'],
                'password' => 'password',
                'role_id' => Role::named($a['role'])->id,
                'is_active' => $a['is_active'],
                'email_verified_at' => now(),
            ]);
        }
    }
}
