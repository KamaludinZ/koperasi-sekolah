<?php

namespace Tests\Feature\Pinjaman;

use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoanEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Member $rina;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rina = Member::factory()->teacher()->create(['name' => 'Bu Rina', 'member_code' => 'G-2026-777']);
        Loan::factory()->running()->create(['code' => 'PJM-2026-0001', 'member_id' => $this->rina->id, 'payment_method' => 'transfer',
            'principal' => 1000000, 'interest' => 50000, 'outstanding' => 700000]);
        Loan::factory()->create(['code' => 'PJM-2026-0002', 'member_id' => $this->rina->id, 'payment_method' => 'tunai']);
        $budi = Member::factory()->teacher()->create(['name' => 'Pak Budi', 'member_code' => 'G-2026-001']); // nama tetap: faker bisa memuat "rina"
        Loan::factory()->overdue()->create(['code' => 'PJM-2026-0003', 'member_id' => $budi->id, 'principal' => 500000, 'interest' => 0, 'outstanding' => 200000]);
        Loan::factory()->create(['code' => 'PJM-2026-0004', 'member_id' => $budi->id, 'status' => 'lunas']);
    }

    private function codes(array $query = []): array
    {
        return collect($this->getJson(route('pinjaman.list', $query))->assertOk()->json('data'))->pluck('code')->sort()->values()->all();
    }

    public function test_list_returns_paginated_rows_with_stats(): void
    {
        $res = $this->getJson(route('pinjaman.list'))->assertOk()
            ->assertJsonPath('meta.total', 4)->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.stats.counts.menunggak', 1)->assertJsonPath('meta.stats.outstanding', 900000)
            ->assertJsonPath('data.0.code', 'PJM-2026-0003') // menunggak paling atas
            ->assertJsonStructure(['data' => [['id', 'code', 'member' => ['id', 'code', 'name'], 'principal', 'interest', 'total', 'tenor_months',
                'monthly', 'payment_method', 'payment_label', 'status', 'status_label', 'outstanding', 'applied_at']], 'meta' => ['statuses', 'methods'], 'links']);
        $this->assertSame(['PJM-2026-0001', 'PJM-2026-0002', 'PJM-2026-0003', 'PJM-2026-0004'], collect($res->json('data'))->pluck('code')->sort()->values()->all());
    }

    public function test_filters(): void
    {
        $this->assertSame(['PJM-2026-0001', 'PJM-2026-0002'], $this->codes(['q' => 'RINA']));
        $this->assertSame(['PJM-2026-0001', 'PJM-2026-0002'], $this->codes(['q' => 'g-2026-777']));
        $this->assertSame(['PJM-2026-0003'], $this->codes(['q' => '0003']));
        $this->assertSame(['PJM-2026-0004'], $this->codes(['status' => 'lunas']));
        $this->assertSame(['PJM-2026-0001'], $this->codes(['metode' => 'transfer']));
        $this->assertSame(['PJM-2026-0002'], $this->codes(['anggota' => $this->rina->id, 'status' => 'diajukan']));
        $this->assertSame([], $this->codes(['q' => '%']));   // wildcard LIKE tidak mencocokkan semua
        $this->assertSame([], $this->codes(['q' => '____'])); // underscore harfiah
    }

    public function test_pagination(): void
    {
        Loan::factory()->count(30)->create();
        $this->getJson(route('pinjaman.list', ['per_halaman' => 25, 'page' => 2]))->assertOk()
            ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.last_page', 2)->assertJsonCount(9, 'data')
            ->assertJsonPath('links.next', null);
        $this->assertStringContainsString('per_halaman=25', $this->getJson(route('pinjaman.list', ['per_halaman' => 25]))->json('links.next'));
    }

    public function test_validation(): void
    {
        $this->getJson(route('pinjaman.list', ['status' => 'macet', 'metode' => 'kartu', 'per_halaman' => 7, 'page' => 0, 'anggota' => 'x']))
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'metode', 'per_halaman', 'page', 'anggota'])
            ->assertJsonPath('errors.status.0', 'Status pinjaman tidak dikenal.');
        $this->getJson(route('pinjaman.list', ['q' => str_repeat('a', 101)]))->assertJsonValidationErrors('q');
    }

    public function test_detail_endpoint(): void
    {
        $loan = Loan::where('code', 'PJM-2026-0001')->first();
        LoanInstallment::create(['loan_id' => $loan->id, 'sequence' => 1, 'due_date' => today()->addMonth(), 'amount' => 350000, 'paid_amount' => 350000,
            'paid_at' => today(), 'payment_method' => 'transfer', 'status' => 'dibayar']);
        $this->getJson(route('pinjaman.data', $loan))->assertOk()
            ->assertJsonPath('data.loan.code', 'PJM-2026-0001')->assertJsonPath('data.summary.paid', 350000)
            ->assertJsonPath('data.installments.0.status', 'dibayar')->assertJsonPath('data.installments.0.late', false);
        $this->getJson('/pinjaman/999999/data')->assertNotFound();
    }

    public function test_access(): void
    {
        $this->loginAs('pengurus');
        $this->getJson(route('pinjaman.list'))->assertOk();
        $this->loginAs('kasir');
        $this->getJson(route('pinjaman.list'))->assertForbidden();
        $this->getJson(route('pinjaman.data', Loan::first()))->assertForbidden();
        auth()->logout();
        $this->getJson(route('pinjaman.list'))->assertUnauthorized();
    }
}
