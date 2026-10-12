<?php

namespace Tests;

use App\Models\Product;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Id barang berdasarkan kode. Jangan menulis id langsung di test: di PostgreSQL
     * sequence tidak ikut di-rollback antar test, jadi id hasil seeder bisa berbeda.
     */
    protected function pid(string $code): int
    {
        return Product::where('code', $code)->valueOrFail('id');
    }
}
