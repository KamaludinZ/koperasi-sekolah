<?php

namespace Tests\Feature\Sistem;

use App\Livewire\Sistem\Backups;
use App\Livewire\Sistem\RestoreTest;
use App\Models\Backup;
use App\Services\BackupService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class RestoreTestPageTest extends TestCase
{
    use RefreshDatabase;

    private Backup $ok;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ProductSeeder::class);
        $this->ok = app(BackupService::class)->run('basis data', 'manual');
        Backup::factory()->failed()->create(['started_at' => now()->subDay()]);
    }

    public function test_page_in_nav_and_linked_from_backup_detail(): void
    {
        $this->get('/sistem/cadangan')->assertSee(route('sistem.restore'));
        $this->get('/sistem/uji-pemulihan')->assertOk()->assertSee('Jalankan uji pemulihan')->assertSee('Riwayat uji')->assertSee('Belum pernah diuji.');
        Livewire::test(Backups::class)->call('show', $this->ok->id)->assertSee(route('sistem.restore', ['cadangan' => $this->ok->id]));
    }

    public function test_only_successful_backups_selectable(): void
    {
        $c = Livewire::test(RestoreTest::class);
        $this->assertSame([$this->ok->id], array_column($c->instance()->backups, 'id'));
        Livewire::withQueryParams(['cadangan' => $this->ok->id])->test(RestoreTest::class)->assertSet('backupId', $this->ok->id);
        $failed = Backup::where('status', 'gagal')->sole();
        Livewire::withQueryParams(['cadangan' => $failed->id])->test(RestoreTest::class)->assertSet('backupId', $this->ok->id);
    }

    public function test_run_requires_confirmation_and_shows_real_comparison(): void
    {
        $c = Livewire::test(RestoreTest::class)->call('run')->assertHasErrors('confirm');
        $c->set('confirm', true)->call('run')->assertHasNoErrors()->assertDispatched('toast', type: 'success')
            ->assertSee('Berhasil dipulihkan')->assertSee('Periksa checksum arsip')->assertSee('Barang')->assertSet('confirm', false);
        $products = collect($c->get('result')['tables'])->firstWhere('table', 'products');
        $this->assertSame([$products['backup'], true], [$products['restored'], $products['ok']]);
        $this->assertSame('Admin Koperasi', $c->instance()->history[0]['by']);
        $this->assertFalse($c->instance()->due);
    }

    public function test_due_reminder(): void
    {
        $this->assertTrue(Livewire::test(RestoreTest::class)->instance()->due); // belum pernah diuji
        $this->assertTrue(RestoreTest::isDue([['status' => 'berhasil', 'started_at' => now()->subDays(31)->toDateTimeString()]]));
        $this->assertFalse(RestoreTest::isDue([['status' => 'berhasil', 'started_at' => now()->subDays(29)->toDateTimeString()]]));
    }

    public function test_api_endpoints(): void
    {
        $this->getJson(route('sistem.cadangan.data'))->assertOk()->assertJsonPath('data.0.id', $this->ok->id)->assertJsonPath('schedule.time', '23:00')->assertJsonStructure(['status' => ['status', 'strip', 'streak']]);
        $this->postJson(route('sistem.cadangan.store'), ['jenis' => 'aneh'])->assertStatus(422);
        $this->postJson(route('sistem.cadangan.store'), ['jenis' => 'basis data', 'catatan' => 'API'])->assertStatus(202)->assertJsonPath('data.trigger', 'manual');
        $this->assertSame('berhasil', Backup::latest('id')->first()->status); // antrean sinkron saat tes

        $this->get(route('sistem.cadangan.unduh', $this->ok))->assertOk()->assertDownload($this->ok->name);
        $this->getJson(route('sistem.cadangan.unduh', Backup::where('status', 'gagal')->sole()))->assertStatus(409);

        $this->postJson(route('sistem.restore.run'), ['backup_id' => Backup::where('status', 'gagal')->sole()->id])->assertStatus(422);
        $this->postJson(route('sistem.restore.run'), ['backup_id' => $this->ok->id])->assertStatus(202);
        $this->getJson(route('sistem.restore.data'))->assertOk()->assertJsonPath('data.0.status', 'berhasil');

        $this->loginAs('kasir@koperasi.test');
        $this->getJson(route('sistem.cadangan.data'))->assertForbidden();
    }
}
