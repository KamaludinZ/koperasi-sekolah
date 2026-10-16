<?php

namespace Tests\Feature\Panduan;

use App\Models\GuideChange;
use App\Models\GuideEntry;
use App\Models\GuideOverride;
use App\Models\GuideVersion;
use App\Services\GuideOverrideService;
use Database\Seeders\GuideSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Service simpan suntingan manual per bagian: normalisasi, simpan banyak bagian satu versi, bentrok, kembalikan. */
class GuideOverrideServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserSeeder::class, GuideSeeder::class]);
    }

    private function svc(): GuideOverrideService
    {
        return app(GuideOverrideService::class);
    }

    private function kas(): GuideEntry
    {
        return GuideEntry::where('key', 'kas')->first();
    }

    public function test_normalize_sections(): void
    {
        $this->assertSame('Judul rapi', $this->svc()->normalize('title', "  Judul \t rapi  "));
        $this->assertSame([['text' => 'Langkah satu', 'route' => 'kas'], ['text' => 'Dua']],
            $this->svc()->normalize('steps', [['text' => ' Langkah  satu ', 'route' => 'kas'], ['text' => ''], 'Dua']));
        $this->assertSame(['A', 'B'], $this->svc()->normalize('tips', [' A ', '', 'B']));
        foreach ([['title', ''], ['summary', 'pendek'], ['steps', []], ['steps', 'bukan daftar'], ['tips', array_fill(0, 11, 'x')], ['related', []]] as [$f, $v]) {
            try {
                $this->svc()->normalize($f, $v);
                $this->fail("{$f} seharusnya ditolak");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame([['text' => 'x']], $this->svc()->normalize('steps', [['text' => 'x', 'route' => 'rute.palsu']])); // tautan tak dikenal dibuang
    }

    public function test_save_many_single_version_and_change_rows(): void
    {
        $before = GuideVersion::count();
        $r = $this->svc()->saveMany($this->kas(), ['title' => 'Judul baru kas', 'tips' => ['Tips baru']], null, 'Bersama');
        $this->assertSame(['title', 'tips'], $r['changed']);
        $this->assertSame($before + 1, GuideVersion::count());
        $this->assertSame(2, GuideChange::where('action', 'override')->where('guide_version_id', $r['version']->id)->count());
        $this->assertSame(['Bersama', 'Bersama'], GuideOverride::orderBy('field')->pluck('note')->all());
    }

    public function test_validation_errors_keyed_by_section_and_nothing_saved(): void
    {
        try {
            $this->svc()->saveMany($this->kas(), ['title' => '', 'summary' => 'pendek', 'tips' => ['ok']], null);
            $this->fail('harus gagal');
        } catch (ValidationException $e) {
            $this->assertSame(['title', 'summary'], array_keys($e->errors()));
        }
        $this->assertSame(0, GuideOverride::count());
    }

    public function test_conflict_detection(): void
    {
        $hash = $this->svc()->fingerprint($this->kas(), 'summary');
        $this->svc()->save($this->kas(), 'summary', 'Ringkasan dari admin pertama', null, null, $hash);
        try {
            $this->svc()->save($this->kas(), 'summary', 'Ringkasan dari admin kedua', null, null, $hash);   // sidik jari lama
            $this->fail('bentrok seharusnya ditolak');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('summary', $e->errors());
        }
        $this->assertSame('Ringkasan dari admin pertama', GuideOverride::where('field', 'summary')->value('value'));
        $fresh = $this->svc()->fingerprint($this->kas(), 'summary');
        $this->svc()->save($this->kas(), 'summary', 'Ringkasan dari admin kedua', null, null, $fresh);
        $this->assertSame('Ringkasan dari admin kedua', GuideOverride::where('field', 'summary')->value('value'));
    }

    public function test_revert(): void
    {
        $this->svc()->save($this->kas(), 'title', 'Judul admin', null);
        $this->assertTrue($this->svc()->revert($this->kas(), 'title', null, 'Kembali'));
        $this->assertFalse($this->svc()->revert($this->kas(), 'title', null));
        $this->assertSame(1, GuideChange::where('action', 'revert')->count());
    }
}
