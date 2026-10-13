<?php

namespace App\Services;

use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Buka & tutup shift kasir atas satu laci kas, secara atomik dan tercatat di audit (kategori `kas`).
 * - Buka: hanya bila tidak ada shift terbuka (dijaga juga oleh indeks unik di database).
 * - Tutup: kunci baris shift, hitung kas seharusnya dari data (modal + penjualan tunai +
 *   kas masuk − kas keluar), simpan snapshot kas seharusnya, uang dihitung, dan selisih.
 *   Selisih ≠ 0 wajib disertai catatan.
 */
class ShiftService
{
    public const MAX_CASH = 50_000_000;

    /** Selisih kas (kurang/lebih) mulai nominal ini dilaporkan ke pengurus lewat notifikasi. */
    public const LARGE_DIFFERENCE = 10_000;

    /** Shift yang sedang terbuka, bila ada. */
    public function current(): ?CashShift
    {
        return CashShift::open()->with('user:id,name')->first();
    }

    public static function rules(): array
    {
        return [
            'open' => [
                'name' => ['required', 'string', 'in:'.implode(',', CashShift::NAMES)],
                'opening_cash' => ['required', 'integer', 'min:0', 'max:'.self::MAX_CASH],
            ],
            'close' => [
                'counted_cash' => ['required', 'integer', 'min:0', 'max:'.self::MAX_CASH],
                'note' => ['nullable', 'string', 'max:200'],
            ],
        ];
    }

    public static function messages(): array
    {
        return [
            'name.in' => 'Pilih shift pagi, siang, atau sore.',
            'opening_cash.required' => 'Isi modal awal uang laci (boleh Rp0).',
            'opening_cash.max' => 'Modal awal terlalu besar (maks. Rp50.000.000).',
            'counted_cash.required' => 'Hitung lalu isi jumlah uang di laci.',
            'counted_cash.max' => 'Jumlah uang terlalu besar (maks. Rp50.000.000).',
        ];
    }

    /** @throws ValidationException bila masih ada shift terbuka */
    public function open(string $name, int $openingCash, ?User $user): CashShift
    {
        try {
            $shift = DB::transaction(function () use ($name, $openingCash, $user) {
                if (CashShift::open()->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['name' => 'Masih ada shift berjalan. Tutup dulu sebelum membuka shift baru.']);
                }

                return CashShift::create([
                    'name' => $name,
                    'status' => CashShift::STATUS_OPEN,
                    'user_id' => $user?->id,
                    'opened_at' => now(),
                    'opening_cash' => $openingCash,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Dua petugas membuka bersamaan: yang kalah ditolak oleh indeks unik.
            throw ValidationException::withMessages(['name' => 'Masih ada shift berjalan. Tutup dulu sebelum membuka shift baru.']);
        }

        AuditLog::record('shift.opened', 'kas', "{$name} dibuka dengan modal Rp".number_format($openingCash, 0, ',', '.'),
            ['type' => 'cash_shift', 'id' => $shift->id, 'label' => $name.' '.$shift->opened_at->format('d/m/Y')],
            ['opening_cash' => $openingCash], $openingCash);

        return $shift->load('user:id,name');
    }

    /** @throws ValidationException bila shift sudah ditutup atau selisih tanpa catatan */
    public function close(CashShift $shift, int $countedCash, ?string $note, ?User $user): CashShift
    {
        $note = filled($note) ? trim($note) : null;

        $shift = DB::transaction(function () use ($shift, $countedCash, $note, $user) {
            /** @var CashShift $locked */
            $locked = CashShift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['counted_cash' => 'Shift ini sudah ditutup.']);
            }

            $expected = $locked->expectedCash();
            $difference = $countedCash - $expected;
            if ($difference !== 0 && $note === null) {
                throw ValidationException::withMessages(['note' => 'Ada selisih kas — tulis penyebabnya, mis. salah kembalian.']);
            }

            $locked->update([
                'status' => CashShift::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => $user?->id,
                'expected_cash' => $expected,
                'counted_cash' => $countedCash,
                'difference' => $difference,
                'close_note' => $note,
            ]);

            return $locked;
        });

        $diff = (int) $shift->difference;
        AuditLog::record('shift.closed', 'kas',
            "{$shift->name} ditutup: ".($diff === 0 ? 'kas pas' : ($diff < 0 ? 'kurang' : 'lebih').' Rp'.number_format(abs($diff), 0, ',', '.')),
            ['type' => 'cash_shift', 'id' => $shift->id, 'label' => $shift->name.' '.$shift->opened_at->format('d/m/Y')],
            ['expected_cash' => (int) $shift->expected_cash, 'counted_cash' => $countedCash, 'difference' => $diff, 'note' => $note,
                'flagged' => abs($diff) >= self::LARGE_DIFFERENCE ?: null],
            $countedCash);

        if (abs($diff) >= self::LARGE_DIFFERENCE) {
            $this->notifyLargeDifference($shift, $diff);
        }

        return $shift->load('user:id,name', 'closer:id,name');
    }

    /** Notifikasi sistem untuk pengurus (admin ikut melihat) bila selisih kas besar; sekali per shift. */
    private function notifyLargeDifference(CashShift $shift, int $diff): void
    {
        $amount = 'Rp'.number_format(abs($diff), 0, ',', '.');
        Notification::firstOrCreate(['dedupe_key' => "kas:selisih:{$shift->id}"], [
            'type' => 'sistem',
            'target' => 'pengurus',
            'title' => "Selisih kas {$shift->name} ".($diff < 0 ? 'kurang' : 'lebih')." {$amount}",
            'message' => ($shift->user?->name ?? 'Kasir').' menutup '.mb_strtolower($shift->name).' '.$shift->opened_at->translatedFormat('d M Y')
                .' dengan selisih '.($diff < 0 ? 'kurang' : 'lebih')." {$amount}".($shift->close_note ? '. Catatan: '.$shift->close_note : '.'),
            'url' => route('kas.shift', ['status' => $diff < 0 ? 'kurang' : 'lebih'], false),
        ]);
    }

    /** Bentuk JSON/tampilan satu shift, dengan angka berjalan untuk shift yang masih terbuka. */
    public static function toArray(CashShift $shift): array
    {
        $open = $shift->isOpen();
        $cashSales = $shift->cashSales();
        $in = $shift->movementTotal(CashMovement::TYPE_IN);
        $out = $shift->movementTotal(CashMovement::TYPE_OUT);

        return [
            'id' => $shift->id,
            'name' => $shift->name,
            'status' => $shift->status,
            'cashier' => $shift->user?->name,
            'closed_by' => $shift->closer?->name,
            'opened_at' => $shift->opened_at->toDateTimeString(),
            'closed_at' => $shift->closed_at?->toDateTimeString(),
            'opening_cash' => (int) $shift->opening_cash,
            'transactions' => $shift->transactions()->count(),
            'cash_sales' => $cashSales,
            'non_cash_sales' => $shift->nonCashSales(),
            'cash_in' => $in,
            'cash_out' => $out,
            'expected' => $open ? (int) $shift->opening_cash + $cashSales + $in - $out : (int) $shift->expected_cash,
            'counted' => $open ? null : (int) $shift->counted_cash,
            'difference' => $open ? null : (int) $shift->difference,
            'note' => $shift->close_note,
        ];
    }
}
