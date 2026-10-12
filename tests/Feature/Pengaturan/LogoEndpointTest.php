<?php

namespace Tests\Feature\Pengaturan;

use App\Models\ActivityLog;
use App\Services\SettingsService;
use App\Support\MockData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LogoEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); // admin
        Storage::fake('local');
    }

    public function test_upload_stores_file_and_serves_it_publicly(): void
    {
        $res = $this->postJson('/pengaturan/data/profile/logo', ['logo' => UploadedFile::fake()->image('logo.png', 128, 128)])
            ->assertCreated()
            ->assertJsonPath('meta.updated_by', 'Admin Koperasi');

        $file = $res->json('data.logo');
        $this->assertMatchesRegularExpression('/^logo-[A-Za-z0-9]{16}\.png$/', $file);
        $this->assertSame(route('logo', $file), $res->json('data.url'));
        Storage::disk('local')->assertExists("koperasi/{$file}");
        $this->assertSame(route('logo', $file), MockData::profile()['logo']);

        auth()->logout(); // logo tampil di beranda & halaman masuk tanpa login
        $this->get("/logo/{$file}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('immutable', $this->get("/logo/{$file}")->headers->get('Cache-Control'));
    }

    public function test_replacing_logo_removes_old_file(): void
    {
        $old = $this->postJson('/pengaturan/data/profile/logo', ['logo' => UploadedFile::fake()->image('a.png', 100, 100)])->json('data.logo');
        $new = $this->postJson('/pengaturan/data/profile/logo', ['logo' => UploadedFile::fake()->image('b.jpg', 100, 100)])->json('data.logo');

        $this->assertStringEndsWith('.jpg', $new);
        Storage::disk('local')->assertMissing("koperasi/{$old}");
        Storage::disk('local')->assertExists("koperasi/{$new}");
        $this->get("/logo/{$old}")->assertNotFound();
    }

    public function test_delete_removes_file_and_setting(): void
    {
        $file = $this->postJson('/pengaturan/data/profile/logo', ['logo' => UploadedFile::fake()->image('a.png', 100, 100)])->json('data.logo');

        $this->deleteJson('/pengaturan/data/profile/logo')->assertOk()->assertJsonPath('data.logo', null)->assertJsonPath('data.url', null);

        Storage::disk('local')->assertMissing("koperasi/{$file}");
        $this->assertNull(MockData::profile()['logo']);
        $this->assertSame(2, ActivityLog::where('event', 'setting.updated')->count());
        $this->assertStringNotContainsString('base64', ActivityLog::pluck('properties')->toJson());
    }

    public function test_reset_profile_removes_logo_file(): void
    {
        $file = $this->postJson('/pengaturan/data/profile/logo', ['logo' => UploadedFile::fake()->image('a.png', 100, 100)])->json('data.logo');

        app(SettingsService::class)->reset('profile');

        Storage::disk('local')->assertMissing("koperasi/{$file}");
    }

    public function test_invalid_uploads_are_rejected(): void
    {
        $this->postJson('/pengaturan/data/profile/logo', [])->assertJsonValidationErrors(['logo' => 'Pilih berkas logo.']);
        $this->postJson('/pengaturan/data/profile/logo', ['logo' => UploadedFile::fake()->image('kecil.png', 32, 32)])
            ->assertJsonValidationErrors(['logo' => 'Logo minimal 64×64 dan maksimal 2000×2000 piksel.']);
        $this->postJson('/pengaturan/data/profile/logo', ['logo' => UploadedFile::fake()->image('besar.png', 300, 300)->size(600)])
            ->assertJsonValidationErrors(['logo' => 'Ukuran logo maksimal 512 KB.']);
        $this->postJson('/pengaturan/data/profile/logo', ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])
            ->assertJsonValidationErrors('logo');
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)" width="100" height="100"/>');
        $this->postJson('/pengaturan/data/profile/logo', ['logo' => $svg])->assertJsonValidationErrors('logo');

        Storage::disk('local')->assertDirectoryEmpty('koperasi');
    }

    public function test_only_admin_can_change_logo(): void
    {
        $this->loginAs('kasir');
        $this->postJson('/pengaturan/data/profile/logo', ['logo' => UploadedFile::fake()->image('a.png', 100, 100)])->assertForbidden();
        $this->deleteJson('/pengaturan/data/profile/logo')->assertForbidden();
    }

    public function test_unknown_or_traversal_paths_are_not_served(): void
    {
        $this->get('/logo/logo-aaaaaaaaaaaaaaaa.png')->assertNotFound();
        $this->get('/logo/..%2F..%2F.env')->assertNotFound();
        $this->get('/logo/logo.svg')->assertNotFound();
    }

    public function test_url_helper_ignores_unexpected_values(): void
    {
        app(SettingsService::class)->save('profile', ['logo' => 'javascript:alert(1)']);

        $this->assertNull(MockData::profile()['logo']);
    }
}
