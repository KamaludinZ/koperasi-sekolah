<?php

namespace Tests\Feature\Pengaturan;

use App\Livewire\Pengaturan\Receipt;
use App\Models\Member;
use App\Models\Transaction;
use App\Services\ReceiptPdfService;
use App\Services\ReceiptPresenter;
use App\Support\MockData;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReceiptSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); // admin
        $this->seed(ProductSeeder::class);
    }

    public function test_page_shows_live_preview(): void
    {
        $this->get('/pengaturan/struk')->assertOk()->assertSee('Kepala struk')->assertSee('Pratinjau · 58 mm')->assertSee('Buku Tulis 38 Lembar');

        Livewire::test(Receipt::class)
            ->set('header', 'KOPSIS AN-NUR')
            ->set('note', 'Barang tidak dapat ditukar.')
            ->set('show_member', false)
            ->assertSee('KOPSIS AN-NUR')
            ->assertSee('Barang tidak dapat ditukar.')
            ->assertDontSee('Andi Pratama (7A)')
            ->set('paper', '80mm')
            ->assertSee('Pratinjau · 80 mm');
    }

    public function test_saved_settings_shape_real_receipts(): void
    {
        $m = Member::factory()->create(['name' => 'Siti', 'class' => '8B']);
        $tx = Transaction::factory()->withItems([$this->pid('ATK001') => 1])->create(['member_id' => $m->id]);

        Livewire::test(Receipt::class)
            ->set('header', 'KOPSIS AN-NUR')
            ->set('subheader', '')
            ->set('show_address', false)
            ->set('show_phone', true)
            ->set('show_cashier', false)
            ->set('note', 'Simpan struk ini.')
            ->set('footer', "Terima kasih\nSemoga berkah")
            ->set('paper', '80mm')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $this->assertSame('80mm', MockData::receipt()['paper']);
        $this->assertSame(80, ReceiptPresenter::paperWidth(app(ReceiptPresenter::class)->profile()));

        $this->get(route('kasir.transactions.receipt', $tx).'?cetak=0')
            ->assertSee('KOPSIS AN-NUR')
            ->assertSee(MockData::profile()['school'])   // sub-judul kosong = ikut profil
            ->assertSee('Telp. 0812-3456-7890')
            ->assertDontSee('Jl. Pendidikan')
            ->assertSee('Siti (8B)')
            ->assertSee('Simpan struk ini.')
            ->assertSee('size: 80mm auto', false);

        $this->assertStringStartsWith('%PDF', app(ReceiptPdfService::class)->render($tx));
        $this->get(route('kasir.transactions.show', $tx))->assertJsonPath('profile.receipt.header', 'KOPSIS AN-NUR');
    }

    public function test_validation_and_reset(): void
    {
        Livewire::test(Receipt::class)
            ->set('header', str_repeat('x', 41))
            ->set('footer', '')
            ->set('note', "1\n2\n3\n4")
            ->set('paper', 'A4')
            ->call('save')
            ->assertHasErrors(['header', 'footer', 'note', 'paper'])
            ->call('resetDefaults')
            ->assertSet('paper', '58mm')
            ->assertSet('footer', MockData::defaultReceipt()['footer'])
            ->assertHasNoErrors();
    }
}
