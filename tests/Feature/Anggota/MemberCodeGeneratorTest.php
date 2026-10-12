<?php

namespace Tests\Feature\Anggota;

use App\Models\Member;
use App\Services\MemberCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberCodeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private MemberCodeGenerator $codes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-12 09:00:00');
        $this->codes = app(MemberCodeGenerator::class);
    }

    public function test_first_code_per_type_and_year(): void
    {
        $this->assertSame('S-2026-001', $this->codes->next('siswa'));
        $this->assertSame('G-2026-001', $this->codes->next('guru'));
        $this->assertSame('S-2025-001', $this->codes->next('siswa', 2025));
    }

    public function test_continues_from_highest_number_ignoring_other_prefixes(): void
    {
        Member::factory()->create(['member_code' => 'S-2026-001']);
        Member::factory()->create(['member_code' => 'S-2026-007']); // ada celah
        Member::factory()->create(['member_code' => 'S-2025-050']);
        Member::factory()->create(['member_code' => 'S-2026-X1']); // kode manual tak berurut
        Member::factory()->teacher()->create(['member_code' => 'G-2026-003']);

        $this->assertSame('S-2026-008', $this->codes->next('siswa'));
        $this->assertSame('G-2026-004', $this->codes->next('guru'));
    }

    public function test_grows_past_three_digits(): void
    {
        Member::factory()->create(['member_code' => 'S-2026-999']);

        $this->assertSame('S-2026-1000', $this->codes->next('siswa'));
    }

    public function test_create_assigns_code_or_keeps_manual_one(): void
    {
        $auto = $this->codes->create(['name' => 'Andi', 'type' => 'siswa', 'class' => '7A', 'joined_at' => '2026-10-12']);
        $teacher = $this->codes->create(['name' => 'Bu Rina', 'type' => 'guru', 'joined_at' => '2026-10-12']);
        $manual = $this->codes->create(['member_code' => 'osis-01', 'name' => 'Siti', 'class' => '8B', 'joined_at' => '2026-10-12']);

        $this->assertSame('S-2026-001', $auto->member_code);
        $this->assertSame('G-2026-001', $teacher->member_code);
        $this->assertSame('OSIS-01', $manual->member_code);
        $this->assertSame('S-2026-002', $this->codes->next('siswa'));
    }
}
