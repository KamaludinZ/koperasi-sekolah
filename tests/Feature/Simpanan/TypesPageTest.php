<?php

namespace Tests\Feature\Simpanan;

use App\Livewire\Simpanan\Types;
use App\Models\ActivityLog;
use App\Models\SavingType;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TypesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_types_are_listed(): void
    {
        $this->get('/simpanan')->assertOk()
            ->assertSeeInOrder(['Simpanan Pokok', 'Simpanan Wajib', 'Simpanan Sukarela'])
            ->assertSee('Rp50.000')->assertSee('Tiap bulan')->assertSee('Ditahan selama anggota')->assertSee('Bebas');
    }

    public function test_create_edit_and_toggle(): void
    {
        $c = Livewire::test(Types::class)->call('create')
            ->set('form.code', 'hari-raya')->set('form.name', 'Simpanan Hari Raya')
            ->set('form.kind', 'wajib')->assertSet('form.period', 'bulanan')->assertSet('form.withdrawable', false)
            ->set('form.amount', 0)->call('save')->assertHasErrors(['form.amount'])
            ->set('form.amount', 20000)->call('save')->assertHasNoErrors()->assertSet('editing', false);

        $t = SavingType::where('code', 'HARI-RAYA')->firstOrFail();
        $this->assertSame(4, $t->sort_order);
        $this->assertSame('saving_type', ActivityLog::where('category', 'simpanan')->latest('id')->value('subject_type'));

        $c->call('edit', $t->id)->assertSet('form.code', 'HARI-RAYA')->set('form.kind', 'sukarela')->assertSet('form.withdrawable', true)
            ->set('form.amount', 0)->call('save')->assertHasNoErrors();
        $this->assertSame('sukarela', $t->fresh()->kind);

        $c->call('create')->set('form.code', 'wajib')->set('form.name', 'X')->call('save')->assertHasErrors(['form.code']);

        $c->call('toggle', $t->id);
        $this->assertFalse($t->fresh()->is_active);
        $c->assertSee('Nonaktif');
    }

    public function test_kasir_has_no_access_and_pengurus_can_manage(): void
    {
        $this->seed(UserSeeder::class);
        $this->loginAs('kasir@koperasi.test');
        $this->get('/simpanan')->assertForbidden();

        $this->loginAs('pengurus@koperasi.test');
        $this->get('/simpanan')->assertOk()->assertSee('Tambah jenis');
        Livewire::test(Types::class)->call('create')->assertSet('editing', true);
    }
}
