<?php

namespace App\Services;

use App\Models\Transaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Membuat & menyimpan struk PDF (kertas thermal 58 mm) untuk transaksi.
 * File disimpan di disk `local`: receipts/YYYY/MM/<invoice>.pdf, dan dipakai
 * ulang bila sudah ada (struk tidak berubah setelah transaksi tersimpan).
 */
class ReceiptPdfService
{
    /** Lebar kertas 58 mm dalam point (1 mm = 2.8346 pt). */
    private const PAPER_WIDTH_PT = 164.41;

    public function __construct(private ReceiptPresenter $presenter) {}

    public function path(Transaction $transaction): string
    {
        return sprintf('receipts/%s/%s.pdf', $transaction->created_at->format('Y/m'), $transaction->invoice_number);
    }

    /** Pastikan PDF struk tersimpan; kembalikan path relatif di disk `local`. */
    public function store(Transaction $transaction, bool $force = false): string
    {
        $path = $this->path($transaction);
        $disk = Storage::disk('local');

        if ($force || ! $disk->exists($path)) {
            $disk->put($path, $this->render($transaction));
        }

        return $path;
    }

    /** Isi PDF (biner) dari file tersimpan, dibuat dulu bila belum ada. */
    public function contents(Transaction $transaction): string
    {
        return Storage::disk('local')->get($this->store($transaction));
    }

    public function render(Transaction $transaction): string
    {
        $tx = $this->presenter->present($transaction);

        // Tinggi kertas mengikuti jumlah baris barang (struk thermal tidak berhalaman).
        $height = 250 + count($tx['items']) * 26;

        return Pdf::loadView('kasir.receipt-pdf', [
            'tx' => $tx,
            'profile' => $this->presenter->profile(),
        ])
            ->setPaper([0, 0, self::PAPER_WIDTH_PT, $height])
            ->output();
    }
}
