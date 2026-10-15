<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanPayment;
use Illuminate\Support\Carbon;

/**
 * Isi CSV rekap pinjaman (pemisah `;` + BOM agar langsung terbuka di Excel): identitas & periode, ringkasan periode,
 * posisi saat ini, angsuran per metode, per bulan, lalu rincian pencairan & pembayaran sah dalam periode.
 * Teks isian pengguna yang diawali = + - @ diberi tanda kutip agar tidak dieksekusi sebagai rumus spreadsheet.
 * Dipakai job ExportLoanRecap.
 */
class LoanRecapExport
{
    public function __construct(private LoanRecapService $recap, private SettingsService $settings) {}

    /** @return array{content: string, rows: int} */
    public function csv(Carbon $from, Carbon $to): array
    {
        $r = $this->recap->recap($from, $to);
        $p = $r['portfolio'];
        $c = $r['collected'];
        $d = fn ($v) => $v ? Carbon::parse($v)->format('d/m/Y') : '';
        $disb = LoanDisbursement::query()->whereDayRange('disbursed_on', $from->toDateString(), $to->toDateString())
            ->with('loan:id,code,member_id', 'loan.member:id,member_code,name,deleted_at', 'user:id,name')->orderBy('disbursed_on')->orderBy('id')->get();
        $pays = LoanPayment::query()->whereNull('voided_at')->whereDayRange('paid_at', $from->toDateString(), $to->toDateString())
            ->with('loan:id,code,member_id', 'loan.member:id,member_code,name,deleted_at', 'user:id,name')->orderBy('paid_at')->orderBy('id')->get();

        $lines = [
            ['Rekap Pinjaman', $this->settings->profile()['name']],
            ['Periode', $from->format('d/m/Y').' – '.$to->format('d/m/Y')],
            ['Dibuat', now()->format('d/m/Y H:i')],
            [],
            ['RINGKASAN PERIODE'],
            ['Pengajuan masuk', $r['applications']['count']], ['Nominal pengajuan', $r['applications']['amount']],
            ['Disetujui', $r['applications']['approved']], ['Ditolak', $r['applications']['rejected']],
            ['Pinjaman dicairkan', $r['disbursed']['count']], ['Dana dicairkan', $r['disbursed']['amount']],
            ['Pembayaran angsuran', $c['count']], ['Angsuran diterima', $c['amount']], ['Porsi pokok', $c['principal']], ['Porsi bunga', $c['interest']],
            [],
            ['POSISI SAAT INI'],
            ['Pinjaman aktif', $p['active']], ['Sisa pinjaman', $p['outstanding']], ['Sisa pokok', $p['principal_outstanding']], ['Sisa bunga', $p['interest_outstanding']],
            ['Pinjaman menunggak', $p['overdue']], ['Sisa pinjaman menunggak', $p['overdue_outstanding']], ['Pinjaman lunas', $p['paid_off']],
            ['Dana tercairkan (seluruhnya)', $p['disbursed_total']], ['Angsuran kembali (seluruhnya)', $p['collected_total']],
            ['Persentase kembali', str_replace('.', ',', (string) $p['collection_rate']).'%'],
            [],
            ['ANGSURAN PER METODE'],
            ['Metode', 'Pembayaran', 'Pinjaman dibayar', 'Diterima', 'Pokok', 'Bunga', 'Bagian (%)', 'Pinjaman aktif', 'Sisa pinjaman aktif'],
        ];
        foreach ($c['by_method'] as $m) {
            $lines[] = [$m['label'], $m['count'], $m['loans_paid'], $m['amount'], $m['principal'], $m['interest'], str_replace('.', ',', (string) $m['share']), $m['active_loans'], $m['active_outstanding']];
        }
        if ($r['months']) {
            $lines = [...$lines, [], ['PER BULAN'], ['Bulan', 'Dicairkan', 'Diterima', 'Pembayaran']];
            foreach ($r['months'] as $m) {
                $lines[] = [$m['label'], $m['disbursed'], $m['collected'], $m['count']];
            }
        }
        $lines = [...$lines, [], ['RINCIAN PENCAIRAN'], ['Tanggal', 'Kode pinjaman', 'Kode anggota', 'Nama anggota', 'Nominal', 'Petugas']];
        foreach ($disb as $x) {
            $lines[] = [$d($x->disbursed_on), $x->loan?->code, $x->loan?->member?->member_code, $x->loan?->member?->name, (int) round((float) $x->amount), $x->user?->name];
        }
        $lines = [...$lines, [], ['RINCIAN PEMBAYARAN ANGSURAN'], ['Tanggal', 'Kode pinjaman', 'Kode anggota', 'Nama anggota', 'Nominal', 'Metode', 'Rujukan', 'Angsuran ke-', 'Petugas']];
        foreach ($pays as $x) {
            $lines[] = [$d($x->paid_at), $x->loan?->code, $x->loan?->member?->member_code, $x->loan?->member?->name, (int) round((float) $x->amount),
                Loan::METHODS[$x->payment_method] ?? $x->payment_method, LoanPaymentService::referenceLabel($x->payment_method, $x->reference),
                implode(', ', array_column($x->allocations ?? [], 'sequence')), $x->user?->name];
        }

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($lines as $row) {
            fputcsv($out, array_map([self::class, 'cell'], $row), ';');
        }
        rewind($out);
        $content = stream_get_contents($out);
        fclose($out);

        return ['content' => $content, 'rows' => $disb->count() + $pays->count()];
    }

    /** Netralkan rumus spreadsheet pada teks (angka dibiarkan). */
    public static function cell(mixed $v): mixed
    {
        return is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }
}
