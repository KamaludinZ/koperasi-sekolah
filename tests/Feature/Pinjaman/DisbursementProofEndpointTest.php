<?php

namespace Tests\Feature\Pinjaman;

use App\Models\ActivityLog;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Services\LoanDisbursementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DisbursementProofEndpointTest extends TestCase
{
    use RefreshDatabase;

    private LoanDisbursement $d;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-10-15 09:00:00');
        $loan = Loan::factory()->create(['status' => 'disetujui', 'principal' => 300000, 'interest' => 0, 'tenor_months' => 3, 'applied_at' => '2026-10-01']);
        app(LoanDisbursementService::class)->disburse($loan, ['disbursed_on' => '2026-10-15', 'amount' => 300000, 'proof' => 'Awal']);
        $this->d = LoanDisbursement::firstOrFail();
    }

    private function send(array $data)
    {
        return $this->post(route('pinjaman.pencairan.bukti.update', $this->d), $data, ['Accept' => 'application/json']);
    }

    public function test_attach_replace_and_update_note(): void
    {
        $this->send(['bukti' => UploadedFile::fake()->image('a.png')])->assertOk()
            ->assertJsonPath('data.proof', 'Awal')->assertJsonPath('data.has_file', true)->assertJsonPath('data.file_type', 'image')
            ->assertJsonPath('data.download_url', route('pinjaman.pencairan.bukti', $this->d));
        $first = $this->d->fresh()->proof_file;

        $this->send(['proof' => 'Kuitansi 021', 'bukti' => UploadedFile::fake()->createWithContent('k.pdf', "%PDF-1.4\n%%EOF")])->assertOk()
            ->assertJsonPath('data.proof', 'Kuitansi 021')->assertJsonPath('data.file_type', 'pdf');
        Storage::disk('local')->assertMissing($first);
        $this->assertSame('application/pdf', $this->d->fresh()->proof_mime);

        $this->postJson(route('pinjaman.pencairan.bukti.update', $this->d), ['proof' => 'Hanya keterangan'])->assertOk()
            ->assertJsonPath('data.proof', 'Hanya keterangan')->assertJsonPath('data.has_file', true);
        $this->assertSame(3, ActivityLog::where('event', 'pinjaman.bukti')->count());
    }

    public function test_validation(): void
    {
        $this->postJson(route('pinjaman.pencairan.bukti.update', $this->d), [])->assertUnprocessable()
            ->assertJsonPath('errors.proof.0', 'Isi keterangan atau pilih berkas bukti.');
        $this->send(['bukti' => UploadedFile::fake()->createWithContent('x.php.png', 'x')])->assertUnprocessable()->assertJsonValidationErrors('bukti');
        $this->send(['proof' => str_repeat('a', 201)])->assertUnprocessable()->assertJsonValidationErrors('proof');
        $this->assertSame([], Storage::disk('local')->allFiles('pinjaman/bukti'));
        $this->postJson('/pinjaman/pencairan/999999/bukti', ['proof' => 'x'])->assertNotFound();
    }

    public function test_access(): void
    {
        $this->loginAs('kasir');
        $this->postJson(route('pinjaman.pencairan.bukti.update', $this->d), ['proof' => 'Diubah kasir'])->assertForbidden();
        $this->assertSame('Awal', $this->d->fresh()->proof);
    }
}
