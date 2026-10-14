<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberSavingAccount;
use App\Models\MemberSavingEntry;
use App\Models\SavingsTransaction;
use App\Models\SavingType;
use App\Support\Rupiah;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Potong saldo anggota tanpa persetujuan pengurus: tabungan (members.balance) atau simpanan yang boleh
 * ditarik. Atomik — baris anggota & saldo dikunci, saldo dicek ulang setelah dikunci, mutasi + saldo
 * diperbarui dalam satu transaksi DB, lalu dicatat di audit. Saldo kurang → ditolak (tidak ada yang dipotong).
 */
class BalanceDeductionService
{
    /**
     * @param  ?int  $savingTypeId  null = tabungan; selain itu jenis simpanan
     * @param  array{0: string, 1: int}  $source  asal mutasi, mis. ['order', 12]
     * @param  ?array  $actor  pelaku audit (anggota portal); null = petugas yang masuk
     * @return array{entry_id: int, source: string, before: int, after: int}
     *
     * @throws ValidationException (kunci `payment_method`)
     */
    public function deduct(Member $member, ?int $savingTypeId, int $amount, string $note, array $source, ?int $userId = null, ?array $actor = null): array
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['payment_method' => 'Jumlah potongan harus lebih dari 0.']);
        }

        return DB::transaction(function () use ($member, $savingTypeId, $amount, $note, $source, $userId, $actor) {
            $locked = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();
            if (! $locked->is_active) {
                throw ValidationException::withMessages(['payment_method' => 'Anggota nonaktif tidak bisa membayar dengan saldo.']);
            }
            $subject = ['type' => 'member', 'id' => $locked->id, 'label' => "{$locked->member_code} · {$locked->name}"];

            if ($savingTypeId === null) {
                $before = (int) round((float) $locked->balance);
                if ($amount > $before) {
                    throw ValidationException::withMessages(['payment_method' => 'Saldo tabungan kurang '.Rupiah::format($amount - $before).' (saldo '.Rupiah::format($before).').']);
                }
                $entry = $locked->savings()->create(['type' => SavingsTransaction::TYPE_WITHDRAWAL, 'amount' => $amount, 'balance_before' => $before,
                    'balance_after' => $before - $amount, 'note' => $note, 'source_type' => $source[0], 'source_id' => $source[1], 'user_id' => $userId]);
                $locked->forceFill(['balance' => $before - $amount])->save();
                AuditLog::record("savings.{$source[0]}", 'tabungan', "{$note} dibayar dengan tabungan {$locked->name}: ".Rupiah::format($amount), $subject,
                    ['savings_id' => $entry->id, "{$source[0]}_id" => $source[1], 'balance_before' => $before, 'balance_after' => $before - $amount], $amount, actor: $actor);

                return ['entry_id' => $entry->id, 'source' => 'Tabungan', 'before' => $before, 'after' => $before - $amount];
            }

            $type = SavingType::find($savingTypeId);
            if (! $type || ! $type->is_active || ! $type->withdrawable) {
                throw ValidationException::withMessages(['payment_method' => 'Simpanan ini tidak bisa dipakai untuk membayar.']);
            }
            $account = MemberSavingAccount::where('member_id', $locked->id)->where('saving_type_id', $type->id)->lockForUpdate()->first();
            $before = (int) round((float) ($account?->balance ?? 0));
            if (! $account || $amount > $before) {
                throw ValidationException::withMessages(['payment_method' => "Saldo {$type->name} kurang ".Rupiah::format($amount - $before).' (saldo '.Rupiah::format($before).').']);
            }
            $entry = MemberSavingEntry::create(['member_id' => $locked->id, 'saving_type_id' => $type->id, 'type' => 'potong', 'amount' => $amount,
                'balance_before' => $before, 'balance_after' => $before - $amount, 'note' => $note, 'source_type' => $source[0], 'source_id' => $source[1], 'user_id' => $userId]);
            $account->forceFill(['balance' => $before - $amount])->save();
            AuditLog::record('simpanan.potong', 'simpanan', "{$note} dibayar dengan {$type->name} {$locked->name}: ".Rupiah::format($amount), $subject,
                ['saving_type_id' => $type->id, 'entry_ids' => [$entry->id], "{$source[0]}_id" => $source[1], 'balance_before' => $before, 'balance_after' => $before - $amount],
                $amount, actor: $actor);

            return ['entry_id' => $entry->id, 'source' => $type->name, 'before' => $before, 'after' => $before - $amount];
        });
    }

    /** Kembalikan potongan (mis. pesanan dibatalkan). Anggota nonaktif tetap menerima pengembalian. */
    public function refund(Member $member, ?int $savingTypeId, int $amount, string $note, array $source, ?int $userId = null): void
    {
        DB::transaction(function () use ($member, $savingTypeId, $amount, $note, $source, $userId) {
            $locked = Member::withTrashed()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            if ($savingTypeId === null) {
                $before = (int) round((float) $locked->balance);
                $locked->savings()->create(['type' => SavingsTransaction::TYPE_DEPOSIT, 'amount' => $amount, 'balance_before' => $before,
                    'balance_after' => $before + $amount, 'note' => $note, 'source_type' => $source[0], 'source_id' => $source[1], 'user_id' => $userId]);
                $locked->forceFill(['balance' => $before + $amount])->save();

                return;
            }
            MemberSavingAccount::firstOrCreate(['member_id' => $locked->id, 'saving_type_id' => $savingTypeId], ['balance' => 0]);
            $account = MemberSavingAccount::where('member_id', $locked->id)->where('saving_type_id', $savingTypeId)->lockForUpdate()->first();
            $before = (int) round((float) $account->balance);
            MemberSavingEntry::create(['member_id' => $locked->id, 'saving_type_id' => $savingTypeId, 'type' => 'setor', 'amount' => $amount,
                'balance_before' => $before, 'balance_after' => $before + $amount, 'note' => $note, 'source_type' => $source[0], 'source_id' => $source[1], 'user_id' => $userId]);
            $account->forceFill(['balance' => $before + $amount])->save();
        });
    }

    /**
     * Riwayat potong saldo anggota untuk belanja (tabungan & simpanan; pesanan portal & kasir), terbaru dulu,
     * dengan tautan pesanan dan tanda bila sudah dikembalikan.
     *
     * @return \Illuminate\Support\Collection<int, array{id: string, at: \Illuminate\Support\Carbon, source: string, label: ?string, amount: int, balance: int, order: ?array, refunded: bool}>
     */
    public function history(Member $member, ?\Illuminate\Support\Carbon $from, int $limit): \Illuminate\Support\Collection
    {
        $id = $member->id;
        $tab = SavingsTransaction::where('member_id', $id)->where('type', SavingsTransaction::TYPE_WITHDRAWAL)->whereIn('source_type', ['order', 'transaction'])
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))->latest('created_at')->latest('id')->limit($limit)->get()
            ->map(fn (SavingsTransaction $s) => ['id' => 't'.$s->id, 'at' => $s->created_at, 'source' => 'Tabungan', 'label' => $s->note,
                'amount' => (int) round((float) $s->amount), 'balance' => (int) round((float) $s->balance_after), 'src' => [$s->source_type, $s->source_id]]);
        $sim = MemberSavingEntry::with('savingType:id,name')->where('member_id', $id)->where('type', 'potong')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))->latest('created_at')->latest('id')->limit($limit)->get()
            ->map(fn (MemberSavingEntry $e) => ['id' => 's'.$e->id, 'at' => $e->created_at, 'source' => $e->savingType?->name ?? 'Simpanan', 'label' => $e->note,
                'amount' => (int) round((float) $e->amount), 'balance' => (int) round((float) $e->balance_after), 'src' => [$e->source_type, $e->source_id]]);
        $rows = $tab->concat($sim)->sortByDesc(fn ($r) => $r['at']->timestamp)->values();
        $orders = \App\Models\Order::whereIn('id', $rows->where('src.0', 'order')->pluck('src.1'))->get(['id', 'code', 'payment_status'])->keyBy('id');

        return $rows->map(function ($r) use ($orders) {
            $o = $r['src'][0] === 'order' ? $orders->get($r['src'][1]) : null;

            return $r + ['in' => false, 'type' => $r['source'], 'order' => $o ? ['id' => $o->id, 'code' => $o->code] : null,
                'refunded' => $o?->payment_status === 'dikembalikan'];
        });
    }
}
