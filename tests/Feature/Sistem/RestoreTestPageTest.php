<?php

namespace Tests\Feature\Sistem;

use App\Livewire\Sistem\Backups;
use App\Livewire\Sistem\RestoreTest;
use App\Support\MockData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RestoreTestPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_in_nav_and_linked_from_backup_detail(): void
    {
        $this->get('/sistem/cadangan')->assertSee(route('sistem.restore'));
        $this->get('/sistem/uji-pemulihan')->assertOk()->assertSee('Jalankan uji pemulihan')->assertSee('Riwayat uji')->assertSee('tidak mengubah data koperasi');

        $ok = collect(MockData::backups())->firstWhere('status', 'berhasil');
        Livewire::test(Backups::class)->call('show', $ok['id'])->assertSee(route('sistem.restore', ['cadangan' => $ok['id']]));
    }

    public function test_only_successful_backups_selectable_and_query_preselects(): void
    {
        $c = Livewire::test(RestoreTest::class);
        $this->assertSame(['berhasil'], array_values(array_unique(array_column($c->instance()->backups, 'status'))));
        $second = $c->instance()->backups[1];
        Livewire::withQueryParams(['cadangan' => $second['id']])->test(RestoreTest::class)->assertSet('backupId', $second['id']);
        $failed = collect(MockData::backups())->firstWhere('status', 'gagal');
        Livewire::withQueryParams(['cadangan' => $failed['id']])->test(RestoreTest::class)->assertSet('backupId', $c->instance()->backups[0]['id']);
    }

    public function test_run_requires_confirmation_and_shows_comparison(): void
    {
        $c = Livewire::test(RestoreTest::class)->call('run')->assertHasErrors('confirm');
        $c->set('confirm', true)->call('run')->assertHasNoErrors()->assertDispatched('toast')
            ->assertSee('Berhasil dipulihkan')->assertSee('Periksa checksum arsip')->assertSee('Transaksi')->assertSet('confirm', false);
        $tables = $c->get('result')['tables'];
        $this->assertContains('transactions', array_column($tables, 'table'));
        foreach ($tables as $t) {
            $this->assertSame($t['live'], $t['backup'] + $t['newer']);
        }
        $this->assertSame('Admin Koperasi', $c->instance()->history[0]['by']);
        $this->assertFalse($c->instance()->due);
    }

    public function test_due_reminder(): void
    {
        $this->assertFalse(Livewire::test(RestoreTest::class)->instance()->due); // tiruan: uji berhasil 7 hari lalu
        $this->assertTrue(RestoreTest::isDue([]));
        $this->assertTrue(RestoreTest::isDue([['status' => 'berhasil', 'started_at' => now()->subDays(31)->toDateTimeString()], ['status' => 'gagal', 'started_at' => now()->toDateTimeString()]]));
        $this->assertFalse(RestoreTest::isDue([['status' => 'berhasil', 'started_at' => now()->subDays(29)->toDateTimeString()]]));
    }
}
