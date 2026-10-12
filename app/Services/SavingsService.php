<?php

namespace App\Services;

use App\Models\Member;
use App\Models\SavingsTransaction;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Setor & tarik tabungan anggota secara atomik: kunci baris anggota → validasi
 * (anggota aktif, nominal, saldo cukup) → catat mutasi dengan saldo sebelum/sesudah
 * → perbarui saldo anggota. Satu-satunya jalan untuk mengubah `members.balance`.
 */
class SavingsService
{
    public const MIN_AMOUNT = 1_000;

    public const MAX_DEPOSIT = 10_000_000;

    public const HISTORY_PRESETS = ['30-hari' => '30 hari', '90-hari' => '3 bulan', 'tahun-ini' => 'Tahun ini', 'semua' => 'Semua'];

    public const PER_PAGE_OPTIONS = [20, 50, 100];

    /** Aturan dasar nominal & catatan (batas saldo dicek saat menyimpan). */
    public static function rules(): array
    {
        return [
            'type' => ['required', 'in:'.implode(',', array_keys(SavingsTransaction::TYPES))],
            'amount' => ['required', 'integer', 'min:'.self::MIN_AMOUNT, 'max:'.self::MAX_DEPOSIT],
            'note' => ['nullable', 'string', 'max:150'],
        ];
    }

    public static function messages(): array
    {
        return [
            'type.required' => 'Pilih setor atau tarik.',
            'type.in' => 'Jenis mutasi tidak dikenal.',
            'amount.required' => 'Masukkan jumlah uang.',
            'amount.integer' => 'Jumlah harus angka bulat.',
            'amount.min' => 'Minimal Rp'.number_format(self::MIN_AMOUNT, 0, ',', '.').'.',
            'amount.max' => 'Maksimal Rp'.number_format(self::MAX_DEPOSIT, 0, ',', '.').' per transaksi.',
            'note.max' => 'Catatan maksimal :max karakter.',
        ];
    }

    public function deposit(Member $member, int $amount, ?string $note = null, ?int $userId = null): SavingsTransaction
    {
        return $this->record($member, SavingsTransaction::TYPE_DEPOSIT, $amount, $note, $userId);
    }

    public function withdraw(Member $member, int $amount, ?string $note = null, ?int $userId = null): SavingsTransaction
    {
        return $this->record($member, SavingsTransaction::TYPE_WITHDRAWAL, $amount, $note, $userId);
    }

    /**
     * @throws ValidationException bila anggota nonaktif, nominal di luar batas, atau saldo kurang
     */
    public function record(Member $member, string $type, int $amount, ?string $note = null, ?int $userId = null): SavingsTransaction
    {
        if (! array_key_exists($type, SavingsTransaction::TYPES)) {
            throw ValidationException::withMessages(['type' => 'Jenis mutasi tidak dikenal.']);
        }
        if ($amount < self::MIN_AMOUNT || $amount > self::MAX_DEPOSIT) {
            throw ValidationException::withMessages(['amount' => $amount < self::MIN_AMOUNT
                ? self::messages()['amount.min']
                : self::messages()['amount.max']]);
        }

        $entry = DB::transaction(function () use ($member, $type, $amount, $note, $userId) {
            // Kunci baris anggota agar dua penarikan bersamaan tidak membuat saldo minus.
            $locked = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();

            if (! $locked->is_active) {
                throw ValidationException::withMessages(['member' => 'Anggota nonaktif tidak bisa setor/tarik.']);
            }

            $before = (int) round((float) $locked->balance);
            if ($type === SavingsTransaction::TYPE_WITHDRAWAL && $amount > $before) {
                throw ValidationException::withMessages([
                    'amount' => 'Penarikan melebihi saldo (Rp'.number_format($before, 0, ',', '.').').',
                ]);
            }
            $after = $type === SavingsTransaction::TYPE_DEPOSIT ? $before + $amount : $before - $amount;

            $entry = $locked->savings()->create([
                'type' => $type,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'note' => filled($note) ? trim($note) : ($type === SavingsTransaction::TYPE_DEPOSIT ? 'Setoran tabungan' : 'Penarikan tabungan'),
                'user_id' => $userId,
            ]);

            $locked->forceFill(['balance' => $after])->save();

            AuditLog::record($type === SavingsTransaction::TYPE_DEPOSIT ? 'savings.deposit' : 'savings.withdraw', 'tabungan',
                ($type === SavingsTransaction::TYPE_DEPOSIT ? 'Setoran' : 'Penarikan').' tabungan '.$locked->name.': Rp'.number_format($amount, 0, ',', '.')
                    .' (saldo Rp'.number_format($after, 0, ',', '.').')',
                ['type' => 'member', 'id' => $locked->id, 'label' => "{$locked->member_code} · {$locked->name}"],
                ['savings_id' => $entry->id, 'balance_before' => $before, 'balance_after' => $after, 'note' => $entry->note],
                $amount);

            return $entry;
        });

        $member->refresh();

        return $entry;
    }

    /**
     * Rentang riwayat untuk preset atau tanggal kustom (Y-m-d); kustom diutamakan.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public static function historyRange(string $preset = 'semua', ?string $from = null, ?string $to = null): array
    {
        if (($from ?? '') !== '' || ($to ?? '') !== '') {
            return [
                ($from ?? '') !== '' ? Carbon::createFromFormat('!Y-m-d', $from)->startOfDay() : null,
                ($to ?? '') !== '' ? Carbon::createFromFormat('!Y-m-d', $to)->endOfDay() : null,
            ];
        }

        return match ($preset) {
            '30-hari' => [now()->subDays(29)->startOfDay(), null],
            '90-hari' => [now()->subDays(89)->startOfDay(), null],
            'tahun-ini' => [now()->startOfYear(), null],
            default => [null, null],
        };
    }

    /** Ringkasan seluruh tabungan anggota: total & jumlah setor/tarik, mutasi terakhir. */
    public function stats(Member $member): array
    {
        $totals = $member->savings()->toBase()
            ->groupBy('type')
            ->selectRaw('type, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total')
            ->get()->keyBy('type');
        $last = $member->savings()->latestFirst()->first(['type', 'created_at']);

        return [
            'balance' => (int) round((float) $member->balance),
            'deposit_total' => (int) round((float) ($totals['setor']->total ?? 0)),
            'deposit_count' => (int) ($totals['setor']->n ?? 0),
            'withdraw_total' => (int) round((float) ($totals['tarik']->total ?? 0)),
            'withdraw_count' => (int) ($totals['tarik']->n ?? 0),
            'last_at' => $last?->created_at->toDateTimeString(),
            'last_type' => $last?->type,
        ];
    }

    /**
     * Riwayat mutasi terbaru dulu (berpaginasi) + total masuk/keluar pada filter itu.
     *
     * @param  array{from?: ?Carbon, to?: ?Carbon, type?: ?string}  $filters
     * @return array{paginator: LengthAwarePaginator, in: int, out: int}
     */
    public function history(Member $member, array $filters = [], int $perPage = 20, ?int $page = null): array
    {
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::PER_PAGE_OPTIONS[0];
        $query = fn () => $member->savings()
            ->betweenDates($filters['from'] ?? null, $filters['to'] ?? null)
            ->ofType($filters['type'] ?? null);

        $paginator = $query()->with('user:id,name')->latestFirst()->paginate($perPage, ['*'], 'page', $page);
        if ($paginator->isEmpty() && $paginator->currentPage() > 1 && $paginator->total() > 0) {
            $paginator = $query()->with('user:id,name')->latestFirst()->paginate($perPage, ['*'], 'page', $paginator->lastPage());
        }

        $sums = $query()->toBase()->groupBy('type')->selectRaw('type, COALESCE(SUM(amount), 0) AS total')->pluck('total', 'type');

        return [
            'paginator' => $paginator->through(fn (SavingsTransaction $s) => self::toArray($s)),
            'in' => (int) round((float) ($sums['setor'] ?? 0)),
            'out' => (int) round((float) ($sums['tarik'] ?? 0)),
        ];
    }

    /** Bentuk array mutasi (sama dengan data tiruan halaman profil anggota). */
    public static function toArray(SavingsTransaction $s): array
    {
        return [
            'id' => $s->id,
            'member_id' => $s->member_id,
            'type' => $s->type,
            'type_label' => $s->type_label,
            'amount' => (int) round((float) $s->amount),
            'balance_before' => (int) round((float) $s->balance_before),
            'balance_after' => (int) round((float) $s->balance_after),
            'note' => $s->note,
            'user' => $s->user?->name ?? 'Petugas',
            'created_at' => $s->created_at->toDateTimeString(),
        ];
    }
}
