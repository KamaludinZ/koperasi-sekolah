<?php

namespace Tests\Feature\Sistem;

use App\Models\Backup;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BackupSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_model_and_row_shape(): void
    {
        $this->assertTrue(Schema::hasColumns('backups', ['name', 'disk', 'path', 'type', 'trigger', 'status', 'size', 'duration_s', 'checksum', 'includes', 'note', 'error', 'created_by', 'started_at', 'finished_at']));
        $b = Backup::factory()->create(['created_by' => auth()->id(), 'trigger' => 'manual', 'note' => 'Sebelum impor']);
        $row = $b->toRow();
        $this->assertSame(['berhasil', 'manual', 'Admin Koperasi', 16, ['basis data', 'logo & berkas unggahan']], [$row['status'], $row['trigger'], $row['by'], strlen($row['checksum']), $row['includes']]);

        $f = Backup::factory()->failed("Disk penuh\nstack")->create();
        $this->assertSame(['gagal', 'Disk penuh', null], [$f->toRow()['status'], $f->toRow()['note'], $f->toRow()['checksum']]);
        $this->assertSame([$b->id], Backup::succeeded()->pluck('id')->all());
        $this->assertSame($f->id, Backup::latestFirst()->first()->id); // waktu sama → id terbaru dulu
    }

    public function test_constraints(): void
    {
        Backup::factory()->create(['name' => 'a.zip']);
        $this->assertThrows(fn () => DB::transaction(fn () => Backup::factory()->create(['name' => 'a.zip'])), QueryException::class);
        if (DB::getDriverName() === 'pgsql') {
            $this->assertThrows(fn () => DB::transaction(fn () => Backup::factory()->create(['status' => 'aneh'])), QueryException::class);
            $this->assertThrows(fn () => DB::transaction(fn () => Backup::factory()->create(['checksum' => null])), QueryException::class);
            $this->assertThrows(fn () => DB::transaction(fn () => Backup::factory()->create(['trigger' => 'robot'])), QueryException::class);
        }
    }
}
