<?php

namespace Tests\Feature\Anggota;

use App\Models\Member;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-12 09:00:00');
    }

    public function test_store_generates_code_and_normalizes(): void
    {
        $this->postJson('/anggota', ['name' => ' Andi Pratama ', 'type' => 'siswa', 'class' => '7a', 'phone' => ''])
            ->assertCreated()
            ->assertJsonPath('data.member_code', 'S-2026-001')
            ->assertJsonPath('data.name', 'Andi Pratama')
            ->assertJsonPath('data.class', '7A')
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.balance', 0)
            ->assertJsonPath('data.joined_at', '2026-10-12')
            ->assertJsonPath('data.display_name', 'Andi Pratama (7A)');

        $this->postJson('/anggota', ['member_code' => 'g-01', 'name' => 'Bu Rina', 'type' => 'guru', 'class' => '9A'])
            ->assertCreated()
            ->assertJsonPath('data.member_code', 'G-01')
            ->assertJsonPath('data.class', null);

        $this->getJson('/anggota/kode-baru?jenis=siswa')->assertJsonPath('data.member_code', 'S-2026-002');
    }

    public function test_store_validates(): void
    {
        Member::factory()->create(['member_code' => 'S-2026-001']);

        $this->postJson('/anggota', ['member_code' => 's-2026-001', 'type' => 'siswa', 'phone' => 'abc'])
            ->assertJsonValidationErrors(['member_code', 'name', 'class', 'phone']);
        $this->postJson('/anggota', ['name' => 'X', 'type' => 'alumni'])->assertJsonValidationErrors('type');
    }

    public function test_update_keeps_balance_and_allows_own_code(): void
    {
        $m = Member::factory()->withBalance(15000)->create(['member_code' => 'S-2026-005']);

        $this->putJson("/anggota/{$m->id}", [
            'member_code' => 'S-2026-005', 'name' => 'Nama Baru', 'type' => 'siswa', 'class' => '8C',
            'is_active' => false, 'balance' => 0,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nama Baru')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.balance', 15000);

        $other = Member::factory()->create();
        $this->putJson("/anggota/{$other->id}", ['member_code' => 's-2026-005', 'name' => 'X', 'type' => 'siswa', 'class' => '7A'])
            ->assertJsonValidationErrors('member_code');
        $this->putJson('/anggota/9999', [])->assertNotFound();
    }

    public function test_status_toggles_active_without_touching_balance(): void
    {
        $m = Member::factory()->withBalance(5000)->create(['name' => 'Budi']);

        $this->patchJson("/anggota/{$m->id}/status", ['aktif' => false])->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('message', fn ($msg) => str_contains($msg, 'dinonaktifkan'));
        $this->assertFalse($m->fresh()->is_active);
        $this->assertEquals(5000, (float) $m->fresh()->balance);
        $this->getJson('/anggota/cari?q=Budi')->assertJsonCount(0, 'data');

        $this->patchJson("/anggota/{$m->id}/status", ['aktif' => true])->assertOk()->assertJsonPath('data.is_active', true);
        $this->patchJson("/anggota/{$m->id}/status", [])->assertJsonValidationErrors('aktif');
        $this->patchJson('/anggota/9999/status', ['aktif' => true])->assertNotFound();
    }

    public function test_show_and_destroy(): void
    {
        $m = Member::factory()->create(['name' => 'Siti']);
        $tx = Transaction::factory()->create(['member_id' => $m->id]);
        $rich = Member::factory()->withBalance(1000)->create();

        $this->getJson("/anggota/{$m->id}/data")->assertJsonPath('data.name', 'Siti');

        $this->deleteJson("/anggota/{$rich->id}")->assertJsonValidationErrors('member');
        $this->deleteJson("/anggota/{$m->id}", ['alasan' => 'Pendaftaran ganda'])->assertOk()->assertJsonPath('message', fn ($msg) => str_contains($msg, 'dipulihkan'));
        $this->assertSoftDeleted($m);
        $this->assertSame('Pendaftaran ganda', Member::withTrashed()->find($m->id)->delete_reason);
        $this->assertSame($m->id, $tx->fresh()->member_id); // riwayat belanja tetap terhubung
        $this->getJson("/anggota/{$m->id}/data")->assertNotFound();
        $this->deleteJson("/anggota/{$rich->id}", ['alasan' => str_repeat('x', 201)])->assertJsonValidationErrors('alasan');
    }

    public function test_list_filters_sorts_and_stats(): void
    {
        Member::factory()->create(['name' => 'Budi', 'class' => '8A']);
        Member::factory()->withBalance(5000)->create(['name' => 'Andi', 'class' => '7A']);
        Member::factory()->teacher()->withBalance(20000)->create(['name' => 'Pak Dedi']);
        Member::factory()->inactive()->withBalance(999)->create(['name' => 'Galih', 'class' => '9B']);

        $this->getJson('/anggota/daftar')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.name', 'Pak Dedi')
            ->assertJsonPath('data.1.name', 'Andi')
            ->assertJsonPath('meta.stats', ['total' => 3, 'siswa' => 2, 'guru' => 1, 'balance' => 25000])
            ->assertJsonPath('meta.classes', ['7A', '8A', '9B']);

        $this->getJson('/anggota/daftar?nonaktif=1')->assertJsonPath('meta.total', 4);
        $this->getJson('/anggota/daftar?jenis=siswa&kelas=8a')->assertJsonPath('data.0.name', 'Budi')->assertJsonPath('meta.total', 1);
        $this->getJson('/anggota/daftar?q=ded')->assertJsonPath('meta.total', 1);
        $this->getJson('/anggota/daftar?jenis=alumni')->assertUnprocessable();
    }

    public function test_quick_search_returns_active_matches_only(): void
    {
        Member::factory()->create(['name' => 'Siti Nurhaliza', 'member_code' => 'S-2024-002']);
        Member::factory()->inactive()->create(['name' => 'Siti Lama']);

        $this->getJson('/anggota/cari?q=siti')->assertJsonCount(1, 'data')->assertJsonPath('data.0.member_code', 'S-2024-002');
        $this->getJson('/anggota/cari?q=2024-002')->assertJsonCount(1, 'data');
        $this->getJson('/anggota/cari')->assertJsonCount(0, 'data');
    }
}
