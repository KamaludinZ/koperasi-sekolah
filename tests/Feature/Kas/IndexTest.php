<?php

namespace Tests\Feature\Kas;

use App\Livewire\Kas\Index;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\User;
use Database\Seeders\CashShiftSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\TransactionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $loginAs = 'kasir';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 12:00:00'); // Rabu, shift siang sedang berjalan
        $this->seed([ProductSeeder::class, TransactionSeeder::class, CashShiftSeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Shift siang hari ini (seeder) dijadikan milik kasir yang masuk. */
    private function ownRunningShift(): CashShift
    {
        $shift = CashShift::open()->sole();
        $shift->update(['user_id' => auth()->id()]);

        return $shift;
    }

    public function test_page_shows_running_shift_and_summary_today(): void
    {
        $this->get('/kas')
            ->assertOk()
            ->assertSee('Kas Harian')
            ->assertSee('Shift sedang berjalan')
            ->assertSee('Shift siang')
            ->assertSee('Kas seharusnya di laci')
            ->assertSee('7 hari terakhir')
            ->assertDontSee('Tahap pengembangan');
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

    public function test_close_running_shift_requires_note_when_cash_differs(): void
    {
        $shift = $this->ownRunningShift();
        $expected = $shift->expectedCash();

        $c = Livewire::test(Index::class)
            ->call('openDialog', 'tutup')->assertSet('dialog', 'tutup')
            ->assertSee('Kas seharusnya')
            ->call('closeShift')->assertHasErrors(['countedCash' => 'required'])
            ->set('countedCash', $expected - 2000)
            ->call('closeShift')->assertHasErrors('closeNote')
            ->set('closeNote', 'Salah kembalian')
            ->call('closeShift')->assertHasNoErrors()
            ->assertSet('dialog', null)
            ->assertDispatched('toast', type: 'success');

        $shift->refresh();
        $this->assertSame(['closed', -2000, 'Salah kembalian'], [$shift->status, (int) $shift->difference, $shift->close_note]);
        $c->assertSee('Semua shift sudah ditutup')->assertSee('Buka shift baru');
    }

    public function test_exact_count_then_open_new_shift(): void
    {
        $shift = $this->ownRunningShift();

        Livewire::test(Index::class)
            ->call('openDialog', 'tutup')
            ->set('countedCash', $shift->expectedCash())
            ->call('closeShift')->assertHasNoErrors()
            ->call('openDialog', 'buka')->assertSet('shiftName', 'Shift siang')
            ->set('openingCash', '')->call('openShift')->assertHasErrors('openingCash')
            ->set('shiftName', 'Shift sore')->set('openingCash', 150000)
            ->call('openShift')->assertHasNoErrors()
            ->assertSee('Shift sore')
            ->assertSee('Shift sedang berjalan');

        $new = CashShift::open()->sole();
        $this->assertSame(['Shift sore', 150000, auth()->id()], [$new->name, (int) $new->opening_cash, $new->user_id]);
    }

    public function test_cannot_open_second_shift_while_one_is_running(): void
    {
        Livewire::test(Index::class)
            ->set('dialog', 'buka')->set('openingCash', 100000)
            ->call('openShift')->assertHasErrors('shiftName');
        $this->assertSame(1, CashShift::open()->count());
    }

    public function test_record_cash_in_and_out_updates_expected_cash(): void
    {
        $shift = $this->ownRunningShift();
        $before = $shift->expectedCash();

        Livewire::test(Index::class)
            ->call('openDialog', 'kas')->assertSet('dialog', 'kas')->assertSee('Uang masuk laci')
            ->call('saveMovement')->assertHasErrors(['movementAmount' => 'required', 'movementReason' => 'required'])
            ->call('pickReason', 'Tambah uang kembalian')->assertSet('movementCategory', 'kembalian')
            ->set('movementAmount', 50000)
            ->call('saveMovement')->assertHasNoErrors()->assertSet('dialog', null)
            ->assertDispatched('toast', type: 'success', message: 'Setoran kas Rp50.000 dicatat.')
            ->call('openDialog', 'kas')->call('setMovementType', 'out')->assertSet('movementCategory', 'bendahara')
            ->set('movementAmount', $before + 50000 + 500)->set('movementReason', 'Setor ke bendahara')
            ->call('saveMovement')->assertHasErrors('movementAmount')
            ->set('movementAmount', 20000)
            ->call('saveMovement')->assertHasNoErrors()
            ->assertSee('Setor ke bendahara');

        $this->assertSame($before + 30000, $shift->fresh()->expectedCash());
        $last = CashMovement::latest('id')->first();
        $this->assertSame(['out', 'bendahara', auth()->id()], [$last->type, $last->category, $last->user_id]);
    }

    public function test_other_cashiers_shift_cannot_be_handled(): void
    {
        $other = User::factory()->create(['role_id' => auth()->user()->role_id]);
        CashShift::open()->sole()->update(['user_id' => $other->id]);

        Livewire::test(Index::class)
            ->assertDontSee('Tutup shift')
            ->call('openDialog', 'tutup')->assertSet('dialog', null)->assertDispatched('toast', type: 'error')
            ->call('closeShift')->assertForbidden();
    }

    public function test_shift_left_open_from_yesterday_is_flagged(): void
    {
        CashShift::open()->sole()->update(['opened_at' => '2026-10-13 10:35:00']);

        $this->get('/kas')->assertOk()->assertSee('belum ditutup');
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

    public function test_menu_access_per_role(): void
    {
        $this->get('/kas')->assertOk();
        $this->loginAs('pengurus');
        $this->get('/kas')->assertOk();
        $this->get('/kasir')->assertForbidden();
    }
}
