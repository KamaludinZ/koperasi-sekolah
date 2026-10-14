<?php

namespace Tests\Feature\Services;

use App\Support\Tanggal;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TanggalTest extends TestCase
{
    use RefreshDatabase;

    public function test_formats_indonesian_dates_and_blanks(): void
    {
        $this->assertSame('5 Mei 2026', Tanggal::format('2026-05-05'));
        $this->assertSame('17 Agt 2026 08.30', Tanggal::format(Carbon::parse('2026-08-17 08:30:00'), true));
        $this->assertSame('—', Tanggal::format(null));
        $this->assertSame('—', Tanggal::format(''));
        $this->assertSame('—', Tanggal::format('bukan-tanggal'));
    }

    public function test_blade_directive(): void
    {
        $this->assertSame('1 Jan 2026', trim($this->blade("@tanggal('2026-01-01')")->__toString()));
    }
}
