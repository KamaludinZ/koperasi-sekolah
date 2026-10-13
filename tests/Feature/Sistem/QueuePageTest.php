<?php

namespace Tests\Feature\Sistem;

use App\Jobs\ExportCashRecap;
use App\Livewire\Sistem\Queue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class QueuePageTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $class): string
    {
        return json_encode(['displayName' => $class, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => []]);
    }

    public function test_page_in_nav(): void
    {
        $this->get('/sistem')->assertSee(route('sistem.antrean'));
        $this->get('/sistem/antrean')->assertOk()->assertSee('Pekerja antrean')->assertSee('Tugas gagal')->assertSee('Antrean kosong.')->assertSee('Tidak ada tugas gagal.');
    }

    public function test_lists_pending_and_failed_jobs(): void
    {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => $this->payload(ExportCashRecap::class), 'attempts' => 1, 'available_at' => time(), 'created_at' => time() - 45 * 60]);
        DB::table('failed_jobs')->insert(['uuid' => 'u-1', 'connection' => 'database', 'queue' => 'default', 'payload' => $this->payload(ExportCashRecap::class),
            'exception' => "RuntimeException: Disk penuh\n#0 stack", 'failed_at' => now()]);

        $c = Livewire::test(Queue::class);
        $this->assertSame([['Ekspor rekap kas', 45, 1]], array_map(fn ($p) => [$p['name'], $p['waiting_min'], $p['attempts']], $c->instance()->pending));
        $this->assertSame('RuntimeException: Disk penuh', $c->instance()->failed[0]['error']);
        $c->assertSee('Ekspor rekap kas')->assertSee('terlama 45 menit')->assertSee('RuntimeException: Disk penuh')->assertDontSee('#0 stack');

        $c->call('retry', 'u-1')->assertDispatched('toast')->assertSee('Tidak ada tugas gagal.');
        $this->assertSame([], $c->instance()->failed);
        $c->call('retry', 'tidak-ada')->assertNotDispatched('toast', type: 'error');
    }

    public function test_retry_all_and_labels(): void
    {
        foreach (['a', 'b'] as $u) {
            DB::table('failed_jobs')->insert(['uuid' => $u, 'connection' => 'database', 'queue' => 'default', 'payload' => $this->payload('App\\Jobs\\Lainnya'), 'exception' => 'Error', 'failed_at' => now()]);
        }
        $c = Livewire::test(Queue::class)->assertSee('Lainnya')->call('retryAll')->assertDispatched('toast');
        $this->assertSame([], $c->instance()->failed);
        $this->assertSame('Ekspor rekap kas', Queue::label(ExportCashRecap::class));
    }

    public function test_admin_only(): void
    {
        $this->loginAs('kasir@koperasi.test');
        $this->get('/sistem/antrean')->assertForbidden();
    }
}
