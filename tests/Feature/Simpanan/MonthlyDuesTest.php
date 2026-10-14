<?php

namespace Tests\Feature\Simpanan;

use App\Livewire\Simpanan\MonthlyDues;
use App\Models\ActivityLog;
use App\Models\Member;
use App\Models\MemberSavingEntry;
use App\Models\SavingDue;
use App\Models\SavingType;
use App\Services\MemberSavingService;
use App\Services\SavingDueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class MonthlyDuesTest extends TestCase
{
    use RefreshDatabase;

    private Member $pakDedi;

    private Member $buSiti;

    private SavingType $wajib;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 09:00:00');
        $this->pakDedi = Member::factory()->teacher()->create(['name' => 'Pak Dedi', 'joined_at' => '2026-01-10']);
        $this->buSiti = Member::factory()->teacher()->create(['name' => 'Bu Siti', 'joined_at' => '2026-01-10']);
        Member::factory()->create(['name' => 'Andi Siswa']);
        Member::factory()->teacher()->create(['name' => 'Guru Baru', 'joined_at' => '2026-11-01']);
        $this->wajib = SavingType::where('code', 'WAJIB')->first();
    }

    public function test_sheet_lists_active_teachers_only(): void
    {
        $this->get('/simpanan/setoran-bulanan')->assertOk()->assertSee('Pak Dedi')->assertSee('Bu Siti')->assertDontSee('Andi Siswa')->assertDontSee('Guru Baru');
        $rows = app(SavingDueService::class)->sheet('2026-10', $this->wajib);
        $this->assertSame([10000, 'kosong'], [$rows[0]['amount'], $rows[0]['status']]);
    }

    public function test_record_then_post_increases_balance(): void
    {
        $c = Livewire::test(MonthlyDues::class)->assertSet('period', '2026-10')->assertSet('typeId', $this->wajib->id)
            ->set("amounts.{$this->pakDedi->id}", 25000)->call('selectOpen')->call('save');
        $this->assertSame(2, SavingDue::where('status', 'belum')->count());
        $this->assertSame(25000, (int) SavingDue::where('member_id', $this->pakDedi->id)->value('amount'));
        $c->assertSee('Dicatat, belum diposting');

        $c->set('selected', [$this->pakDedi->id])->call('postSelected')->assertSee('Sudah disetor');
        $entry = MemberSavingEntry::where('member_id', $this->pakDedi->id)->firstOrFail();
        $this->assertSame(['2026-10', 25000, 'saving_due'], [$entry->period, (int) $entry->amount, $entry->source_type]);
        $this->assertSame('disetor', SavingDue::where('member_id', $this->pakDedi->id)->value('status'));
        $this->assertSame(1, ActivityLog::where('event', 'simpanan.setoran_bulanan')->count());

        $recap = app(SavingDueService::class)->recap($this->wajib);
        $this->assertSame(['2026-10', 2, 1, 25000], [$recap[0]['period'], $recap[0]['recorded'], $recap[0]['posted'], $recap[0]['posted_amount']]);
    }

    public function test_already_paid_month_is_skipped_and_students_rejected(): void
    {
        app(MemberSavingService::class)->depositForPeriod($this->buSiti, $this->wajib, 10000, '2026-10');
        $svc = app(SavingDueService::class);
        $this->assertTrue(collect($svc->sheet('2026-10', $this->wajib))->firstWhere('member_id', $this->buSiti->id)['paid_elsewhere']);

        $svc->record('2026-10', $this->wajib, [$this->buSiti->id => 10000]);
        $r = $svc->post('2026-10', $this->wajib, [$this->buSiti->id]);
        $this->assertSame(0, $r['posted']);
        $this->assertStringContainsString('sudah disetor', $r['skipped'][0]);

        $this->expectException(ValidationException::class);
        $svc->record('2026-10', $this->wajib, [Member::where('name', 'Andi Siswa')->value('id') => 10000]);
    }

    public function test_validation_and_access(): void
    {
        $svc = app(SavingDueService::class);
        foreach (['2026-13', '2026-11'] as $bad) {
            try {
                $svc->record($bad, $this->wajib, [$this->pakDedi->id => 10000]);
                $this->fail("{$bad} harus ditolak");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('period', $e->errors());
            }
        }
        Livewire::test(MonthlyDues::class)->set("amounts.{$this->pakDedi->id}", 500)->set('selected', [$this->pakDedi->id])->call('save')->assertHasErrors('amounts');

        $due = SavingDue::create(['member_id' => $this->pakDedi->id, 'saving_type_id' => $this->wajib->id, 'period' => '2026-09', 'amount' => 10000]);
        Livewire::withQueryParams(['bulan' => '2026-09'])->test(MonthlyDues::class)->call('discard', $due->id);
        $this->assertNull(SavingDue::find($due->id));

        $this->loginAs('kasir');
        $this->get('/simpanan/setoran-bulanan')->assertForbidden();
    }
}
