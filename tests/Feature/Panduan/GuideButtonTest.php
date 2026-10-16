<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Portal\Guide;
use App\Models\Member;
use App\Models\User;
use App\Support\GuideCatalog;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GuideButtonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
    }

    public function test_staff_header_has_context_aware_guide_button(): void
    {
        $this->actingAs(User::where('email', 'admin@koperasi.test')->first());
        $this->get('/pinjaman')->assertOk()
            ->assertSeeHtml('data-guide-button="pinjaman"')
            ->assertSeeHtml('href="'.route('panduan', ['menu' => 'pinjaman']).'"')
            ->assertSeeHtml('aria-label="Panduan menu Pinjaman"');
        $this->get('/akun/kata-sandi')->assertOk()->assertSeeHtml('data-guide-button="umum"')->assertSeeHtml('href="'.route('panduan').'"');
        $this->get(route('panduan', ['menu' => 'pinjaman']))->assertOk()->assertSeeHtml('data-guide="pinjaman"');
    }

    public function test_guests_see_no_guide_button(): void
    {
        $this->get('/')->assertDontSeeHtml('data-guide-button');
    }

    public function test_portal_header_has_guide_button_and_member_guide_works(): void
    {
        auth()->logout();
        $m = Member::factory()->create();
        $this->actingAsMember($m);
        $this->get('/portal')->assertOk()->assertSeeHtml('data-portal-guide-button')->assertSeeHtml('href="'.e(route('portal.panduan', ['topik' => 'portal.beranda', 'dari' => 'portal'])).'"');
        $this->get('/portal/panduan')->assertOk()->assertSeeText('Panduan Portal')->assertSeeHtml('data-portal-guide="portal.beranda"');
        Livewire::test(Guide::class)->call('open', 'portal.belanja')->assertSeeText('PIN 6 digit')->call('open', 'kasir')->assertSet('selected', '');
    }

    public function test_member_guide_only_contains_member_entries_and_staff_guide_excludes_them(): void
    {
        $this->assertSame([], array_filter(array_keys(GuideCatalog::forAudience('anggota')), fn ($k) => ! str_starts_with($k, 'portal.')));
        $this->actingAs(User::where('email', 'admin@koperasi.test')->first());
        $this->get('/panduan')->assertDontSeeHtml('data-guide-item="portal.belanja"');
    }

    public function test_portal_guide_requires_member_login(): void
    {
        auth()->logout();
        $this->get('/portal/panduan')->assertRedirect(route('portal.login'));
    }
}
