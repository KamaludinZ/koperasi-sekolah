<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call(ProductSeeder::class);

        // Anggota & riwayat penjualan contoh hanya untuk lingkungan pengembangan.
        if (app()->environment('local')) {
            $this->call([MemberSeeder::class, TransactionSeeder::class]);
        }
    }
}
