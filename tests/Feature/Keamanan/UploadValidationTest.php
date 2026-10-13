<?php

namespace Tests\Feature\Keamanan;

use App\Services\SettingsService;
use App\Services\UploadPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;
use ZipArchive;

class UploadValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function check(string $kind, UploadedFile $file): array
    {
        $v = Validator::make(['berkas' => $file], ['berkas' => UploadPolicy::rules($kind)], UploadPolicy::messages($kind, 'berkas'));

        return $v->errors()->get('berkas');
    }

    private function xlsx(string $name, bool $macro = false, bool $workbook = true): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
        if ($workbook) {
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook/>');
        }
        if ($macro) {
            $zip->addFromString('xl/vbaProject.bin', 'macro');
        }
        $zip->close();

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_logo_accepts_real_images_within_limits(): void
    {
        $this->assertSame([], $this->check('logo', UploadedFile::fake()->image('logo.png', 128, 128)));
        $this->assertSame([], $this->check('logo', UploadedFile::fake()->image('logo.jpeg', 128, 128)));
    }

    public function test_logo_rejects_svg_disguised_scripts_and_wrong_content(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->assertNotEmpty($this->check('logo', $svg));

        $double = UploadedFile::fake()->image('logo.php.png', 128, 128);
        $this->assertContains('Nama atau jenis berkas tidak diizinkan.', $this->check('logo', $double));

        $text = UploadedFile::fake()->createWithContent('logo.png', 'bukan gambar');
        $this->assertNotEmpty($this->check('logo', $text));

        $img = UploadedFile::fake()->image('logo.png', 128, 128);
        file_put_contents($img->getRealPath(), '<?php echo 1; ?>', FILE_APPEND);
        $this->assertContains('Berkas berisi kode yang tidak diizinkan.', $this->check('logo', $img));

        $this->assertNotEmpty($this->check('logo', UploadedFile::fake()->image('kecil.png', 32, 32)));
    }

    public function test_limits_follow_security_settings(): void
    {
        $big = UploadedFile::fake()->image('logo.png', 128, 128)->size(300);
        $this->assertSame([], $this->check('logo', $big));

        app(SettingsService::class)->save('security', ['logo_max_kb' => 128, 'logo_types' => ['png']]);
        $this->assertContains('Ukuran logo maksimal 128 KB.', $this->check('logo', $big));
        $this->assertContains('Gunakan berkas PNG.', $this->check('logo', UploadedFile::fake()->image('logo.jpg', 128, 128)));
    }

    public function test_import_accepts_csv_and_clean_xlsx_only(): void
    {
        $csv = UploadedFile::fake()->createWithContent('barang.csv', "kode;nama;harga\nATK010;Spidol;8000\n");
        $this->assertSame([], $this->check('import', $csv));
        $this->assertSame([], $this->check('import', $this->xlsx('barang.xlsx')));

        $this->assertContains('Berkas Excel berisi makro dan ditolak. Simpan ulang sebagai .xlsx biasa.', $this->check('import', $this->xlsx('barang.xlsx', macro: true)));
        $this->assertContains('Berkas bukan buku kerja Excel (.xlsx).', $this->check('import', $this->xlsx('barang.xlsx', workbook: false)));
        $this->assertContains('Nama atau jenis berkas tidak diizinkan.', $this->check('import', $this->xlsx('barang.xlsm')));
        $this->assertContains('Berkas CSV harus berupa teks.', $this->check('import', UploadedFile::fake()->createWithContent('barang.csv', "kode\0nama")));
        $this->assertNotEmpty($this->check('import', UploadedFile::fake()->createWithContent('barang.exe', 'MZ')));
        $this->assertNotEmpty($this->check('import', UploadedFile::fake()->createWithContent('kosong.csv', '')));

        app(SettingsService::class)->save('security', ['import_types' => ['csv'], 'import_max_kb' => 256]);
        $this->assertNotEmpty($this->check('import', $this->xlsx('barang.xlsx')));
        $this->assertContains('Ukuran berkas maksimal 256 KB.', $this->check('import', UploadedFile::fake()->create('besar.csv', 300, 'text/csv')));
    }

    public function test_logo_endpoint_uses_policy(): void
    {
        $this->postJson('/pengaturan/data/profile/logo', ['logo' => UploadedFile::fake()->image('logo.php.png', 128, 128)])
            ->assertJsonValidationErrors(['logo' => 'Nama atau jenis berkas tidak diizinkan.']);
        $this->postJson('/pengaturan/data/profile/logo', ['logo' => UploadedFile::fake()->image('logo.webp', 128, 128)])->assertCreated();
    }
}
