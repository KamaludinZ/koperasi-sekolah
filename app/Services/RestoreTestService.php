<?php

namespace App\Services;

use App\Models\Backup;
use App\Models\RestoreTest;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Uji pemulihan tanpa menyentuh data asli: arsip cadangan diperiksa checksum-nya, database.json dibuka,
 * dipulihkan ke basis data SQLite sementara yang dibuat dari migrasi aplikasi, lalu jumlah baris tiap
 * tabel dibandingkan dengan manifest (harus sama) dan dengan data sekarang (selisih = data setelah
 * cadangan). Basis data uji dihapus setelahnya. Hasil tercatat di restore_tests & audit.
 */
class RestoreTestService
{
    public const CONNECTION = 'restore_test';

    /** Label tabel yang ditampilkan di hasil uji. */
    public const LABELS = ['transactions' => 'Transaksi', 'transaction_items' => 'Baris transaksi', 'products' => 'Barang', 'members' => 'Anggota',
        'savings_transactions' => 'Mutasi tabungan', 'users' => 'Pengguna', 'activity_logs' => 'Catatan audit', 'stock_movements' => 'Mutasi stok',
        'categories' => 'Kategori', 'settings' => 'Pengaturan', 'notifications' => 'Notifikasi', 'suppliers' => 'Supplier'];

    public const STEPS = ['verify' => 'Periksa checksum arsip', 'extract' => 'Buka arsip cadangan', 'restore' => 'Pulihkan ke basis data uji', 'count' => 'Hitung & bandingkan data', 'cleanup' => 'Hapus basis data uji'];

    public function run(Backup $backup, ?User $by = null): RestoreTest
    {
        $started = now();
        $test = RestoreTest::create(['backup_id' => $backup->id, 'backup_name' => $backup->name, 'backup_at' => $backup->started_at, 'run_by' => $by?->id, 'started_at' => $started]);
        $steps = [];
        $dbFile = storage_path('app/restore-test-'.$test->id.'.sqlite');
        $mark = function (string $key, bool $ok) use (&$steps) {
            $steps[] = ['key' => $key, 'label' => self::STEPS[$key], 'ok' => $ok];
        };

        try {
            if ($backup->status !== 'berhasil' || ! $backup->path) {
                throw new RuntimeException('Cadangan ini tidak berhasil dibuat, jadi tidak bisa diuji.');
            }
            $disk = Storage::disk($backup->disk);
            if (! $disk->exists($backup->path)) {
                throw new RuntimeException('Berkas arsip cadangan tidak ditemukan di server.');
            }
            $zipPath = $disk->path($backup->path);
            if (! hash_equals((string) $backup->checksum, hash_file('sha256', $zipPath))) {
                $mark('verify', false);
                throw new RuntimeException('Checksum arsip tidak cocok — berkas cadangan rusak atau berubah.');
            }
            $mark('verify', true);

            $zip = new ZipArchive;
            if ($zip->open($zipPath) !== true || ($json = $zip->getFromName('database.json')) === false) {
                $mark('extract', false);
                throw new RuntimeException('Arsip tidak berisi database.json.');
            }
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true) ?: [];
            $zip->close();
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            unset($json);
            $mark('extract', true);

            $restored = $this->restoreInto($dbFile, $data);
            $mark('restore', true);

            $tables = [];
            $mismatches = 0;
            foreach ($data as $table => $rows) {
                $expected = (int) ($manifest['tables'][$table] ?? count($rows));
                $got = $restored[$table] ?? 0;
                $live = Schema::hasTable($table) ? DB::table($table)->count() : 0;
                $ok = $got === $expected;
                $mismatches += $ok ? 0 : 1;
                $tables[] = ['table' => $table, 'backup' => $expected, 'restored' => $got, 'live' => $live, 'ok' => $ok];
            }
            $mark('count', $mismatches === 0);

            $this->cleanup($dbFile);
            $mark('cleanup', true);

            $test->forceFill(['status' => $mismatches ? 'gagal' : 'berhasil', 'steps' => $steps, 'tables' => $tables, 'mismatches' => $mismatches,
                'error' => $mismatches ? "{$mismatches} tabel jumlah barisnya tidak cocok setelah dipulihkan." : null,
                'finished_at' => now(), 'duration_s' => (int) $started->diffInSeconds(now(), true)])->save();
        } catch (Throwable $e) {
            $this->cleanup($dbFile);
            $test->forceFill(['status' => 'gagal', 'steps' => $steps, 'error' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => now(), 'duration_s' => (int) $started->diffInSeconds(now(), true)])->save();
        }

        AuditLog::record('backup.restore_tested', 'pengaturan',
            "Uji pemulihan cadangan {$backup->name}: ".($test->status === 'berhasil' ? 'berhasil' : 'gagal — '.mb_strimwidth((string) $test->error, 0, 120, '…')),
            null, ['restore_test_id' => $test->id, 'backup_id' => $backup->id, 'mismatches' => $test->mismatches]);

        return $test->fresh();
    }

    /** Buat SQLite sementara dari migrasi, masukkan semua baris. @return array<string, int> baris terpulihkan per tabel */
    private function restoreInto(string $file, array $data): array
    {
        touch($file);
        config(['database.connections.'.self::CONNECTION => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false]]);
        DB::purge(self::CONNECTION);
        Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true]);

        $conn = DB::connection(self::CONNECTION);
        $conn->statement('PRAGMA foreign_keys = OFF');
        $counts = [];
        foreach ($data as $table => $rows) {
            if (! $conn->getSchemaBuilder()->hasTable($table)) {
                $counts[$table] = 0;

                continue;
            }
            try {
                $conn->table($table)->delete(); // data bawaan dari migrasi diganti isi arsip
            } catch (Throwable) {
                // tabel yang dilindungi pemicu (audit) biasanya kosong di basis data uji
            }
            $columns = array_flip($conn->getSchemaBuilder()->getColumnListing($table));
            foreach (array_chunk($rows, 200) as $chunk) {
                $conn->table($table)->insert(array_map(fn ($r) => array_intersect_key(array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $r), $columns), $chunk));
            }
            $counts[$table] = $conn->table($table)->count();
        }

        return $counts;
    }

    private function cleanup(string $file): void
    {
        DB::purge(self::CONNECTION);
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
