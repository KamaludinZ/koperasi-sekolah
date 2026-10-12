<?php

namespace Tests\Feature\Anggota;

use App\Livewire\Anggota\Index;
use App\Support\MockData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MemberFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_student_with_suggested_code(): void
    {
        Livewire::test(Index::class)
            ->call('create')
            ->assertSet('editing', true)
            ->assertSet('form.member_code', 'S-'.now()->year.'-013')
            ->set('form.name', 'Rani Oktaviani')
            ->set('form.class', '7b')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Rani Oktaviani terdaftar sebagai anggota.')
            ->assertSee('Rani Oktaviani');

        $m = collect(MockData::members())->firstWhere('name', 'Rani Oktaviani');
        $this->assertSame(['siswa', '7B', 0], [$m['type'], $m['class'], $m['balance']]);
    }

    public function test_switching_to_teacher_updates_code_and_clears_class(): void
    {
        Livewire::test(Index::class)
            ->call('create')
            ->set('form.class', '8A')
            ->set('form.type', 'guru')
            ->assertSet('form.class', '')
            ->assertSet('form.member_code', 'G-'.now()->year.'-005')
            ->set('form.name', 'Bu Ani')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_student_requires_class_and_unique_code(): void
    {
        Livewire::test(Index::class)
            ->call('create')
            ->set('form.member_code', 's-2024-001')
            ->set('form.class', '')
            ->set('form.phone', 'abc')
            ->call('save')
            ->assertHasErrors(['form.name', 'form.class', 'form.member_code', 'form.phone']);
    }

    public function test_edit_existing_member_keeps_balance(): void
    {
        Livewire::test(Index::class)
            ->call('edit', 2)
            ->assertSet('form.name', 'Siti Nurhaliza')
            ->set('form.class', '9B')
            ->set('form.is_active', false)
            ->call('save')
            ->assertHasNoErrors();

        $m = MockData::findMember(2);
        $this->assertSame(['9B', false, 142500], [$m['class'], $m['is_active'], $m['balance']]);
    }
}
