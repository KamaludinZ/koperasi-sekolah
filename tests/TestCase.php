<?php

namespace Tests;

use App\Models\Member;
use App\Models\Product;
use App\Support\MockData;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Peran akun tiruan yang otomatis "masuk" di setiap test (halaman petugas wajib masuk).
     * Set null di test yang menguji kondisi belum masuk.
     */
    protected ?string $mockRole = 'admin';

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->mockRole) {
            session(['mock.user' => collect(MockData::users())->firstWhere('role', $this->mockRole)]);
        }
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
