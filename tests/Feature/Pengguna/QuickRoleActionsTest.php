<?php

namespace Tests\Feature\Pengguna;

use App\Livewire\Pengguna\Index;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class QuickRoleActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $u;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs('admin');
        $this->u = User::factory()->role('kasir')->create(['name' => 'Bu Siti']);
    }

    public function test_add_then_remove_role_from_list(): void
    {
        $id = $this->u->id;
        $c = Livewire::test(Index::class)
            ->assertSeeHtml('data-add-role-menu="'.$id.'"')->assertSeeHtml('data-add-role="'.$id.'-pengurus"')->assertDontSeeHtml('data-add-role="'.$id.'-kasir"')
            ->assertDontSeeHtml('data-remove-role="'.$id.'-kasir"') // peran tunggal tidak punya tombol cabut
            ->call('addRole', $id, 'pengurus')->assertSee('Peran Pengurus ditambahkan ke Bu Siti.')
            ->assertSeeHtml('data-user-roles="pengurus,kasir"')->assertSeeHtml('data-remove-role="'.$id.'-kasir"');
        $this->assertSame(['pengurus', 'kasir'], $this->u->fresh()->roleNames());
        $this->assertSame('pengurus', $this->u->fresh()->role->name); // peran utama ikut prioritas

        $c->call('removeRole', $id, 'pengurus')->assertSee('Peran Pengurus dicabut dari Bu Siti.')->assertSeeHtml('data-user-roles="kasir"');
        $this->assertSame(['kasir'], $this->u->fresh()->roleNames());
        $this->assertSame('kasir', $this->u->fresh()->role->name);

        $logs = ActivityLog::where('event', 'user.role_changed')->orderBy('id')->get();
        $this->assertSame([[['pengurus'], []], [[], ['pengurus']]], $logs->map(fn ($l) => [$l->properties['added'], $l->properties['removed']])->all());
    }

    public function test_guards(): void
    {
        $id = $this->u->id;
        Livewire::test(Index::class)
            ->call('removeRole', $id, 'kasir')->assertSee('Peran terakhir Bu Siti tidak bisa dicabut')
            ->call('removeRole', $id, 'admin')->assertSee('tidak memegang peran Admin')
            ->call('addRole', $id, 'kasir')->assertSee('Bu Siti sudah memegang peran Kasir.') // sudah punya → tidak berubah
            ->call('addRole', $id, 'superuser')->assertSee('Akun atau peran tidak dikenal.')
            ->call('addRole', 999999, 'kasir')->assertSee('Akun atau peran tidak dikenal.');
        $this->assertSame(['kasir'], $this->u->fresh()->roleNames());
        $this->assertSame(0, ActivityLog::where('event', 'user.role_changed')->count());

        // admin tidak bisa mencabut peran admin dari dirinya walau punya peran lain
        $me = auth()->user();
        Livewire::test(Index::class)->call('addRole', $me->id, 'pengurus')->call('removeRole', $me->id, 'admin')
            ->assertSee('Anda tidak bisa mencabut peran admin dari akun sendiri.');
        $this->assertSame(['admin', 'pengurus'], $me->fresh()->roleNames());
    }

    public function test_only_admin(): void
    {
        $this->loginAs('pengurus');
        $this->get('/pengguna')->assertForbidden();
    }
}
