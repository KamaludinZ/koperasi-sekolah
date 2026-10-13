<?php

namespace Tests\Feature\Pengadaan;

use App\Livewire\Pengadaan\SupplierForm;
use App\Models\ActivityLog;
use App\Models\Supplier;
use Database\Seeders\ProductSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SupplierFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ProductSeeder::class, SupplierSeeder::class]);
    }

    public function test_pages_and_links(): void
    {
        $id = Supplier::where('name', 'UD Berkah Pangan')->value('id');
        $this->get('/pengadaan/supplier/baru')->assertOk()->assertSee('Supplier baru')->assertSee('Alat Tulis');
        $this->get("/pengadaan/supplier/{$id}/ubah")->assertOk()->assertSee('Ubah supplier');
        $this->get('/pengadaan/supplier/999/ubah')->assertNotFound();
        $this->loginAs('kasir@koperasi.test');
        $this->get('/pengadaan/supplier/baru')->assertForbidden();
    }

    public function test_create_with_validation(): void
    {
        Livewire::test(SupplierForm::class)
            ->call('save')->assertHasErrors(['name' => 'required', 'phone' => 'required'])->assertDispatched('toast', type: 'error')
            ->set('name', 'cv sinar ilmu')->set('phone', 'abc')->call('save')->assertHasErrors(['name', 'phone'])
            ->set('name', '  Toko  Buku Cahaya ')->set('phone', '0812-1111-2222')->set('termsChoice', 'lain')->call('save')->assertHasErrors('customDays')
            ->set('customDays', 21)->set('categories', ['Alat Tulis', 'Seragam'])->call('save')
            ->assertHasNoErrors()->assertRedirect(route('pengadaan.supplier'));

        $s = Supplier::with('categories')->where('name', 'Toko Buku Cahaya')->firstOrFail();
        $this->assertSame([21, ['Alat Tulis', 'Seragam'], true], [$s->terms_days, $s->categories->pluck('name')->all(), $s->is_active]);
        $this->assertSame('Supplier Toko Buku Cahaya ditambahkan.', session('toast')['message']);
        $this->assertTrue(ActivityLog::where('event', 'supplier.created')->exists());
    }

    public function test_edit_updates_and_audits(): void
    {
        $sinar = Supplier::where('name', 'CV Sinar Ilmu')->first();
        $c = Livewire::test(SupplierForm::class, ['supplier' => $sinar->id])
            ->assertSet('name', 'CV Sinar Ilmu')->assertSet('termsChoice', '30')->assertSet('categories', ['Alat Tulis']);
        $c->set('termsChoice', '14')->set('is_active', false)->set('categories', ['Alat Tulis', 'Seragam'])->call('save')->assertHasNoErrors();

        $sinar->refresh();
        $this->assertSame([14, false], [$sinar->terms_days, $sinar->is_active]);
        $logs = ActivityLog::where('event', 'supplier.updated')->orderBy('id')->get();
        $this->assertSame(['terms_days' => 30, 'is_active' => true], $logs[0]->old_values);
        $this->assertSame(['kategori' => 'Alat Tulis, Seragam'], $logs[1]->new_values);
    }
}
