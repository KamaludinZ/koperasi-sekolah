<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Portal\Guide;
use App\Models\Member;
use App\Support\GuideCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Panduan portal anggota dari header: topik sesuai halaman, kembali ke halaman asal, centang langkah, navigasi. */
class PortalGuideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsMember($this->member = Member::factory()->create());
    }

    private Member $member;

    public function test_header_button_carries_topic_of_current_page(): void
    {
        foreach (['portal.simpanan' => 'portal.simpanan', 'portal.pinjaman' => 'portal.pinjaman', 'portal.riwayat' => 'portal.riwayat', 'portal.pesanan' => 'portal.belanja', 'portal.belanja' => 'portal.belanja', 'portal.akun' => 'portal.akun'] as $route => $topic) {
            $this->get(route($route))->assertOk()
                ->assertSeeHtml('data-portal-guide-topic="'.$topic.'"')
                ->assertSeeHtml('href="'.e(route('portal.panduan', ['topik' => $topic, 'dari' => $route])).'"');
        }
        $this->get('/portal/panduan')->assertOk()->assertDontSeeHtml('data-portal-guide-topic')
            ->assertSeeHtml('data-portal-guide-button')->assertSeeHtml('aria-current="page"');
    }

    public function test_every_member_topic_and_alias_maps_to_existing_guide(): void
    {
        $keys = array_keys(GuideCatalog::forAudience('anggota'));
        foreach ([...array_column(GuideCatalog::forAudience('anggota'), 'portal_route'), ...array_keys(GuideCatalog::PORTAL_ALIASES)] as $route) {
            $this->assertContains(GuideCatalog::portalTopicFor($route), $keys, $route);
        }
        $this->assertNull(GuideCatalog::portalTopicFor('kasir'));
        $this->assertNull(GuideCatalog::portalTopicFor(null));
    }

    public function test_opened_from_page_shows_context_and_back_link(): void
    {
        $this->get('/portal/panduan?topik=portal.pinjaman&dari=portal.pinjaman')->assertOk()
            ->assertSeeHtml('data-portal-guide="portal.pinjaman"')->assertSeeHtml('data-portal-guide-context')
            ->assertSeeHtml('data-portal-guide-back')->assertSeeHtml('href="'.route('portal.pinjaman').'"');
    }

    public function test_back_link_only_for_safe_portal_pages(): void
    {
        foreach (['kasir', 'portal.logout', 'portal.pesanan.show', 'portal.panduan', 'https://contoh.test', 'portal.tidak-ada', '../admin'] as $bad) {
            Livewire::withQueryParams(['dari' => $bad])->test(Guide::class)->assertSet('from', '')->assertDontSeeHtml('data-portal-guide-back');
        }
        Livewire::withQueryParams(['dari' => 'portal.simpanan'])->test(Guide::class)->assertSet('from', 'portal.simpanan');
    }

    public function test_progress_is_per_member_and_topic_with_prev_next(): void
    {
        Livewire::test(Guide::class)->call('open', 'portal.simpanan')
            ->assertSeeHtml("portal-panduan:{$this->member->id}:portal.simpanan")
            ->assertSeeHtml('data-portal-step-toggle="0"')
            ->assertSeeHtml('data-portal-guide-prev="portal.beranda"')->assertSeeHtml('data-portal-guide-next="portal.pinjaman"');
        Livewire::test(Guide::class)->assertDontSeeHtml('data-portal-guide-prev=')->call('open', 'portal.riwayat')->assertSeeHtml('data-portal-guide-next="portal.akun"')->call('open', 'portal.akun')->assertDontSeeHtml('data-portal-guide-next=');
    }

    public function test_step_links_skip_checkout_and_parameterized_pages(): void
    {
        $html = Livewire::test(Guide::class)->call('open', 'portal.belanja')->html();
        $this->assertStringNotContainsString('href="'.route('portal.checkout').'"', $html);
        $this->assertStringContainsString('href="'.route('portal.belanja').'"', $html);
    }
}
