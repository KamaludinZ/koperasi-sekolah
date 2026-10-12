<?php

namespace Tests\Feature\Anggota;

use App\Livewire\Anggota\Index;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_lists_active_members_with_balance(): void
    {
        $this->get('/anggota')
            ->assertOk()
            ->assertSee('Anggota & Tabungan')
            ->assertSee('Siti Nurhaliza')
            ->assertSee('Rp142.500')
            ->assertDontSee('Galih Saputra'); // nonaktif
    }

    public function test_filters(): void
    {
        $c = Livewire::test(Index::class)->set('type', 'guru');
        $this->assertSame(['guru'], array_values(array_unique(array_column($c->instance()->members, 'type'))));

        $c = Livewire::test(Index::class)->set('class', '7A');
        $this->assertSame(['Andi Pratama', 'Dewi Lestari'], array_column($c->instance()->members, 'name'));

        Livewire::test(Index::class)->set('search', 's-2024-005')->assertSee('Rizky Ramadhan')->assertDontSee('Andi Pratama');

        Livewire::test(Index::class)->set('showInactive', true)->assertSee('Galih Saputra');
    }

    public function test_choosing_teachers_clears_class_and_stats(): void
    {
        $c = Livewire::test(Index::class)->set('class', '7A')->set('type', 'guru')->assertSet('class', '');
        $stats = $c->instance()->stats;

        $this->assertSame(14, $stats['total']);
        $this->assertSame(11, $stats['siswa']);
        $this->assertSame(3, $stats['guru']);
    }
}
