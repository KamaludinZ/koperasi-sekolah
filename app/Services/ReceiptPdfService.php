<?php

namespace App\Services;

use App\Models\Transaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Membuat & menyimpan struk PDF (kertas thermal 58/80 mm sesuai Pengaturan Struk)
 * untuk transaksi. File disimpan di disk `local`: receipts/YYYY/MM/<invoice>.pdf dan
 * dipakai ulang, kecuali pengaturan koperasi diubah setelah file dibuat — maka dibuat
 * ulang agar cetak ulang mengikuti format terbaru. Angka transaksi tetap dari snapshot.
 */
class ReceiptPdfService
{
    /** 1 mm = 2.8346 pt. */
    public const PT_PER_MM = 2.8346;

    public function __construct(private ReceiptPresenter $presenter, private SettingsService $settings) {}

    public function path(Transaction $transaction): string
    {
        return sprintf('receipts/%s/%s.pdf', $transaction->created_at->format('Y/m'), $transaction->invoice_number);
    }

    /** Pastikan PDF struk tersimpan; kembalikan path relatif di disk `local`. */
    public function store(Transaction $transaction, bool $force = false): string
    {
        $path = $this->path($transaction);
        $disk = Storage::disk('local');

        if ($force || ! $disk->exists($path) || $this->outdated($disk->lastModified($path))) {
            $disk->put($path, $this->render($transaction));
        }

        return $path;
    }

    /** Isi PDF (biner) dari file tersimpan, dibuat dulu bila belum ada. */
    public function contents(Transaction $transaction): string
    {
        return Storage::disk('local')->get($this->store($transaction));
    }

    /** File dibuat sebelum pengaturan terakhir diubah? */
    private function outdated(int $fileTime): bool
    {
        $updated = $this->settings->all()['updated_at'];

        return $updated !== null && strtotime($updated) > $fileTime;
    }

    public function render(Transaction $transaction): string
    {
        $tx = $this->presenter->present($transaction);
        $profile = $this->presenter->pdfProfile();
        $mm = ReceiptPresenter::paperWidth($profile);
        $width = $mm * self::PT_PER_MM;
        $height = $this->height($tx, $profile, $mm);

        return Pdf::loadView('kasir.receipt-pdf', [
            'tx' => $tx,
            'profile' => $profile,
        ])
            ->setPaper([0, 0, $width, $height])
            ->output();
    }

    /**
     * Perkiraan tinggi kertas (pt) dari jumlah baris yang tercetak, karena struk
     * thermal tidak berhalaman. Teks panjang dihitung terbungkus sesuai lebar kertas.
     */
    private function height(array $tx, array $profile, int $mm): float
    {
        $rc = $profile['receipt'];
        $cols = $mm === 80 ? 46 : 32; // karakter per baris (huruf mono 7pt)
        $wrap = fn (?string $text) => $text === null || $text === '' ? 0
            : collect(preg_split('/\R/', $text))->sum(fn ($line) => max(1, (int) ceil(mb_strlen($line) / $cols)));

        $lines = $wrap($rc['header']) + $wrap($rc['subheader'])
            + ($rc['show_address'] ? $wrap($profile['address'] ?? null) : 0)
            + ($rc['show_phone'] && ($profile['phone'] ?? null) ? 1 : 0)
            + 2 + ($rc['show_cashier'] ? 1 : 0) + ($rc['show_member'] && ($tx['member'] ?? null) ? 1 : 0)
            + collect($tx['items'])->sum(fn ($i) => $wrap($i['product_name']) + 1)
            + 4 // subtotal, total, bayar, kembali
            + ($tx['discount'] > 0 ? 1 : 0) + ($tx['tax'] > 0 ? 1 : 0)
            + (($tx['rounding'] ?? 0) !== 0 ? 1 : 0) + (($tx['tax_included'] ?? 0) > 0 ? 1 : 0)
            + $wrap($rc['note'] ?? null) + $wrap($rc['footer'] ?? null);

        $logo = ! empty($profile['logo_data']) ? 38 : 0;

        return 16 /* margin */ + 4 * 11 /* garis putus */ + $logo + $lines * 9.6 + count($tx['items']) * 2 + 12;
    }
}
