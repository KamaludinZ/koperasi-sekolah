<?php

namespace App\Http\Controllers\Pinjaman;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\AuditQuery;
use App\Services\LoanAuditQuery;
use App\Services\LoanLedgerQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Kronologi jejak audit pinjaman (JSON, Admin & Pengurus):
 * - GET /pinjaman/audit/data?kelompok=&peristiwa=&periode=&dari=&sampai=&petugas=&pinjaman=&q=&page=&per_page=
 *   → peristiwa pinjaman terbaru dulu (label, kelompok, perlu perhatian, uraian, pinjaman, petugas, IP, nominal,
 *   sebelum/sesudah, alasan) + mata rantai HMAC tiap baris (hmac, prev_hmac) + jumlah per kelompok.
 * - GET /pinjaman/audit/transaksi?jenis=&periode=&dari=&sampai=&pinjaman=&q=&page=&per_page= → riwayat pencairan &
 *   angsuran termasuk yang dikoreksi + total.
 * periode: hari-ini|7-hari|30-hari|semua (bawaan 30-hari); dari/sampai (Y-m-d) menimpa periode. Parameter salah → 422.
 */
class AuditTrailController extends Controller
{
    private function rules(): array
    {
        return [
            'periode' => ['nullable', Rule::in(array_keys(AuditQuery::PERIODS))],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dari'],
            'pinjaman' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function range(array $data): array
    {
        return AuditQuery::range($data['periode'] ?? '30-hari', $data['dari'] ?? null, $data['sampai'] ?? null);
    }

    public function index(Request $request, LoanAuditQuery $query): JsonResponse
    {
        $data = $request->validate($this->rules() + [
            'kelompok' => ['nullable', Rule::in([...array_keys(LoanAuditQuery::GROUPS), 'perhatian'])],
            'peristiwa' => ['nullable', Rule::in(array_keys(LoanAuditQuery::EVENTS))],
            'petugas' => ['nullable', 'string', 'max:100'],
        ], ['kelompok.in' => 'Kelompok tidak dikenal.', 'peristiwa.in' => 'Peristiwa tidak dikenal.', 'sampai.after_or_equal' => 'Tanggal akhir sebelum tanggal awal.']);
        [$from, $to] = $this->range($data);
        $group = $data['kelompok'] ?? '';

        $page = $query->query(['group' => $group === 'perhatian' ? '' : $group, 'event' => $data['peristiwa'] ?? '', 'from' => $from, 'to' => $to,
            'actor' => $data['petugas'] ?? '', 'loan_id' => $data['pinjaman'] ?? null, 'search' => $data['q'] ?? ''])
            ->when($group === 'perhatian', fn ($q) => $q->whereIn('event', LoanAuditQuery::NOTABLE))
            ->paginate((int) ($data['per_page'] ?? 50), ['*'], 'page', (int) ($data['page'] ?? 1));
        $links = DB::table('loan_audit_chain')->where('source_type', 'activity')->whereIn('source_id', $page->getCollection()->pluck('id'))
            ->get(['source_id', 'id', 'hmac', 'prev_hmac'])->keyBy('source_id');

        return response()->json([
            'data' => $page->getCollection()->map(fn (ActivityLog $l) => LoanAuditQuery::toArray($l) + [
                'chain' => isset($links[$l->id]) ? ['link_id' => $links[$l->id]->id, 'hmac' => $links[$l->id]->hmac, 'prev_hmac' => $links[$l->id]->prev_hmac] : null,
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'counts' => $query->counts($from, $to),
            'filters' => ['from' => $from?->toDateString(), 'to' => $to?->toDateString(), 'kelompok' => $group ?: null, 'peristiwa' => $data['peristiwa'] ?? null,
                'petugas' => $data['petugas'] ?? null, 'pinjaman' => $data['pinjaman'] ?? null, 'q' => $data['q'] ?? null],
            'events' => LoanAuditQuery::EVENTS,
        ]);
    }

    public function ledger(Request $request, LoanLedgerQuery $ledger): JsonResponse
    {
        $data = $request->validate($this->rules() + ['jenis' => ['nullable', Rule::in(array_keys(LoanLedgerQuery::TYPES))]],
            ['jenis.in' => 'Jenis transaksi tidak dikenal.', 'sampai.after_or_equal' => 'Tanggal akhir sebelum tanggal awal.']);
        [$from, $to] = $this->range($data);
        $rows = $ledger->rows(['type' => $data['jenis'] ?? '', 'from' => $from, 'to' => $to, 'loan_id' => $data['pinjaman'] ?? null, 'search' => $data['q'] ?? '']);
        $per = (int) ($data['per_page'] ?? 50);
        $page = (int) ($data['page'] ?? 1);

        return response()->json([
            'data' => $rows->forPage($page, $per)->values(),
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($rows->count() / $per)), 'per_page' => $per, 'total' => $rows->count()],
            'totals' => LoanLedgerQuery::totals($rows),
        ]);
    }
}
