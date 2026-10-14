<?php

namespace App\Services;

use App\Models\Loan;
use App\Support\Rupiah;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pencairan pinjaman yang sudah disetujui: catat tanggal, nominal & bukti serah terima, lalu bentuk jadwal
 * angsuran otomatis dari pokok + bunga dan tenor. Semuanya dalam satu transaksi dengan baris pinjaman dikunci.
 */
class LoanDisbursementService
{
    public static function rules(Loan $loan): array
    {
        return [
            'disbursed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.$loan->applied_at->toDateString()],
            'amount' => ['required', 'integer', 'in:'.(int) round((float) $loan->principal)],
            'proof' => ['nullable', 'string', 'max:200'],
        ];
    }

    public static function messages(Loan $loan): array
    {
        return [
            'disbursed_on.required' => 'Isi tanggal pencairan.',
            'disbursed_on.date_format' => 'Tanggal pencairan tidak valid.',
            'disbursed_on.before_or_equal' => 'Tanggal pencairan tidak boleh di masa depan.',
            'disbursed_on.after_or_equal' => 'Tanggal pencairan tidak boleh sebelum tanggal pengajuan ('.\App\Support\Tanggal::format($loan->applied_at).').',
            'amount.required' => 'Isi nominal yang diserahkan.',
            'amount.integer' => 'Nominal harus berupa angka.',
            'amount.in' => 'Nominal pencairan harus sama dengan pokok yang disetujui ('.Rupiah::format($loan->principal).'). Ubah pengajuan bila nominalnya berbeda.',
            'proof.max' => 'Keterangan bukti maksimal 200 karakter.',
        ];
    }

    /**
     * Jadwal angsuran (lihat LoanScheduleGenerator) tanpa rincian porsi pokok/bunga.
     *
     * @return list<array{sequence: int, due_date: string, amount: int}>
     */
    public static function schedule(int $total, int $tenor, Carbon|string $disbursedOn, int $interest = 0): array
    {
        return array_map(fn ($r) => ['sequence' => $r['sequence'], 'due_date' => $r['due_date'], 'amount' => $r['amount']],
            LoanScheduleGenerator::generate($total - $interest, $interest, $tenor, $disbursedOn));
    }

    public const PROOF_DIR = 'pinjaman/bukti';

    /**
     * Simpan berkas bukti di disk lokal (privat) dengan nama acak.
     *
     * @return array{proof_file: string, proof_mime: string, proof_size: int, proof_sha256: string}
     */
    public static function storeProof(\Illuminate\Http\UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension()) === 'jpeg' ? 'jpg' : strtolower($file->getClientOriginalExtension());
        $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'][$ext] ?? 'application/octet-stream';

        return ['proof_file' => $file->storeAs(self::PROOF_DIR, \Illuminate\Support\Str::random(40).'.'.$ext, 'local'),
            'proof_mime' => $mime, 'proof_size' => max(1, (int) $file->getSize()), 'proof_sha256' => hash_file('sha256', $file->getRealPath())];
    }

    /** Lampirkan/ganti berkas bukti & keterangan pada pencairan yang sudah tercatat; berkas lama dihapus. */
    /** @param  array{proof_file: string, proof_mime: string, proof_size: int, proof_sha256: string}|null  $file  hasil storeProof() */
    public function attachProof(\App\Models\LoanDisbursement $disbursement, ?string $proof, ?array $file): \App\Models\LoanDisbursement
    {
        $proof = filled($proof) ? trim($proof) : null;
        $old = ['proof' => $disbursement->proof, 'proof_sha256' => $disbursement->proof_sha256, 'proof_mime' => $disbursement->proof_mime];
        $oldFile = $disbursement->proof_file;
        $path = $file['proof_file'] ?? null;
        $loan = $disbursement->loan()->with('member')->firstOrFail();

        DB::transaction(function () use ($disbursement, $proof, $file, $loan, $old) {
            $disbursement->update(['proof' => $proof] + ($file ?? []));
            AuditLog::record('pinjaman.bukti', 'pinjaman', "Bukti pencairan {$loan->code} {$loan->member->name} diperbarui".($proof ? ": {$proof}" : ''),
                LoanApplicationService::subject($loan), ['disbursement_id' => $disbursement->id, 'file_replaced' => $file !== null], action: 'diubah',
                old: $old, new: ['proof' => $proof, 'proof_sha256' => $disbursement->proof_sha256, 'proof_mime' => $disbursement->proof_mime]);
        });
        if ($path && $oldFile && $oldFile !== $path) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($oldFile);
        }

        return $disbursement;
    }

    /** @param  array  $data  data tervalidasi (rules()) */
    /** @param  array{proof_file: string, proof_mime: string, proof_size: int, proof_sha256: string}|null  $file  hasil storeProof() */
    public function disburse(Loan $loan, array $data, ?int $userId = null, ?array $file = null): Loan
    {
        $proof = filled($data['proof'] ?? null) ? trim($data['proof']) : null;

        $loan = DB::transaction(function () use ($loan, $data, $proof, $userId, $file) {
            $locked = Loan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'disetujui') {
                throw ValidationException::withMessages(['loan' => "Pinjaman {$locked->code} berstatus ".mb_strtolower(Loan::STATUSES[$locked->status] ?? $locked->status)
                    .'; hanya pengajuan yang sudah disetujui yang bisa dicairkan.']);
            }
            if ((int) $data['amount'] !== (int) round((float) $locked->principal)) {
                throw ValidationException::withMessages(['amount' => 'Nominal pencairan harus sama dengan pokok yang disetujui.']);
            }

            $locked->disbursements()->create(['disbursed_on' => $data['disbursed_on'], 'amount' => (int) $data['amount'],
                'proof' => $proof, 'user_id' => $userId] + ($file ?? []));
            $schedule = LoanScheduleGenerator::generate((int) round((float) $locked->principal), (int) round((float) $locked->interest), $locked->tenor_months, $data['disbursed_on']);
            foreach ($schedule as $row) {
                $locked->installments()->create(['sequence' => $row['sequence'], 'due_date' => $row['due_date'], 'amount' => $row['amount'], 'status' => 'belum', 'paid_amount' => 0]);
            }
            $locked->update(['status' => 'berjalan', 'disbursed_at' => $data['disbursed_on'], 'outstanding' => $locked->totalDue()]);
            $locked->load('member');

            // Jejak audit ditulis dalam transaksi yang sama: pencairan & catatannya tersimpan bersama.
            AuditLog::record('pinjaman.dicairkan', 'pinjaman',
                "Pencairan {$locked->code} {$locked->member->name}: ".Rupiah::format($data['amount']).' pada '.\App\Support\Tanggal::format($data['disbursed_on'])
                    .", {$locked->tenor_months} angsuran terbentuk".($proof ? " · {$proof}" : ''),
                LoanApplicationService::subject($locked),
                ['member_id' => $locked->member_id, 'installments' => $locked->tenor_months, 'has_file' => $file !== null,
                    'proof' => $proof, 'proof_sha256' => $file['proof_sha256'] ?? null, 'proof_mime' => $file['proof_mime'] ?? null,
                    'first_due' => $schedule[0]['due_date'], 'last_due' => end($schedule)['due_date'],
                    'monthly' => $schedule[0]['amount'], 'interest' => (int) round((float) $locked->interest)],
                (int) $data['amount'], action: 'diubah',
                old: ['status' => 'disetujui'], new: ['status' => 'berjalan', 'disbursed_at' => $data['disbursed_on'], 'outstanding' => $locked->totalDue()]);

            return $locked;
        });

        return $loan;
    }
}
