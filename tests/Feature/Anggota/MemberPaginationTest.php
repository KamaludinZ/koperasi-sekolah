<?php

namespace Tests\Feature\Anggota;

use App\Livewire\Anggota\Index;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Daftar anggota berhalaman (tidak memuat seluruh anggota sekaligus). */
class MemberPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_list_is_paginated_and_filters_reset_page(): void
    {
        Member::factory()->count(Index::PER_PAGE + 10)->create(['is_active' => true, 'type' => 'siswa']);
        $c = Livewire::test(Index::class);
        $this->assertCount(Index::PER_PAGE, $c->instance()->members);
        $c->assertSeeText('Menampilkan 1–'.Index::PER_PAGE.' dari '.(Index::PER_PAGE + 10).' anggota.');
        $c->call('gotoPage', 2);
        $this->assertCount(10, $c->instance()->members);
        $c->set('search', 'zzz-tidak-ada')->assertSet('paginators.page', 1);
        $this->assertCount(0, $c->instance()->members);
    }
}
