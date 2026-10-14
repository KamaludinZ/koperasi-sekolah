<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberSavingEntry;
use App\Models\SavingDue;
use App\Models\SavingType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Setoran simpanan bulanan anggota guru/karyawan (hasil potong gaji). Pengurus mencatat nominal per
 * periode (status `belum`), lalu memposting → saldo jenis simpanan bertambah lewat mutasi setor dengan
 * periode itu (status `disetor`). Tidak berlaku untuk siswa; sistem tidak menghitung penggajian.
 */
class SavingDueService
{
    public function __construct(private MemberSavingService $savings) {}

    /** Jenis simpanan yang bisa dipakai setoran bulanan: aktif & berperiode bulanan. */
    public static function types()
    {
        return SavingType::active()->where('period', 'bulanan')->ordered()->get();
    }

    public static function validPeriod(string $period): bool
    {
        return (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period);
    }

    /**
     * Lembar setoran satu periode: semua anggota guru aktif (yang bergabung paling lambat bulan itu) dengan
     * nominal tercatat/bawaan dan statusnya. `paid_elsewhere` = bulan itu sudah disetor lewat setoran biasa.
     *
     * @return list<array{member_id: int, code: string, name: string, due_id: ?int, amount: int, status: string, posted_at: ?string, paid_elsewhere: bool, note: ?string}>
     */
    public function sheet(string $period, SavingType $type, string $search = ''): array
    {
        $end = Carbon::parse($period.'-01')->endOfMonth();
        $term = mb_strtolower(trim($search));
        $members = Member::active()->where('type', 'guru')
            ->where(fn ($q) => $q->whereNull('joined_at')->orWhere('joined_at', '<=', $end->toDateString()))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw('LOWER(name) LIKE ?', ["%{$term}%"])->orWhereRaw('LOWER(member_code) LIKE ?', ["%{$term}%"])))
            ->orderByRaw('LOWER(name)')->get();
        $dues = SavingDue::where('period', $period)->where('saving_type_id', $type->id)->whereIn('member_id', $members->pluck('id'))->get()->keyBy('member_id');
        $paid = MemberSavingService::paidPeriodRows($members->pluck('id')->all(), $type->id, [$period])->pluck('member_id')->flip();
        $default = (int) round((float) $type->amount);

        return $members->map(function (Member $m) use ($dues, $paid, $default) {
            $d = $dues->get($m->id);

            return [
                'member_id' => $m->id, 'code' => $m->member_code, 'name' => $m->name,
                'due_id' => $d?->id, 'amount' => $d ? (int) round((float) $d->amount) : $default,
                'status' => $d?->status ?? ($paid->has($m->id) ? 'disetor' : 'kosong'),
                'posted_at' => $d?->posted_at?->toDateTimeString(),
                'paid_elsewhere' => ! $d && $paid->has($m->id), 'note' => $d?->note,
            ];
        })->values()->all();
    }

    /**
     * Catat/ubah nominal setoran (status `belum`); baris yang sudah disetor tidak diubah.
     *
     * @param  array<int, int>  $amounts  member_id → nominal
     * @return int jumlah baris tercatat
     *
     * @throws ValidationException
     */
    public function record(string $period, SavingType $type, array $amounts, ?User $by = null): int
    {
        $this->assertTypeAndPeriod($type, $period);
        $members = Member::whereKey(array_keys($amounts))->get()->keyBy('id');
        $n = 0;
        DB::transaction(function () use ($period, $type, $amounts, $members, $by, &$n) {
            foreach ($amounts as $memberId => $amount) {
                $m = $members->get($memberId);
                if (! $m || $m->type !== 'guru') {
                    throw ValidationException::withMessages(['amounts' => 'Setoran potong gaji hanya untuk anggota guru/karyawan.']);
                }
                $amount = (int) $amount;
                if ($amount < MemberSavingService::MIN_AMOUNT || $amount > MemberSavingService::MAX_AMOUNT) {
                    throw ValidationException::withMessages(['amounts' => "Nominal {$m->name} tidak valid (minimal ".\App\Support\Rupiah::format(MemberSavingService::MIN_AMOUNT).').']);
                }
                $due = SavingDue::firstOrNew(['member_id' => $m->id, 'saving_type_id' => $type->id, 'period' => $period]);
                if ($due->status === 'disetor') {
                    continue;
                }
                $due->fill(['amount' => $amount, 'status' => 'belum', 'recorded_by' => $by?->id])->save();
                $n++;
            }
        });

        return $n;
    }

    /**
     * Posting setoran `belum` → saldo bertambah (mutasi setor periode tsb.). Bulan yang sudah disetor lewat
     * jalan lain dilewati. Satu ringkasan dicatat di audit.
     *
     * @param  list<int>  $memberIds
     * @return array{posted: int, amount: int, skipped: list<string>}
     */
    public function post(string $period, SavingType $type, array $memberIds, ?User $by = null): array
    {
        $this->assertTypeAndPeriod($type, $period);
        $dues = SavingDue::with('member')->where('period', $period)->where('saving_type_id', $type->id)->where('status', 'belum')
            ->whereIn('member_id', array_map('intval', $memberIds))->orderBy('id')->get();

        return DB::transaction(function () use ($dues, $type, $period, $by) {
            $posted = 0;
            $total = 0;
            $skipped = [];
            foreach ($dues as $due) {
                $due = SavingDue::whereKey($due->id)->lockForUpdate()->first();
                if ($due->status !== 'belum') {
                    continue;
                }
                $member = $due->member;
                if ($member->type !== 'guru') {
                    $skipped[] = "{$member->name} (bukan anggota guru/karyawan)"; // jenis anggota berubah setelah dicatat

                    continue;
                }
                if ($this->savings->isPaid($member, $type, $period)) {
                    $skipped[] = "{$member->name} (bulan ini sudah disetor)";

                    continue;
                }
                try {
                    $entry = $this->savings->depositForPeriod($member, $type, (int) round((float) $due->amount), $period,
                        "{$type->name} ".Carbon::parse($period.'-01')->translatedFormat('F Y').' (potong gaji)', $by?->id, ['saving_due', $due->id]);
                } catch (ValidationException $e) {
                    $skipped[] = "{$member->name} (".collect($e->errors())->flatten()->first().')';

                    continue;
                }
                $due->forceFill(['status' => 'disetor', 'posted_at' => now(), 'entry_id' => $entry->id])->save();
                $posted++;
                $total += (int) $entry->amount;
            }
            if ($posted) {
                AuditLog::record('simpanan.setoran_bulanan', 'simpanan',
                    "Posting setoran bulanan {$type->name} ".Carbon::parse($period.'-01')->translatedFormat('F Y').": {$posted} anggota, ".\App\Support\Rupiah::format($total),
                    null, ['period' => $period, 'saving_type_id' => $type->id, 'posted' => $posted, 'skipped' => $skipped], $total);
            }

            return ['posted' => $posted, 'amount' => $total, 'skipped' => $skipped];
        });
    }

    /**
     * Koreksi setoran: yang belum diposting cukup diubah nominalnya; yang sudah diposting dikoreksi lewat
     * mutasi selisih pada bulan yang sama. Nominal baru 0 = batalkan posting (catatan kembali `belum`).
     *
     * @throws ValidationException
     */
    public function correct(SavingDue $due, int $newAmount, string $reason, ?User $by = null): SavingDue
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 150) {
            throw ValidationException::withMessages(['reason' => 'Tulis alasan koreksi (5–150 karakter).']);
        }
        if ($newAmount < 0 || $newAmount > MemberSavingService::MAX_AMOUNT || ($newAmount > 0 && $newAmount < MemberSavingService::MIN_AMOUNT)) {
            throw ValidationException::withMessages(['amount' => 'Nominal baru tidak valid (0 untuk membatalkan posting).']);
        }

        return DB::transaction(function () use ($due, $newAmount, $reason, $by) {
            $due = SavingDue::with(['member', 'savingType'])->whereKey($due->id)->lockForUpdate()->firstOrFail();
            $old = (int) round((float) $due->amount);
            if ($due->status !== 'disetor') {
                if ($newAmount === 0) {
                    throw ValidationException::withMessages(['amount' => 'Setoran belum diposting; hapus catatannya saja.']);
                }
                $due->update(['amount' => $newAmount, 'note' => mb_substr($reason, 0, 200)]);
                $this->logCorrection($due, 'draf', $old, $newAmount, 0, $reason, null, $by);

                return $due;
            }
            $delta = ($newAmount === 0 ? 0 : $newAmount) - $old;
            $entry = $delta !== 0
                ? $this->savings->correctPeriod($due->member, $due->savingType, $due->period, $delta, $reason, $by?->id, ['saving_due', $due->id])
                : null;
            $this->logCorrection($due, $newAmount === 0 ? 'batal' : 'ubah', $old, $newAmount, $delta, $reason, $entry?->id, $by);
            $due->forceFill($newAmount === 0
                ? ['status' => 'belum', 'posted_at' => null, 'entry_id' => null, 'note' => mb_substr('Posting dibatalkan: '.$reason, 0, 200)]
                : ['amount' => $newAmount, 'note' => mb_substr('Dikoreksi dari '.\App\Support\Rupiah::format($old).': '.$reason, 0, 200)])->save();

            return $due->refresh();
        });
    }

    private function logCorrection(SavingDue $due, string $action, int $old, int $new, int $delta, string $reason, ?int $entryId, ?User $by): void
    {
        \App\Models\SavingDueCorrection::create(['saving_due_id' => $due->id, 'action' => $action, 'old_amount' => $old, 'new_amount' => $new,
            'delta' => $delta, 'reason' => mb_substr($reason, 0, 150), 'entry_id' => $entryId, 'user_id' => $by?->id ?? auth()->id(), 'created_at' => now()]);
    }

    /** Hapus catatan setoran yang belum diposting. */
    public function discard(SavingDue $due): void
    {
        if ($due->status === 'disetor') {
            throw ValidationException::withMessages(['due' => 'Setoran yang sudah diposting tidak bisa dihapus.']);
        }
        $due->delete();
    }

    /**
     * Rekap per bulan (terbaru dulu) untuk satu jenis: anggota & nominal tercatat vs sudah disetor.
     *
     * @return list<array{period: string, label: string, recorded: int, recorded_amount: int, posted: int, posted_amount: int}>
     */
    public function recap(SavingType $type, int $months = 12): array
    {
        return SavingDue::where('saving_type_id', $type->id)
            ->selectRaw("period, COUNT(*) AS recorded, COALESCE(SUM(amount), 0) AS recorded_amount,
                SUM(CASE WHEN status = 'disetor' THEN 1 ELSE 0 END) AS posted,
                COALESCE(SUM(CASE WHEN status = 'disetor' THEN amount ELSE 0 END), 0) AS posted_amount")
            ->groupBy('period')->orderByDesc('period')->limit($months)->get()
            ->map(fn ($r) => ['period' => $r->period, 'label' => Carbon::parse($r->period.'-01')->translatedFormat('F Y'),
                'recorded' => (int) $r->recorded, 'recorded_amount' => (int) round((float) $r->recorded_amount),
                'posted' => (int) $r->posted, 'posted_amount' => (int) round((float) $r->posted_amount)])->all();
    }

    /**
     * Matriks status per guru untuk $months bulan terakhir (lama → baru): disetor (ada mutasi setor bulan itu),
     * belum (dicatat, belum diposting), kosong (belum dicatat), atau null (belum bergabung).
     *
     * @return array{periods: list<array{key: string, label: string, full: string}>, rows: list<array{member_id: int, code: string, name: string, cells: array<string, ?string>, missing: int}>,
     *     totals: array<string, array{eligible: int, paid: int, missing: int, amount: int}>}
     */
    public function matrix(SavingType $type, int $months = 6): array
    {
        $periods = collect(range($months - 1, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i));
        $keys = $periods->map->format('Y-m')->all();
        $members = Member::active()->where('type', 'guru')->orderByRaw('LOWER(name)')->get();
        $ids = $members->pluck('id');
        $paid = MemberSavingService::paidPeriodRows($ids->all(), $type->id, $keys)->groupBy('member_id')->map(fn ($g) => $g->pluck('period')->flip());
        $pending = SavingDue::where('saving_type_id', $type->id)->where('status', 'belum')->whereIn('period', $keys)->whereIn('member_id', $ids)
            ->get(['member_id', 'period'])->groupBy('member_id')->map(fn ($g) => $g->pluck('period')->flip());

        $rows = $members->map(function (Member $m) use ($keys, $paid, $pending) {
            $joined = ($m->joined_at ?? $m->created_at)?->format('Y-m');
            $cells = [];
            foreach ($keys as $k) {
                $cells[$k] = $joined && $k < $joined ? null
                    : (isset($paid[$m->id][$k]) ? 'disetor' : (isset($pending[$m->id][$k]) ? 'belum' : 'kosong'));
            }

            return ['member_id' => $m->id, 'code' => $m->member_code, 'name' => $m->name, 'cells' => $cells,
                'missing' => count(array_filter($cells, fn ($c) => $c !== null && $c !== 'disetor'))];
        })->values()->all();

        // Total per bulan: anggota sudah disetor & nominal bersih bulan itu (setor − koreksi).
        $amounts = MemberSavingEntry::where('saving_type_id', $type->id)->whereIn('period', $keys)->whereIn('member_id', $ids)
            ->selectRaw("period, SUM(CASE WHEN type = 'setor' THEN amount ELSE -amount END) AS net")->groupBy('period')->pluck('net', 'period');
        $totals = [];
        foreach ($keys as $k) {
            $eligible = count(array_filter($rows, fn ($r) => $r['cells'][$k] !== null));
            $paidCount = count(array_filter($rows, fn ($r) => $r['cells'][$k] === 'disetor'));
            $totals[$k] = ['eligible' => $eligible, 'paid' => $paidCount, 'missing' => $eligible - $paidCount, 'amount' => (int) round((float) ($amounts[$k] ?? 0))];
        }

        return ['periods' => $periods->map(fn ($d) => ['key' => $d->format('Y-m'), 'label' => $d->translatedFormat('M y'), 'full' => $d->translatedFormat('F Y')])->all(),
            'rows' => $rows, 'totals' => $totals];
    }

    private function assertTypeAndPeriod(SavingType $type, string $period): void
    {
        if (! self::validPeriod($period)) {
            throw ValidationException::withMessages(['period' => 'Periode tidak valid.']);
        }
        if ($period > now()->format('Y-m')) {
            throw ValidationException::withMessages(['period' => 'Periode tidak boleh melewati bulan ini.']);
        }
        if (! $type->is_active || $type->period !== 'bulanan') {
            throw ValidationException::withMessages(['type' => 'Pilih jenis simpanan bulanan yang aktif.']);
        }
    }
}
