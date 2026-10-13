<?php

namespace Tests\Feature\Kas;

use App\Livewire\Kas\Index;
use App\Support\MockData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 12:00:00'); // Rabu, shift siang sedang berjalan
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_page_shows_running_shift_and_summary_today(): void
    {
        $this->get('/kas')
            ->assertOk()
            ->assertSee('Kas Harian')
            ->assertSee('Shift sedang berjalan')
            ->assertSee('Shift siang')
            ->assertSee('Kas seharusnya di laci')
            ->assertSee('7 hari terakhir');
    }

    public function test_cash_day_figures_are_consistent(): void
    {
        $day = MockData::cashDay('2026-10-13');

        $this->assertSame($day, MockData::cashDay('2026-10-13')); // deterministik
        foreach ($day['shifts'] as $shift) {
            $this->assertSame('closed', $shift['status']);
            $this->assertSame($shift['opening_cash'] + $shift['cash_sales'] + $shift['cash_in'] - $shift['cash_out'], $shift['expected']);
            $this->assertSame($shift['counted'] - $shift['expected'], $shift['difference']);
        }
        $this->assertSame(array_sum(array_column($day['shifts'], 'difference')), $day['summary']['difference']);
        $this->assertSame($day['sales']['tunai'], $day['summary']['cash_sales']);
    }

    public function test_sunday_and_future_have_no_shift(): void
    {
        $this->assertTrue(MockData::cashDay('2026-10-11')['closed_day']);
        $this->assertSame([], MockData::cashDay('2026-10-11')['shifts']);
        $this->assertSame([], MockData::cashDay('2026-10-20')['shifts']);
    }

    public function test_navigate_dates_but_not_into_the_future(): void
    {
        Livewire::test(Index::class)
            ->assertSet('date', '')
            ->call('shiftDay', -1)->assertSet('date', '2026-10-13')
            ->assertSee(Carbon::parse('2026-10-13')->translatedFormat('l, d F Y'))
            ->assertSee('Semua shift sudah ditutup')
            ->call('shiftDay', 1)->assertSet('date', '')
            ->call('shiftDay', 1)->assertSet('date', '')
            ->set('date', '2026-12-01')->assertSet('date', '')
            ->set('date', 'bukan-tanggal')->assertSet('date', '')
            ->set('date', '2026-10-11')->assertSee('Koperasi tutup')
            ->call('goToday')->assertSet('date', '');
    }

    public function test_query_string_selects_date(): void
    {
        $this->get('/kas?tanggal=2026-10-12')->assertOk()->assertSee(Carbon::parse('2026-10-12')->translatedFormat('l, d F Y'));
    }

    public function test_menu_access_per_role(): void
    {
        $this->loginAs('kasir');
        $this->get('/kas')->assertOk();
        $this->loginAs('pengurus');
        $this->get('/kas')->assertOk();
        $this->get('/kasir')->assertForbidden();
    }
}
