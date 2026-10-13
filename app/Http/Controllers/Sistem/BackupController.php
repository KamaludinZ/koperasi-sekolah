<?php

namespace App\Http\Controllers\Sistem;

use App\Http\Controllers\Controller;
use App\Jobs\RunBackup;
use App\Jobs\RunRestoreTest;
use App\Models\Backup;
use App\Models\RestoreTest;
use App\Services\AuditLog;
use App\Services\BackupService;
use App\Services\HealthCheckService;
use App\Support\BackupStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * API cadangan & uji pemulihan (menu Sistem, Admin):
 * - GET  /sistem/cadangan/data                → {data: cadangan 30 hari, status: ringkasan, schedule}
 * - POST /sistem/cadangan  {jenis?, catatan?} → 202 {data: cadangan berstatus berjalan} (dikerjakan di antrean cadangan)
 * - GET  /sistem/cadangan/{backup}/unduh      → arsip zip (tercatat di audit)
 * - GET  /sistem/uji-pemulihan/data           → {data: riwayat uji}
 * - POST /sistem/uji-pemulihan {backup_id}    → 202 (uji dijalankan di antrean cadangan)
 */
class BackupController extends Controller
{
    public static function schedule(): array
    {
        return ['enabled' => (bool) config('koperasi.backup.enabled', true), 'frequency' => 'harian', 'time' => config('koperasi.backup.time', '23:00'),
            'keep' => (int) config('koperasi.backup.keep', 14), 'include_files' => true, 'disk' => 'local'];
    }

    public function index(): JsonResponse
    {
        $rows = HealthCheckService::backupRows();

        return response()->json(['data' => $rows, 'status' => BackupStatus::summarize($rows), 'schedule' => self::schedule()]);
    }

    public function store(Request $request, BackupService $backups): JsonResponse
    {
        $data = $request->validate([
            'jenis' => ['nullable', Rule::in(array_keys(Backup::TYPES))],
            'catatan' => ['nullable', 'string', 'max:120'],
        ]);
        if (Backup::where('status', 'berjalan')->where('started_at', '>=', now()->subHour())->exists()) {
            return response()->json(['message' => 'Masih ada cadangan yang sedang dibuat.'], 409);
        }
        $b = $backups->start($data['jenis'] ?? 'lengkap', 'manual', $data['catatan'] ?? null, $request->user()->id);
        RunBackup::dispatch($b->id);

        return response()->json(['data' => $b->fresh()->toRow()], 202);
    }

    public function download(Backup $backup): StreamedResponse|JsonResponse
    {
        if ($backup->status !== 'berhasil' || ! $backup->path || ! Storage::disk($backup->disk)->exists($backup->path)) {
            return response()->json(['message' => 'Arsip cadangan tidak tersedia.'], 409);
        }
        AuditLog::record('backup.downloaded', 'pengaturan', "Mengunduh arsip cadangan {$backup->name}", null, ['backup_id' => $backup->id]);

        return Storage::disk($backup->disk)->download($backup->path, $backup->name, ['Content-Type' => 'application/zip']);
    }

    public function tests(): JsonResponse
    {
        return response()->json(['data' => RestoreTest::with('runner:id,name')->latest('started_at')->latest('id')->limit(30)->get()->map->toRow()->all()]);
    }

    public function runTest(Request $request): JsonResponse
    {
        $data = $request->validate(['backup_id' => ['required', 'integer', Rule::exists('backups', 'id')->where('status', 'berhasil')]],
            ['backup_id.exists' => 'Pilih cadangan yang berhasil dibuat.']);
        RunRestoreTest::dispatch((int) $data['backup_id'], $request->user()->id);

        return response()->json(['message' => 'Uji pemulihan dijalankan di latar.', 'data' => RestoreTest::latest('id')->first()?->toRow()], 202);
    }
}
