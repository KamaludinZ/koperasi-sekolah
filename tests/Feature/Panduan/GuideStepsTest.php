<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Panduan\Index;
use App\Models\User;
use App\Support\GuideCatalog;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GuideStepsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
        $this->admin = User::where('email', 'admin@koperasi.test')->first();
        $this->actingAs($this->admin);
    }

    public function test_summary_then_numbered_steps_in_order_with_progress_controls(): void
    {
        $steps = array_column(GuideCatalog::entries()['pinjaman']['steps'], 'text');
        $c = Livewire::test(Index::class)->call('open', 'pinjaman')
            ->assertSeeHtmlInOrder(['data-guide-summary', 'data-guide-steps', ...array_map('e', $steps)])
            ->assertSeeHtml('x-data="guideProgress(')
            ->assertSeeText('dari '.count($steps).' langkah')
            ->assertSeeHtml('data-guide-print');
        foreach (array_keys($steps) as $i) {
            $c->assertSeeHtml('data-guide-step-toggle="'.$i.'"');
        }
        // kunci kemajuan per petugas & panduan
        $c->assertSeeHtml('panduan:'.$this->admin->id.':pinjaman');
    }

    public function test_prev_next_navigation(): void
    {
        $keys = array_keys(GuideCatalog::forAudience('petugas'));
        Livewire::test(Index::class)->call('open', $keys[0])
            ->assertDontSeeHtml('data-guide-prev=')->assertSeeHtml('data-guide-next="'.$keys[1].'"')
            ->call('open', $keys[1])->assertSeeHtml('data-guide-prev="'.$keys[0].'"')
            ->call('open', end($keys))->assertDontSeeHtml('data-guide-next=');
    }

    public function test_print_hides_navigation_chrome(): void
    {
        $html = $this->get('/panduan?menu=kasir')->assertOk()->getContent();
        $this->assertStringContainsString('print:hidden fixed inset-y-0', $html); // menu samping tidak ikut tercetak
        $this->assertStringContainsString('aria-label="Daftar panduan"', $html);
    }
}
