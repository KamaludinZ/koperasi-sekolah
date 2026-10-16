<?php

namespace Tests\Feature\Panduan;

use App\Models\GuideEntry;
use App\Support\GuideCatalog;
use Database\Seeders\GuideSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GuideSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_and_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('guide_entries', ['key', 'menu_key', 'audience', 'title', 'summary', 'steps', 'tips', 'related',
            'keywords', 'required_permissions', 'source', 'is_stale', 'stale_reason', 'generated_at', 'position']));
    }

    public function test_seeder_loads_catalog_with_matching_shape_and_order(): void
    {
        $this->seed(GuideSeeder::class);
        $catalog = GuideCatalog::entries();
        $this->assertSame(count($catalog), GuideEntry::count());
        $this->assertSame(array_keys(GuideCatalog::forAudience('petugas')), GuideEntry::for('petugas')->pluck('key')->all());

        $kasir = GuideEntry::where('key', 'kasir')->first();
        $this->assertSame(array_intersect_key($catalog['kasir'], array_flip(['menu', 'audience', 'title', 'summary', 'steps', 'tips'])),
            array_intersect_key($kasir->toGuide(), array_flip(['menu', 'audience', 'title', 'summary', 'steps', 'tips'])));
        $inSteps = array_flip(array_filter(array_column($kasir->steps, 'route')));
        $this->assertSame([], array_diff_key($catalog['kasir']['related'], $kasir->related, $inSteps)); // terkait kurasi tetap ada kecuali yang sudah di langkah
        $this->assertSame(['kasir'], $kasir->required_permissions);
        $this->assertSame([], GuideEntry::where('key', 'akun')->first()->required_permissions); // panduan umum: tanpa syarat menu
        $this->assertSame('auto', $kasir->source);
        $this->assertNotNull($kasir->generated_at);
    }

    public function test_seeder_is_idempotent_and_keeps_manual_edits(): void
    {
        $this->seed(GuideSeeder::class);
        GuideEntry::where('key', 'kasir')->update(['title' => 'Judul suntingan admin', 'source' => 'manual']);
        GuideEntry::where('key', 'kas')->update(['title' => 'Judul lama otomatis']);

        $this->seed(GuideSeeder::class);
        $this->assertSame(count(GuideCatalog::entries()), GuideEntry::count());
        $this->assertSame('Judul suntingan admin', GuideEntry::where('key', 'kasir')->value('title')); // manual tidak ditimpa
        $this->assertSame(GuideCatalog::entries()['kas']['title'], GuideEntry::where('key', 'kas')->value('title')); // otomatis diperbarui
    }

    public function test_key_is_unique(): void
    {
        $this->seed(GuideSeeder::class);
        $this->expectException(QueryException::class);
        DB::table('guide_entries')->insert(['key' => 'kasir', 'title' => 'x', 'summary' => 'x', 'steps' => '[]', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_postgres_checks(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK hanya di PostgreSQL.');
        }
        foreach ([['audience' => 'raja'], ['source' => 'tebak'], ['steps' => '{"a":1}']] as $bad) {
            try {
                DB::transaction(fn () => DB::table('guide_entries')->insert([...['key' => 'x'.md5(json_encode($bad)), 'title' => 'x', 'summary' => 'x', 'steps' => '[]', 'created_at' => now(), 'updated_at' => now()], ...$bad]));
                $this->fail('harus ditolak: '.json_encode($bad));
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }
}
