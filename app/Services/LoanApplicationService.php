<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\Member;
use App\Support\Rupiah;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Pengajuan pinjaman anggota yang dicatat pengurus: validasi, kode otomatis, dan jejak audit. */
class LoanApplicationService
{
    public const MIN_PRINCIPAL = 10_000;

    public const MAX_PRINCIPAL = 100_000_000;

    public const MAX_TENOR = 120;

    public static function rules(): array
    {
        return [
            'member_id' => ['required', 'integer', Rule::exists('members', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'principal' => ['required', 'integer', 'min:'.self::MIN_PRINCIPAL, 'max:'.self::MAX_PRINCIPAL],
            'interest' => ['required', 'integer', 'min:0', 'lte:principal'],
            'tenor_months' => ['required', 'integer', 'min:1', 'max:'.self::MAX_TENOR],
            'payment_method' => ['required', Rule::in(array_keys(Loan::METHODS))],
            'purpose' => ['nullable', 'string', 'max:200'],
            'applied_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.now()->subYear()->toDateString()],
        ];
    }

    public static function messages(): array
    {
        return [
            'member_id.required' => 'Pilih anggota yang mengajukan.',
            'member_id.exists' => 'Anggota tidak ditemukan atau sudah nonaktif.',
            'principal.required' => 'Isi nominal pinjaman.',
            'principal.integer' => 'Nominal harus berupa angka.',
            'principal.min' => 'Nominal minimal '.Rupiah::format(self::MIN_PRINCIPAL).'.',
            'principal.max' => 'Nominal maksimal '.Rupiah::format(self::MAX_PRINCIPAL).'.',
            'interest.required' => 'Isi nominal bunga (0 bila tanpa bunga).',
            'interest.integer' => 'Bunga harus berupa angka.',
            'interest.min' => 'Bunga tidak boleh negatif.',
            'interest.lte' => 'Bunga tidak boleh melebihi nominal pinjaman.',
            'tenor_months.required' => 'Isi lama tenor.',
            'tenor_months.integer' => 'Tenor harus berupa angka bulan.',
            'tenor_months.min' => 'Tenor minimal 1 bulan.',
            'tenor_months.max' => 'Tenor maksimal '.self::MAX_TENOR.' bulan.',
            'payment_method.required' => 'Pilih metode bayar angsuran.',
            'payment_method.in' => 'Metode bayar tidak dikenal.',
            'purpose.max' => 'Keperluan maksimal 200 karakter.',
            'applied_at.required' => 'Isi tanggal pengajuan.',
            'applied_at.date_format' => 'Tanggal pengajuan tidak valid.',
            'applied_at.before_or_equal' => 'Tanggal pengajuan tidak boleh di masa depan.',
            'applied_at.after_or_equal' => 'Tanggal pengajuan paling lama setahun lalu.',
        ];
    }

    /** Ringkasan pinjaman anggota yang masih berjalan/menunggu (untuk peringatan di form). */
    public function memberContext(Member $member): array
    {
        $loans = $member->loans()->whereIn('status', ['diajukan', 'disetujui', ...Loan::ACTIVE])->orderBy('id')->get();

        return [
            'open_count' => $loans->count(),
            'active_outstanding' => (int) round((float) $loans->whereIn('status', Loan::ACTIVE)->sum(fn ($l) => (float) $l->outstanding)),
            'has_overdue' => $loans->contains('status', 'menunggak'),
            'codes' => $loans->pluck('code')->all(),
        ];
    }

    /** @param  array  $data  data tervalidasi (rules()) */
    public function create(array $data, ?int $userId = null): Loan
    {
        $purpose = filled($data['purpose'] ?? null) ? trim($data['purpose']) : null;

        for ($attempt = 1; ; $attempt++) {
            try {
                $loan = DB::transaction(function () use ($data, $purpose, $userId) {
                    $member = Member::whereKey($data['member_id'])->lockForUpdate()->firstOrFail();

                    return Loan::create([
                        'code' => Loan::nextCode(\Illuminate\Support\Carbon::parse($data['applied_at'])),
                        'member_id' => $member->id,
                        'principal' => (int) $data['principal'],
                        'interest' => (int) $data['interest'],
                        'tenor_months' => (int) $data['tenor_months'],
                        'payment_method' => $data['payment_method'],
                        'status' => 'diajukan',
                        'purpose' => $purpose,
                        'outstanding' => 0,
                        'applied_at' => $data['applied_at'],
                        'created_by' => $userId,
                    ]);
                });
                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }

        $loan->load('member');
        AuditLog::record('pinjaman.diajukan', 'pinjaman',
            "Pengajuan pinjaman {$loan->code} {$loan->member->name}: ".Rupiah::format($data['principal'])
                .' + bunga '.Rupiah::format($data['interest']).", {$loan->tenor_months} bulan",
            self::subject($loan),
            ['member_id' => $loan->member_id, 'payment_method' => $loan->payment_method],
            (int) $data['principal'], action: 'dibuat',
            new: ['principal' => (int) $data['principal'], 'interest' => (int) $data['interest'], 'tenor_months' => (int) $data['tenor_months'],
                'payment_method' => $loan->payment_method, 'purpose' => $purpose, 'applied_at' => $data['applied_at']]);

        return $loan;
    }

    /**
     * Putuskan pengajuan: `disetujui` (catatan opsional) atau `ditolak` (alasan wajib). Hanya pengajuan berstatus
     * `diajukan`; baris dikunci agar dua pengurus tidak memutuskan bersamaan.
     */
    public function decide(Loan $loan, string $decision, ?string $note, ?int $userId = null): Loan
    {
        $note = filled($note) ? trim($note) : null;
        if (! in_array($decision, ['disetujui', 'ditolak'], true)) {
            throw ValidationException::withMessages(['decision' => 'Keputusan tidak dikenal.']);
        }
        if ($decision === 'ditolak' && mb_strlen((string) $note) < 5) {
            throw ValidationException::withMessages(['note' => 'Tulis alasan penolakan (minimal 5 karakter).']);
        }
        if (mb_strlen((string) $note) > 200) {
            throw ValidationException::withMessages(['note' => 'Catatan maksimal 200 karakter.']);
        }

        $loan = DB::transaction(function () use ($loan, $decision, $note, $userId) {
            $locked = Loan::whereKey($loan->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'diajukan') {
                throw ValidationException::withMessages(['decision' => "Pengajuan {$locked->code} sudah ".mb_strtolower(Loan::STATUSES[$locked->status] ?? $locked->status).'; muat ulang halaman.']);
            }
            $locked->update(['status' => $decision, 'decision_note' => $note, 'decided_at' => now(), 'decided_by' => $userId]);

            return $locked;
        });

        $loan->load('member');
        AuditLog::record("pinjaman.{$decision}", 'pinjaman',
            'Pengajuan '.$loan->code.' '.$loan->member->name.' '.($decision === 'disetujui' ? 'disetujui' : 'ditolak').($note ? ": {$note}" : ''),
            self::subject($loan), ['member_id' => $loan->member_id], (int) round((float) $loan->principal), action: 'diubah',
            old: ['status' => 'diajukan'], new: ['status' => $decision, 'decision_note' => $note], reason: $note);

        return $loan;
    }

    public static function subject(Loan $loan): array
    {
        return ['type' => 'loan', 'id' => $loan->id, 'label' => "{$loan->code} · ".($loan->member?->name ?? '—')];
    }
}
