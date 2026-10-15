<?php

namespace App\Models;

use App\Services\AuditLog;
use App\Services\LoanApplicationService;
use App\Services\LoanScheduleGenerator;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Angsuran pinjaman: jadwal (ke-n, jatuh tempo, nominal) & pembayarannya. */
class LoanInstallment extends Model
{
    public const STATUSES = ['belum' => 'Belum dibayar', 'dibayar' => 'Dibayar', 'menunggak' => 'Menunggak'];

    protected $fillable = ['loan_id', 'sequence', 'due_date', 'amount', 'principal_part', 'interest_part', 'paid_amount', 'paid_at', 'payment_method', 'status', 'note', 'user_id'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'paid_at' => 'date', 'amount' => 'decimal:2', 'principal_part' => 'decimal:2', 'interest_part' => 'decimal:2', 'paid_amount' => 'decimal:2', 'sequence' => 'integer'];
    }

    /** Konteks riwayat yang sedang berlaku: event, loan_payment_id, reason, user_id. */
    private static ?array $historyContext = null;

    private static bool $historySuppressed = false;

    /** Jalankan $fn dengan konteks riwayat (mis. pembayaran mana yang menyebabkan perubahan). */
    public static function withHistoryContext(array $context, callable $fn): mixed
    {
        $prev = self::$historyContext;
        self::$historyContext = $context;
        try {
            return $fn();
        } finally {
            self::$historyContext = $prev;
        }
    }

    /** Jalankan $fn tanpa mencatat riwayat per simpan (pemanggil mencatat selisih bersihnya sendiri, mis. koreksi). */
    public static function withoutHistory(callable $fn): mixed
    {
        $prev = self::$historySuppressed;
        self::$historySuppressed = true;
        try {
            return $fn();
        } finally {
            self::$historySuppressed = $prev;
        }
    }

    /** Catat satu baris riwayat untuk angsuran ini bila ada kolom yang berubah. */
    public function logChange(string $event, array $changes, array $context = []): ?LoanInstallmentChange
    {
        if ($changes === []) {
            return null;
        }
        $userId = Actor::userId($context['user_id'] ?? auth()->id()); // admin pelaku selama impersonasi

        return LoanInstallmentChange::create([
            'loan_id' => $this->loan_id, 'loan_installment_id' => $this->id, 'sequence' => $this->sequence, 'event' => $event,
            'changes' => $changes, 'loan_payment_id' => $context['loan_payment_id'] ?? null, 'reason' => $context['reason'] ?? null,
            'user_id' => $userId, 'actor_name' => Actor::name($userId ? User::whereKey($userId)->value('name') : null),
        ]);
    }

    /** Snapshot kolom yang dilacak (nilai mentah sebelum perubahan). */
    public function trackedOriginal(): array
    {
        return array_intersect_key($this->getRawOriginal(), array_flip(LoanInstallmentChange::TRACKED));
    }

    public function trackedAttributes(): array
    {
        return array_intersect_key($this->getAttributes(), array_flip(LoanInstallmentChange::TRACKED));
    }

    /** Angsuran tanpa porsi eksplisit diisi otomatis (pembagian sama dengan generator jadwal). */
    protected static function booted(): void
    {
        static::updated(function (self $i) {
            if (self::$historySuppressed) {
                return;
            }
            $changes = LoanInstallmentChange::diff($i->trackedOriginal(), $i->trackedAttributes());
            if ($changes === []) {
                return;
            }
            $ctx = self::$historyContext ?? [];
            $keys = array_keys($changes);
            $event = match (true) {
                array_intersect($keys, ['due_date', 'amount', 'principal_part', 'interest_part']) !== [] => 'jadwal',
                $keys === ['status'] => 'status',
                default => $ctx['event'] ?? 'pembayaran',
            };
            $i->logChange($event, $changes, $ctx);
            if ($event === 'jadwal') { // jadwal (jatuh tempo/nominal/porsi) jarang berubah — selalu masuk jejak audit
                $loan = $i->loan()->first();
                AuditLog::record('pinjaman.jadwal_diubah', 'pinjaman', "Jadwal angsuran ke-{$i->sequence} {$loan?->code} diubah",
                    $loan ? LoanApplicationService::subject($loan) : null, ['installment_id' => $i->id], action: 'diubah',
                    old: array_map(fn ($c) => $c['from'], $changes), new: array_map(fn ($c) => $c['to'], $changes), reason: $ctx['reason'] ?? null);
            }
        });

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
        if ($tenor >= 1 && $tenor <= LoanApplicationService::MAX_TENOR && $principal > 0 && $interest >= 0) {
            $plan = LoanScheduleGenerator::generate($principal, $interest, $tenor, '2000-01-01');
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

    /** Riwayat perubahan angsuran ini, terlama dulu. */
    public function history(): HasMany
    {
        return $this->hasMany(LoanInstallmentChange::class)->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
