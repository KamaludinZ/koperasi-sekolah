<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Angsuran pinjaman: jadwal (ke-n, jatuh tempo, nominal) & pembayarannya. */
class LoanInstallment extends Model
{
    public const STATUSES = ['belum' => 'Belum dibayar', 'dibayar' => 'Dibayar', 'menunggak' => 'Menunggak'];

    protected $fillable = ['loan_id', 'sequence', 'due_date', 'amount', 'principal_part', 'interest_part', 'paid_amount', 'paid_at', 'payment_method', 'status', 'note', 'user_id'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'paid_at' => 'date', 'amount' => 'decimal:2', 'principal_part' => 'decimal:2', 'interest_part' => 'decimal:2', 'paid_amount' => 'decimal:2', 'sequence' => 'integer'];
    }

    /** Angsuran tanpa porsi eksplisit diisi otomatis (pembagian sama dengan generator jadwal). */
    protected static function booted(): void
    {
        static::saving(function (self $i) {
            if ($i->principal_part !== null && $i->interest_part !== null) {
                return;
            }
            $loan = $i->loan()->first(['id', 'principal', 'interest']);
            $amount = (int) round((float) $i->amount);
            if (! $loan) {
                $i->forceFill(['principal_part' => $amount, 'interest_part' => 0]);

                return;
            }
            $rows = $loan->installments()->whereKeyNot($i->id ?? 0)->get(['sequence', 'amount'])
                ->map(fn ($r) => ['sequence' => (int) $r->sequence, 'amount' => (int) round((float) $r->amount)])
                ->push(['sequence' => (int) $i->sequence, 'amount' => $amount])->sortBy('sequence')->values()->all();
            $i->forceFill(self::partsFor((int) round((float) $loan->principal), (int) round((float) $loan->interest), $rows)[(int) $i->sequence]);
        });
    }

    /**
     * Porsi pokok/bunga untuk sekumpulan angsuran satu pinjaman. Bila nominalnya persis jadwal generator, dipakai
     * pembagian generator; selain itu bunga dibagi proporsional terhadap nominal (dibulatkan ke bawah, tidak melebihi
     * nominal angsuran), sisanya pokok.
     *
     * @param  list<array{sequence: int, amount: int}>  $rows
     * @return array<int, array{principal_part: int, interest_part: int}> per ke-n
     */
    public static function partsFor(int $principal, int $interest, array $rows): array
    {
        $total = $principal + $interest;
        $tenor = count($rows);
        if ($tenor >= 1 && $tenor <= \App\Services\LoanApplicationService::MAX_TENOR && $principal > 0 && $interest >= 0) {
            $plan = \App\Services\LoanScheduleGenerator::generate($principal, $interest, $tenor, '2000-01-01');
            $matches = array_column($plan, 'amount', 'sequence') == array_column($rows, 'amount', 'sequence');
            if ($matches) {
                return collect($plan)->mapWithKeys(fn ($r) => [$r['sequence'] => ['principal_part' => $r['principal_part'], 'interest_part' => $r['interest_part']]])->all();
            }
        }

        return collect($rows)->mapWithKeys(function ($r) use ($interest, $total) {
            $int = $total > 0 ? min($r['amount'], intdiv($r['amount'] * $interest, $total)) : 0;

            return [$r['sequence'] => ['principal_part' => $r['amount'] - $int, 'interest_part' => $int]];
        })->all();
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
