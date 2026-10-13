<?php

namespace Tests\Feature\Sistem;

use App\Livewire\Sistem\Backups;
use App\Support\BackupStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BackupStatusTest extends TestCase
{
    use RefreshDatabase;

    private function b(int $daysAgo, string $status, ?string $note = null, int $hour = 23): array
    {
        return ['created_at' => now()->subDays($daysAgo)->setTime($hour, 0)->toDateTimeString(), 'status' => $status, 'trigger' => 'terjadwal', 'note' => $note];
    }

    public function test_summary_strip_streak_and_status(): void
    {
        $s = BackupStatus::summarize([$this->b(1, 'berhasil'), $this->b(2, 'berhasil'), $this->b(3, 'gagal', 'Ruang penyimpanan penuh'), $this->b(4, 'berhasil')], 5);
        $this->assertSame(['kosong', 'berhasil', 'gagal', 'berhasil', 'berhasil'], array_column($s['strip'], 'status'));
        $this->assertSame([2, 1, 'ok'], [$s['streak'], $s['failed'], $s['status']]);
        $this->assertFalse($s['last_failure']['unresolved']);
        $this->assertSame(BackupStatus::ADVICE['penyimpanan'], $s['last_failure']['advice']);
    }

    public function test_recent_failure_and_stale_backups(): void
    {
        $s = BackupStatus::summarize([$this->b(1, 'gagal', 'Koneksi basis data terputus'), $this->b(2, 'berhasil')]);
        $this->assertSame(['peringatan', true, 0], [$s['status'], $s['last_failure']['unresolved'], $s['streak']]);
        $this->assertSame(BackupStatus::ADVICE['koneksi'], $s['last_failure']['advice']);

        $this->assertSame('gangguan', BackupStatus::summarize([$this->b(4, 'berhasil')])['status']);
        $this->assertSame('gangguan', BackupStatus::summarize([])['status']);
        $this->assertSame('Lihat catatan kesalahan di log server, lalu coba lagi.', BackupStatus::advice('aneh'));
    }

    public function test_panel_on_backups_and_health_pages(): void
    {
        \App\Models\Backup::factory()->create(['started_at' => now()->subDays(1)->setTime(23, 0)]);
        \App\Models\Backup::factory()->failed('Ruang penyimpanan penuh')->create(['started_at' => now()->subDays(2)->setTime(23, 0)]);
        $expected = BackupStatus::summarize(\App\Services\HealthCheckService::backupRows());
        $this->get('/sistem/cadangan')->assertOk()->assertSee('malam berturut-turut berhasil')->assertSee('Kegagalan terakhir')
            ->assertSee('data-backup-status="'.$expected['status'].'"', false);
        $this->get('/sistem')->assertOk()->assertSee('malam berturut-turut berhasil')->assertSee(route('sistem.cadangan'));
    }

    public function test_retry_creates_backup(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $c = Livewire::test(Backups::class)->call('retry')->assertDispatched('toast');
        $this->assertSame('Ulang setelah cadangan gagal', $c->instance()->rows[0]['note']);
    }
}
