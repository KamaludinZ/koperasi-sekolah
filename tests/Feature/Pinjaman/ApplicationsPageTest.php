<?php

namespace Tests\Feature\Pinjaman;

use App\Livewire\Pinjaman\Applications;
use App\Models\Loan;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ApplicationsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabs_list_applications_by_stage(): void
    {
        $budi = Member::factory()->teacher()->create(['name' => 'Pak Budi']);
        Loan::factory()->create(['code' => 'PJM-A1', 'member_id' => $budi->id, 'principal' => 2000000, 'interest' => 100000, 'tenor_months' => 10, 'purpose' => 'Renovasi rumah']);
        Loan::factory()->create(['code' => 'PJM-A2', 'status' => 'disetujui', 'decision_note' => 'Memenuhi syarat']);
        Loan::factory()->create(['code' => 'PJM-A3', 'status' => 'ditolak', 'decision_note' => 'Masih ada pinjaman berjalan']);
        Loan::factory()->running()->create(['code' => 'PJM-A4']);

        $this->get('/pinjaman/pengajuan')->assertOk()->assertSee('Pengajuan Pinjaman')
            ->assertSee('PJM-A1')->assertSee('Pak Budi')->assertSee('Rp2.000.000')->assertSee('Rp210.000')->assertSee('Renovasi rumah')
            ->assertDontSee('PJM-A2')->assertDontSee('PJM-A4');

        Livewire::test(Applications::class)->assertSet('tab', 'diajukan')
            ->call('setTab', 'disetujui')->assertSee('PJM-A2')->assertSee('Memenuhi syarat')->assertDontSee('PJM-A1')
            ->call('setTab', 'ditolak')->assertSee('PJM-A3')->assertSee('Masih ada pinjaman berjalan')
            ->call('setTab', 'dibatalkan')->assertSee('Tidak ada pengajuan dibatalkan')
            ->call('setTab', 'berjalan')->assertSet('tab', 'diajukan'); // tab tak dikenal kembali ke bawaan

        $this->get('/pinjaman/pengajuan?tahap=ditolak')->assertSee('PJM-A3')->assertDontSee('PJM-A1');
        $this->get('/pinjaman/pengajuan?tahap=lunas')->assertOk()->assertSee('PJM-A1'); // nilai URL aneh tidak error
    }

    public function test_counts_search_and_nav(): void
    {
        Loan::factory()->count(2)->create();
        $rina = Member::factory()->teacher()->create(['name' => 'Bu Rina']);
        Loan::factory()->create(['code' => 'PJM-R1', 'member_id' => $rina->id]);

        Livewire::test(Applications::class)->assertSeeHtml('data-tab="diajukan"')
            ->assertSet('counts.diajukan', 3)
            ->set('search', 'Bu Rina')->assertSee('PJM-R1')->assertSeeHtml('data-application="PJM-R1"')
            ->set('search', 'zzz-tidak-ada')->assertSee('Tidak ada pengajuan yang cocok');

        $this->get('/pinjaman')->assertSee(route('pinjaman.pengajuan'), false); // tab navigasi muncul
    }

    public function test_access(): void
    {
        $this->loginAs('pengurus');
        $this->get('/pinjaman/pengajuan')->assertOk();
        $this->loginAs('kasir');
        $this->get('/pinjaman/pengajuan')->assertForbidden();
    }
}
