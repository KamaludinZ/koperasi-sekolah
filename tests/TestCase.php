<?php

namespace Tests;

use App\Models\Member;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Peran akun demo (UserSeeder) yang otomatis masuk di setiap test, karena halaman
     * petugas wajib masuk. Set null di test yang menguji kondisi belum masuk.
     */
    protected ?string $loginAs = 'admin';

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->loginAs) {
            $this->loginAs($this->loginAs);
        }
    }

    /** Masuk sebagai akun demo peran tertentu (admin, kasir, pengurus) atau email tertentu. */
    /** Masuk portal sebagai anggota tanpa mengganti guard bawaan (sama seperti middleware `portal`). */
    protected function actingAsMember(\App\Models\Member $member): static
    {
        if (blank($member->getAttributes()['password'] ?? null)) {
            $member->forceFill(['password' => 'rahasia123'])->save(); // akses portal butuh kata sandi
        }
        if (! $member->hasPin()) {
            $member->forceFill(['pin' => '123456'])->save(); // PIN transaksi bawaan tes
        }
        $this->app['auth']->guard('member')->setUser($member);

        return $this;
    }

    protected function loginAs(string $roleOrEmail): User
    {
        $this->seed(UserSeeder::class);
        $email = str_contains($roleOrEmail, '@')
            ? $roleOrEmail
            : collect(UserSeeder::ACCOUNTS)->firstWhere('role', $roleOrEmail)['email'];
        $user = User::with('role')->firstWhere('email', $email);
        $this->actingAs($user);

        return $user;
    }

    /**
     * Id barang berdasarkan kode. Jangan menulis id langsung di test: di PostgreSQL
     * sequence tidak ikut di-rollback antar test, jadi id hasil seeder bisa berbeda.
     */
    protected function pid(string $code): int
    {
        return Product::where('code', $code)->valueOrFail('id');
    }

    /** Id anggota berdasarkan kode (alasan sama dengan pid). */
    protected function mid(string $code): int
    {
        return Member::where('member_code', $code)->valueOrFail('id');
    }
}
