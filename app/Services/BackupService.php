<?php

namespace App\Services;

use App\Models\Backup;
use App\Models\Notification;
use App\Support\BackupStatus;
use App\Support\SystemHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Pembuatan cadangan data ke arsip zip di disk privat (`cadangan/`):
 * - database.json — semua tabel data (tanpa cache/sesi/antrean) dalam format portabel; dipakai untuk
 *   uji pemulihan dan bisa dipulihkan ke basis data mana pun.
 * - database.sql — salinan pg_dump bila memakai PostgreSQL dan pg_dump tersedia (pemulihan penuh server).
 * - berkas/ — logo & gambar QRIS koperasi (jenis "lengkap").
 * - manifest.json — waktu, versi aplikasi, jumlah baris per tabel.
 * Setiap percobaan tercatat di tabel backups (berhasil/gagal + galat). Masa simpan: N arsip berhasil terakhir.
 */
class BackupService
{
    public const DIR = 'cadangan';

    /** Tabel sementara/teknis yang tidak perlu dicadangkan. */
    public const SKIP_TABLES = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'migrations'];

    /** Buat & jalankan cadangan sekarang (dipakai command & job). */
    public function run(string $type = 'lengkap', string $trigger = 'terjadwal', ?string $note = null, ?int $userId = null): Backup
    {
        return $this->execute($this->start($type, $trigger, $note, $userId));
    }

    /** Catat cadangan baru berstatus berjalan (sebelum dikerjakan di antrean). */
    public function start(string $type = 'lengkap', string $trigger = 'manual', ?string $note = null, ?int $userId = null): Backup
    {
        if (! array_key_exists($type, Backup::TYPES)) {
            throw new \InvalidArgumentException("Jenis cadangan tidak dikenal: {$type}");
        }
        $started = now();

        return Backup::create([
            'name' => 'koperasi-'.$started->format('Y-m-d-His').'-'.Str::lower(Str::random(4)).'.zip',
            'type' => $type, 'trigger' => $trigger, 'note' => $note ? mb_substr(trim($note), 0, 200) : null,
            'created_by' => $userId, 'started_at' => $started,
        ]);
    }

    /** Kerjakan cadangan yang berstatus berjalan: buat arsip, catat hasil (berhasil/gagal). */
    public function execute(Backup $backup): Backup
    {
        if ($backup->status !== 'berjalan') {
            return $backup;
        }
        $type = $backup->type;
        $trigger = $backup->trigger;
        $started = $backup->started_at;

        $tmp = storage_path('app/tmp-backup-'.$backup->id);
        try {
            @mkdir($tmp, 0700, true);
            $counts = $this->dumpJson($tmp.'/database.json');
            $includes = ['basis data'];
            if ($sql = $this->dumpSql($tmp.'/database.sql')) {
                $includes[] = 'salinan pg_dump';
            }
            $files = $type === 'lengkap' ? $this->collectFiles() : [];
            if ($files) {
                $includes[] = 'logo & berkas unggahan';
            }
            file_put_contents($tmp.'/manifest.json', json_encode([
                'app' => config('app.name'), 'created_at' => $started->toIso8601String(), 'driver' => DB::getDriverName(),
                'laravel' => app()->version(), 'type' => $type, 'tables' => $counts,
            ], JSON_PRETTY_PRINT));

            $path = self::DIR.'/'.$backup->name;
            $disk = Storage::disk($backup->disk);
            $disk->makeDirectory(self::DIR);
            $zipFile = $disk->path($path);
            $zip = new ZipArchive;
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Tidak bisa membuat arsip cadangan.');
            }
            $zip->addFile($tmp.'/database.json', 'database.json');
            $zip->addFile($tmp.'/manifest.json', 'manifest.json');
            if ($sql) {
                $zip->addFile($tmp.'/database.sql', 'database.sql');
            }
            foreach ($files as $name => $abs) {
                $zip->addFile($abs, 'berkas/'.$name);
            }
            $zip->close();

            $backup->forceFill([
                'status' => 'berhasil', 'path' => $path, 'size' => filesize($zipFile), 'checksum' => hash_file('sha256', $zipFile),
                'includes' => $includes, 'finished_at' => now(), 'duration_s' => (int) $started->diffInSeconds(now(), true),
            ])->save();
            AuditLog::record('backup.created', 'pengaturan', "Cadangan {$backup->name} dibuat (".Backup::TRIGGERS[$trigger].', '.self::bytes($backup->size).')',
                null, ['backup_id' => $backup->id, 'type' => $type, 'tables' => count($counts)]);
            Notification::where('dedupe_key', 'like', 'backup-%')->delete(); // masalah cadangan sudah teratasi
            $this->prune();
        } catch (Throwable $e) {
            $backup->forceFill([
                'status' => 'gagal', 'path' => null, 'checksum' => null, 'size' => 0, 'error' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => now(), 'duration_s' => (int) $started->diffInSeconds(now(), true),
            ])->save();
            AuditLog::record('backup.failed', 'pengaturan', "Cadangan {$backup->name} gagal: ".mb_strimwidth($e->getMessage(), 0, 150, '…'), null, ['backup_id' => $backup->id]);
            $this->notify('backup-failed-'.now()->toDateString(), 'Cadangan data gagal',
                mb_strimwidth($e->getMessage(), 0, 160, '…').' Saran: '.BackupStatus::advice($e->getMessage()));
            report($e);
        } finally {
            $this->cleanTmp($tmp);
        }

        return $backup->fresh();
    }

    /**
     * Pemeriksaan harian: beri tahu Admin bila cadangan berhasil terakhir lebih lama dari batas
     * (mis. penjadwal tidak berjalan). Mengembalikan true bila peringatan dibuat.
     */
    public function checkStale(): bool
    {
        $last = Backup::succeeded()->latestFirst()->first();
        $hours = $last ? (int) floor($last->started_at->diffInHours(now(), true)) : null;
        if ($hours !== null && $hours < BackupStatus::STALE_HOURS) {
            return false;
        }

        return $this->notify('backup-stale-'.now()->toDateString(), 'Cadangan data terlambat',
            $last ? "Cadangan berhasil terakhir {$hours} jam lalu. Periksa penjadwal (cron) di server atau buat cadangan manual."
                : 'Belum ada cadangan yang berhasil. Buat cadangan pertama di Sistem → Cadangan data.');
    }

    /** Notifikasi sistem untuk Admin (sekali per kunci). */
    private function notify(string $key, string $title, string $message): bool
    {
        return (bool) Notification::firstOrCreate(['dedupe_key' => $key], [
            'type' => 'sistem', 'target' => 'admin', 'title' => $title, 'message' => $message,
            'url' => route('sistem.cadangan', [], false),
        ])->wasRecentlyCreated;
    }

    /** Tabel yang dicadangkan, urut nama. */
    public static function tables(): array
    {
        return collect(Schema::getTableListing(schemaQualified: false))
            ->reject(fn ($t) => in_array($t, self::SKIP_TABLES, true) || str_starts_with($t, 'sqlite_'))
            ->sort()->values()->all();
    }

    /** Tulis semua tabel ke satu berkas JSON {tabel: [baris…]} secara bertahap. Mengembalikan jumlah baris. */
    private function dumpJson(string $file): array
    {
        $h = fopen($file, 'w');
        fwrite($h, '{');
        $counts = [];
        foreach (self::tables() as $i => $table) {
            fwrite($h, ($i ? ',' : '').json_encode($table).':[');
            $n = 0;
            $order = Schema::hasColumn($table, 'id') ? 'id' : null;
            $query = DB::table($table);
            $rows = $order ? $query->orderBy($order)->lazy(500) : $query->cursor();
            foreach ($rows as $row) {
                fwrite($h, ($n ? ',' : '').json_encode((array) $row, JSON_UNESCAPED_UNICODE));
                $n++;
            }
            fwrite($h, ']');
            $counts[$table] = $n;
        }
        fwrite($h, '}');
        fclose($h);

        return $counts;
    }

    /** pg_dump (format SQL biasa) bila memakai PostgreSQL & tersedia; null bila tidak dibuat. */
    private function dumpSql(string $file): ?string
    {
        if (DB::getDriverName() !== 'pgsql' || ! config('koperasi.backup.pg_dump', true)) {
            return null;
        }
        $c = config('database.connections.'.DB::getDefaultConnection());
        $result = Process::timeout(300)->env(array_filter(['PGPASSWORD' => $c['password'] ?? null]))->run(array_values(array_filter([
            'pg_dump', '--no-owner', '--no-privileges', '-f', $file,
            '-h', $c['host'] ?? null, '-p', isset($c['port']) ? (string) $c['port'] : null, '-U', $c['username'] ?? null, $c['database'],
        ], fn ($v) => $v !== null && $v !== '')));

        return $result->successful() && is_file($file) ? $file : null;
    }

    /** Berkas koperasi (logo, QRIS) di disk privat. @return array<string, string> nama → path absolut */
    private function collectFiles(): array
    {
        $disk = Storage::disk(LogoService::DISK);
        $out = [];
        foreach ($disk->files(LogoService::DIR) as $f) {
            $out[basename($f)] = $disk->path($f);
        }

        return $out;
    }

    /** Simpan N arsip berhasil terakhir; hapus arsip & baris yang lebih lama. Catatan gagal > 30 hari dihapus. */
    public function prune(?int $keep = null): int
    {
        $keep ??= (int) config('koperasi.backup.keep', 14);
        $old = Backup::succeeded()->latestFirst()->skip($keep)->take(PHP_INT_MAX)->get();
        foreach ($old as $b) {
            if ($b->path) {
                Storage::disk($b->disk)->delete($b->path);
            }
            $b->delete();
        }
        Backup::where('status', 'gagal')->where('started_at', '<', now()->subDays(30))->delete();

        return $old->count();
    }

    private function cleanTmp(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    public static function bytes(int $b): string
    {
        return SystemHealth::bytes($b);
    }
}
