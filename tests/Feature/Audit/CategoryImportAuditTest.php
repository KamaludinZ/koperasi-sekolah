<?php

namespace Tests\Feature\Audit;

use App\Livewire\Audit\Changes;
use App\Livewire\Audit\Index;
use App\Livewire\Barang\ImportPreview;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Services\CategoryService;
use App\Services\ProductImportService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\MakesProductImports;
use Tests\TestCase;

class CategoryImportAuditTest extends TestCase
{
    use MakesProductImports;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(ProductSeeder::class);
    }

    public function test_category_lifecycle_is_audited_and_listed_in_changes(): void
    {
        $svc = app(CategoryService::class);
        $cat = $svc->create(['name' => 'Aksesoris']);
        $svc->update($cat, ['name' => 'Aksesoris', 'color' => 'rose', 'description' => 'Pin & gantungan']);
        $svc->reorder([$cat->id]);
        $svc->delete($cat, null, 'Tidak dipakai');
        $cat->restore();

        $this->assertSame(['category.created', 'category.updated', 'category.reordered', 'category.deleted', 'category.restored'],
            ActivityLog::where('event', 'like', 'category.%')->orderBy('id')->pluck('event')->all());
        $this->assertSame('Tidak dipakai', ActivityLog::where('event', 'category.deleted')->value('reason'));

        $c = Livewire::test(Changes::class)->call('setEntity', 'kategori');
        $this->assertCount(5, $c->instance()->rows);
        $reorder = collect($c->instance()->rows)->firstWhere('subject_label', 'Urutan kategori');
        $this->assertStringStartsWith('Aksesoris, Alat Tulis', $reorder['changes']['urutan']['to']);
        $c->call('show', $reorder['id'])->assertSee('Urutan')->assertDontSee('Lihat semua riwayat kategori ini');
        $c->assertSee('Warna');
    }

    public function test_import_steps_are_audited_and_linked(): void
    {
        $import = $this->makeImport();
        $svc = app(ProductImportService::class);
        $svc->updateRow($import, 7, ['kategori' => 'Makanan', 'nama' => 'Kacang Atom']);   // nama tidak berubah
        $svc->updateRow($import, 7, ['kategori' => 'Makanan']);                             // tidak ada perubahan → tidak dicatat
        $svc->setSkipped($import, 13, true);
        $svc->setSkipped($import, 13, true);                                                  // sudah dilewati → tidak dicatat
        $svc->skipProblems($import);
        Livewire::test(ImportPreview::class, ['import' => $import->id])->call('commit');

        $edit = ActivityLog::where('event', 'import.row_edited')->sole();
        $this->assertSame([['kategori' => 'Snack'], ['kategori' => 'Makanan']], [$edit->old_values, $edit->new_values]);
        $this->assertSame(2, ActivityLog::where('event', 'import.row_skipped')->count());
        $this->assertSame(1, ActivityLog::where('event', 'import.uploaded')->count());
        $this->assertSame(1, ActivityLog::where('event', 'import.completed')->count());
        $this->assertSame(6, ActivityLog::where('event', 'product.created')->where('reason', 'Impor barang-semester-ganjil.csv')->count());

        Livewire::test(Index::class)->call('show', $edit->id)->assertSee(route('barang.impor.detail', $import->id), false);
    }
}
