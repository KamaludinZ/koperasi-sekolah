<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hutang ke supplier. Tagihan dibuat otomatis saat barang diterima (GoodsReceiptService); layanan ini
 * menyajikan daftar & ringkasan (sisa, lewat jatuh tempo, ≤ 7 hari, dibayar bulan ini), rincian dengan
 * pembayaran, mencatat pembayaran (satu atau beberapa tagihan, maks. sisa), dan mengirim pengingat
 * jatuh tempo ke Admin & Pengurus (perintah hutang:ingatkan, terjadwal setiap pagi).
 */
class SupplierBillService
{
    public const DUE_SOON_DAYS = 7;

    /** @return list<array<string, mixed>> semua tagihan, jatuh tempo terdekat dulu */
    public function list(): array
    {
        return SupplierBill::with(['supplier', 'order'])->orderBy('due_at')->orderBy('id')->get()
            ->map(fn (SupplierBill $b) => self::toArray($b))->all();
    }

    /** @return array<string, mixed> */
    public static function toArray(SupplierBill $b): array
    {
        return [
            'id' => $b->id,
            'po_number' => $b->order?->number,
            'order_id' => $b->purchase_order_id,
            'supplier_id' => $b->supplier_id,
            'supplier' => $b->supplier?->name,
            'amount' => $b->amount,
            'paid' => $b->paid,
            'due_at' => $b->due_at->toDateString(),
            'status' => $b->status,
        ];
    }

    /** @return array{outstanding: int, overdue: int, week: int, paid_month: int} */
    public function summary(): array
    {
        $open = SupplierBill::open()->get();
        $remain = fn ($c) => (int) $c->sum(fn ($b) => $b->remaining());

        return [
            'outstanding' => $remain($open),
            'overdue' => $remain($open->filter(fn ($b) => $b->daysLeft() < 0)),
            'week' => $remain($open->filter(fn ($b) => $b->daysLeft() >= 0 && $b->daysLeft() <= self::DUE_SOON_DAYS)),
            'paid_month' => (int) SupplierPayment::whereBetween('paid_at', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->sum('amount'),
        ];
    }

    /** @return list<array<string, mixed>> pembayaran satu tagihan (terbaru dulu) */
    public function payments(SupplierBill $bill): array
    {
        return $bill->payments()->with('payer')->get()->sortByDesc(fn ($p) => $p->paid_at->toDateString().sprintf('%08d', $p->id))->values()
            ->map(fn (SupplierPayment $p) => ['id' => $p->id, 'amount' => $p->amount, 'paid_at' => $p->paid_at->toDateString(),
                'method' => $p->method, 'reference' => $p->reference, 'note' => $p->note, 'user' => $p->payer?->name ?? 'Sistem'])->all();
    }

    /**
     * Catat pembayaran ke beberapa tagihan sekaligus (satu supplier).
     *
     * @param  array<int|string, int|string>  $allocations  supplier_bill_id => jumlah
     * @param  array{paid_at: string, method: string, reference?: ?string, note?: ?string}  $meta
     * @return list<SupplierPayment>
     *
     * @throws ValidationException
     */
    public function pay(array $allocations, array $meta, ?User $by = null): array
    {
        $by ??= auth()->user();

        return DB::transaction(function () use ($allocations, $meta, $by) {
            $allocations = array_filter(array_map('intval', $allocations), fn ($v) => $v > 0);
            if ($allocations === []) {
                throw ValidationException::withMessages(['jumlah' => 'Isi jumlah yang dibayar.']);
            }
            $bills = SupplierBill::with('supplier', 'order')->whereIn('id', array_keys($allocations))->lockForUpdate()->get()->keyBy('id');
            if ($bills->count() !== count($allocations)) {
                throw ValidationException::withMessages(['tagihan' => 'Tagihan tidak ditemukan.']);
            }
            if ($bills->pluck('supplier_id')->unique()->count() > 1) {
                throw ValidationException::withMessages(['tagihan' => 'Satu pembayaran hanya untuk tagihan satu supplier.']);
            }
            $errors = [];
            foreach ($allocations as $id => $amount) {
                if ($amount > $bills[$id]->remaining()) {
                    $errors["alokasi.{$id}"] = 'Melebihi sisa tagihan (Rp'.number_format($bills[$id]->remaining(), 0, ',', '.').').';
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $payments = [];
            foreach ($allocations as $id => $amount) {
                $bill = $bills[$id];
                $payments[] = $bill->payments()->create([
                    'amount' => $amount, 'paid_at' => $meta['paid_at'], 'method' => $meta['method'],
                    'reference' => filled($meta['reference'] ?? null) ? trim($meta['reference']) : null,
                    'note' => filled($meta['note'] ?? null) ? trim($meta['note']) : null, 'paid_by' => $by?->id,
                ]);
                $bill->syncPaid();
                AuditLog::record('payable.paid', 'pengadaan',
                    "Bayar hutang {$bill->order?->number} ke {$bill->supplier->name}: Rp".number_format($amount, 0, ',', '.').($bill->status === 'lunas' ? ' (lunas)' : ''),
                    ['type' => 'supplier_bill', 'id' => $bill->id, 'label' => $bill->order?->number],
                    ['method' => $meta['method'], 'reference' => $meta['reference'] ?? null, 'remaining' => $bill->remaining()], $amount);
            }

            return $payments;
        });
    }

    /** Aturan validasi pembayaran (endpoint & form). */
    public static function paymentRules(): array
    {
        return [
            'alokasi' => ['required', 'array', 'min:1', 'max:50'],
            'alokasi.*' => ['nullable', 'integer', 'min:0', 'max:10000000000'],
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'method' => ['required', \Illuminate\Validation\Rule::in(array_keys(SupplierPayment::METHODS))],
            'reference' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * Batalkan satu pembayaran yang salah input (khusus Admin): baris pembayaran dihapus, sisa
     * tagihan dihitung ulang, dan pembatalan tercatat di audit beserta alasannya.
     */
    public function cancelPayment(SupplierPayment $payment, string $reason): SupplierBill
    {
        return DB::transaction(function () use ($payment, $reason) {
            $bill = SupplierBill::with(['supplier', 'order'])->whereKey($payment->supplier_bill_id)->lockForUpdate()->firstOrFail();
            $amount = $payment->amount;
            $payment->delete();
            $bill->syncPaid();
            AuditLog::record('payable.payment_cancelled', 'pengadaan',
                "Pembayaran Rp".number_format($amount, 0, ',', '.')." untuk {$bill->order?->number} ke {$bill->supplier->name} dibatalkan",
                ['type' => 'supplier_bill', 'id' => $bill->id, 'label' => $bill->order?->number],
                ['paid_at' => $payment->paid_at->toDateString(), 'method' => $payment->method, 'reference' => $payment->reference], $amount,
                reason: mb_substr(trim($reason), 0, 200));

            return $bill;
        });
    }

    /**
     * Kirim notifikasi untuk tagihan yang jatuh tempo hari ini/≤ 3 hari lagi atau sudah lewat
     * (sekali per tagihan per keadaan). Mengembalikan jumlah notifikasi baru.
     */
    public function remind(): int
    {
        $created = 0;
        foreach (SupplierBill::with(['supplier', 'order'])->open()->where('due_at', '<=', today()->addDays(3))->get() as $bill) {
            $days = $bill->daysLeft();
            $state = $days < 0 ? 'lewat' : 'segera';
            $n = Notification::firstOrCreate(['dedupe_key' => "hutang:{$state}:{$bill->id}"], [
                'type' => 'sistem',
                'target' => 'pengurus',
                'title' => ($days < 0 ? 'Hutang lewat jatuh tempo: ' : 'Hutang jatuh tempo: ').$bill->supplier->name,
                'message' => 'Sisa Rp'.number_format($bill->remaining(), 0, ',', '.')." untuk {$bill->order?->number} "
                    .($days < 0 ? 'lewat '.abs($days).' hari dari jatuh tempo.' : ($days === 0 ? 'jatuh tempo hari ini.' : "jatuh tempo {$days} hari lagi.")),
                'url' => route('pengadaan.hutang', ['supplier' => $bill->supplier_id], false),
            ]);
            $created += $n->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }
}
