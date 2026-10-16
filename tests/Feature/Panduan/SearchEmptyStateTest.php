<?php

namespace Tests\Feature\Panduan;

use App\Livewire\Panduan\Index;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SearchEmptyStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserSeeder::class);
    }

    private function emptyBlock(string $html): string
    {
        preg_match('/<div[^>]*data-guide-empty[^>]*>/', $html, $m);

        return $m[0] ?? '';
    }

    public function test_empty_state_visible_on_load_when_nothing_matches(): void
    {
        $this->actingAs(User::where('email', 'admin@koperasi.test')->first());
        $html = $this->get('/panduan?q=zzqqxx')->assertOk()
            ->assertSeeText('Panduan tidak ditemukan')->assertSeeText('Hapus pencarian')
            ->assertSeeHtml('data-guide-suggestion="retur"')->assertSeeHtml('data-guide-clear')
            ->getContent();
        $this->assertStringNotContainsString(' hidden', $this->emptyBlock($html));
        $this->assertStringNotContainsString('data-guide-card=', $html);
    }

    public function test_empty_state_hidden_when_there_are_results(): void
    {
        $this->actingAs(User::where('email', 'admin@koperasi.test')->first());
        foreach (['/panduan', '/panduan?q=retur'] as $url) {
            $this->assertStringContainsString(' hidden', $this->emptyBlock($this->get($url)->getContent()), $url);
        }
    }

    public function test_every_suggestion_finds_something_for_admin(): void
    {
        $this->actingAs(User::where('email', 'admin@koperasi.test')->first());
        foreach (Index::SUGGESTIONS as $s) {
            $this->assertNotSame([], Livewire::test(Index::class)->set('search', $s)->instance()->groups, $s);
        }
    }

    public function test_hint_when_only_restricted_guides_match(): void
    {
        $this->actingAs(User::where('email', 'kasir@koperasi.test')->first());
        Livewire::test(Index::class)->set('search', 'cicilan') // hanya panduan Pinjaman (terbatas untuk kasir)
            ->assertSeeHtml('data-guide-empty-restricted')->assertSeeText('Ada 1 panduan menu terbatas yang cocok')
            ->set('showRestricted', true)
            ->assertDontSeeHtml('data-guide-empty-restricted')->assertSeeHtml('data-guide-card="pinjaman"');
    }

    public function test_search_text_is_escaped_in_empty_state(): void
    {
        $this->actingAs(User::where('email', 'admin@koperasi.test')->first());
        $this->get('/panduan?q='.urlencode('<b>x</b>'))->assertOk()->assertDontSee('<b>x</b>', false);
    }
}
