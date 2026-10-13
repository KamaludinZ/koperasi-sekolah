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

    public function test_close_running_shift_requires_note_when_cash_differs(): void
    {
        $this->loginAs('kasir');
        $shift = collect(MockData::cashDay('2026-10-14')['shifts'])->firstWhere('status', 'open');

        $c = Livewire::test(Index::class)
            ->call('openDialog', 'tutup')->assertSet('dialog', 'tutup')
            ->assertSee('Kas seharusnya')
            ->call('closeShift')->assertHasErrors(['countedCash' => 'required'])
            ->set('countedCash', $shift['expected'] - 2000)
            ->call('closeShift')->assertHasErrors('closeNote')
            ->set('closeNote', 'Salah kembalian')
            ->call('closeShift')->assertHasNoErrors()
            ->assertSet('dialog', null)
            ->assertDispatched('toast', type: 'success');

        $closed = collect(MockData::cashDay('2026-10-14')['shifts'])->firstWhere('id', $shift['id']);
        $this->assertSame('closed', $closed['status']);
        $this->assertSame(-2000, $closed['difference']);
        $this->assertSame('Salah kembalian', $closed['note']);
        $c->assertSee('Semua shift sudah ditutup')->assertSee('Buka shift baru');
    }

    public function test_exact_count_needs_no_note_then_open_new_shift(): void
    {
        $shift = collect(MockData::cashDay('2026-10-14')['shifts'])->firstWhere('status', 'open');

        Livewire::test(Index::class)
            ->call('openDialog', 'tutup')
            ->set('countedCash', $shift['expected'])
            ->call('closeShift')->assertHasNoErrors()
            ->call('openDialog', 'buka')->assertSet('shiftName', 'Shift siang')
            ->set('openingCash', '')->call('openShift')->assertHasErrors('openingCash')
            ->set('shiftName', 'Shift sore')->set('openingCash', 150000)
            ->call('openShift')->assertHasNoErrors()
            ->assertSee('Shift sore')
            ->assertSee('Shift sedang berjalan');

        $new = collect(MockData::cashDay('2026-10-14')['shifts'])->last();
        $this->assertSame(['Shift sore', 'open', 150000, 150000], [$new['name'], $new['status'], $new['opening_cash'], $new['expected']]);
        $this->assertSame(auth()->user()->name, $new['cashier']);
    }

    public function test_cannot_open_second_shift_while_one_is_running(): void
    {
        Livewire::test(Index::class)
            ->set('dialog', 'buka')->set('openingCash', 100000)
            ->call('openShift')->assertHasErrors('shiftName');
    }

    public function test_pengurus_only_monitors(): void
    {
        $this->loginAs('pengurus');

        Livewire::test(Index::class)
            ->assertDontSee('Tutup shift')
            ->call('openDialog', 'tutup')->assertSet('dialog', null)
            ->call('closeShift')->assertForbidden();
    }

    public function test_past_days_have_no_shift_actions(): void
    {
        Livewire::test(Index::class)
            ->set('date', '2026-10-13')
            ->call('openDialog', 'buka')->assertSet('dialog', null)
            ->assertDontSee('Buka shift baru');
    }
}
