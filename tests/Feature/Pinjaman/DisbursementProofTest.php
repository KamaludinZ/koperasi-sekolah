<?php

namespace Tests\Feature\Pinjaman;

use App\Livewire\Pinjaman\Show;
use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Services\LoanDisbursementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DisbursementProofTest extends TestCase
{
    use RefreshDatabase;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-10-14 10:00:00');
        $this->loan = Loan::factory()->create(['code' => 'PJM-2026-0001', 'status' => 'disetujui', 'principal' => 600000, 'interest' => 0,
            'tenor_months' => 3, 'applied_at' => '2026-10-01']);
    }

    private function pdf(string $body = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF"): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('kuitansi.pdf', $body);
    }

    public function test_disburse_with_photo_proof_and_download(): void
    {
        Livewire::test(Show::class, ['loan' => $this->loan])->call('openDisburse')
            ->set('proof', 'Kuitansi 017')->set('proofFile', UploadedFile::fake()->image('serah-terima.jpg', 800, 600))
            ->call('disburse')->assertHasNoErrors()->assertSeeHtml('data-proof-download');

        $d = LoanDisbursement::firstOrFail();
        $this->assertMatchesRegularExpression('#^pinjaman/bukti/[A-Za-z0-9]{40}\.jpg$#', $d->proof_file);
        Storage::disk('local')->assertExists($d->proof_file);
        $this->assertStringNotContainsString('serah-terima', $d->proof_file); // nama asli tidak dipakai

        $res = $this->get(route('pinjaman.pencairan.bukti', $d))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment; filename=bukti-pencairan-PJM-2026-0001.jpg', $res->headers->get('Content-Disposition'));
        $this->assertTrue(ActivityLog::where('event', 'pinjaman.dicairkan')->first()->properties['has_file']);
    }

    public function test_pdf_proof_and_rejected_files(): void
    {
        $c = Livewire::test(Show::class, ['loan' => $this->loan])->call('openDisburse');
        foreach ([
            UploadedFile::fake()->createWithContent('bukti.php.jpg', 'x'),                               // ekstensi ganda berbahaya
            UploadedFile::fake()->createWithContent('bukti.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
            UploadedFile::fake()->createWithContent('palsu.jpg', 'bukan gambar'),
            $this->pdf("%PDF-1.4\n<< /OpenAction << /S /JavaScript /JS (app.alert(1)) >> >>"),          // PDF berskrip
            UploadedFile::fake()->createWithContent('bukan.pdf', 'MZ executable'),
            UploadedFile::fake()->create('besar.pdf', 5000, 'application/pdf'),
        ] as $bad) {
            $c->set('proofFile', $bad)->call('disburse')->assertHasErrors('proofFile');
        }
        $this->assertSame('disetujui', $this->loan->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles('pinjaman/bukti'));

        $c->set('proofFile', $this->pdf())->call('disburse')->assertHasNoErrors();
        $this->assertStringEndsWith('.pdf', LoanDisbursement::first()->proof_file);
    }

    public function test_attach_and_replace_proof_later(): void
    {
        app(LoanDisbursementService::class)->disburse($this->loan, ['disbursed_on' => '2026-10-14', 'amount' => 600000]);
        $d = LoanDisbursement::firstOrFail();
        $this->get(route('pinjaman.pencairan.bukti', $d))->assertNotFound();

        $c = Livewire::test(Show::class, ['loan' => $this->loan])->assertSee('Lampirkan bukti')
            ->call('openAttach', $d->id)->assertSeeHtml('data-attach-form')
            ->set('attachProof', 'Transfer BRI 14/10')->set('attachFile', UploadedFile::fake()->image('a.png'))->call('saveAttach')
            ->assertHasNoErrors()->assertSee('Bukti pencairan diperbarui.')->assertSee('Ganti bukti');
        $first = $d->fresh()->proof_file;
        Storage::disk('local')->assertExists($first);

        $c->call('openAttach', $d->id)->set('attachFile', $this->pdf())->call('saveAttach')->assertHasNoErrors();
        Storage::disk('local')->assertMissing($first); // berkas lama dihapus
        Storage::disk('local')->assertExists($d->fresh()->proof_file);
        $this->assertSame(2, ActivityLog::where('event', 'pinjaman.bukti')->count());

        // Pencairan pinjaman lain tidak bisa disentuh dari halaman ini.
        $other = Loan::factory()->create(['status' => 'disetujui', 'principal' => 100000, 'tenor_months' => 1]);
        app(LoanDisbursementService::class)->disburse($other, ['disbursed_on' => '2026-10-14', 'amount' => 100000]);
        $c->call('openAttach', LoanDisbursement::where('loan_id', $other->id)->value('id'))->assertSet('attachingId', null);
    }

    public function test_download_guards(): void
    {
        $d = LoanDisbursement::create(['loan_id' => $this->loan->id, 'disbursed_on' => '2026-10-14', 'amount' => 600000, 'proof_file' => '../../.env']);
        $this->get(route('pinjaman.pencairan.bukti', $d))->assertNotFound();
        $d->update(['proof_file' => 'pinjaman/bukti/../../x.jpg']);
        $this->get(route('pinjaman.pencairan.bukti', $d))->assertNotFound();

        Storage::disk('local')->put('pinjaman/bukti/ok.jpg', 'x');
        $d->update(['proof_file' => 'pinjaman/bukti/ok.jpg']);
        $this->get(route('pinjaman.pencairan.bukti', $d))->assertOk();
        auth()->logout();
        $this->get(route('pinjaman.pencairan.bukti', $d))->assertRedirect(route('login'));
        $this->getJson(route('pinjaman.pencairan.bukti', $d))->assertUnauthorized();
        $this->loginAs('kasir');
        $this->get(route('pinjaman.pencairan.bukti', $d))->assertForbidden();
        Livewire::test(Show::class, ['loan' => $this->loan])->call('openAttach', $d->id)->assertForbidden();
    }
}
