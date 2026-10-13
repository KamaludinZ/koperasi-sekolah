<?php

namespace Tests\Feature\Pengaturan;

use App\Http\Controllers\BerandaController;
use App\Livewire\Pengaturan\Profile;
use App\Support\MockData;
use App\Support\OpeningHours;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); // admin
        $this->seed(ProductSeeder::class);
    }

    public function test_form_is_prefilled(): void
    {
        $this->get('/pengaturan/profil')->assertOk()->assertSee('Identitas')->assertSee('Jam buka')->assertSee('Kepala struk');

        Livewire::test(Profile::class)
            ->assertSet('name', 'Koperasi Siswa Al-Hikmah')
            ->assertSet('schedule.1', ['open' => true, 'from' => '07:00', 'to' => '14:00'])
            ->assertSet('schedule.7.open', false);
    }

    public function test_save_updates_profile_receipt_and_public_home(): void
    {
        Livewire::test(Profile::class)
            ->set('name', 'Koperasi Siswa An-Nur')
            ->set('short_name', 'Kopsis An-Nur')
            ->set('school', 'MTs An-Nur')
            ->set('phone', '0811-222-333')
            ->set('schedule.6.open', false)
            ->set('schedule.5.to', '11:30')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $p = MockData::profile();
        $this->assertSame('Koperasi Siswa An-Nur', $p['name']);
        $this->assertNull($p['schedule'][6]);
        $this->assertSame([['days' => 'Senin – Kamis', 'time' => '07.00 – 14.00'], ['days' => 'Jumat', 'time' => '07.00 – 11.30']], $p['hours']);

        $this->get('/')->assertSee('Kopsis An-Nur')->assertSee('MTs An-Nur')->assertSee('Sabtu, Minggu &amp; hari libur', false);
        $this->get('/pengaturan')->assertSee('Koperasi Siswa An-Nur');
    }

    public function test_validation(): void
    {
        Livewire::test(Profile::class)
            ->set('name', '')
            ->set('phone', 'abc')
            ->set('email', 'bukan-email')
            ->set('schedule.1.to', '06:00')
            ->call('save')
            ->assertHasErrors(['name', 'phone', 'email', 'schedule.1.to'])
            ->assertSet('saved', false);
    }

    public function test_app_name_is_used_in_titles_and_falls_back_to_short_name(): void
    {
        $this->assertSame('Kopsis Al-Hikmah', MockData::profile()['app_name']);

        Livewire::test(Profile::class)
            ->set('app_name', str_repeat('A', 61))->call('save')->assertHasErrors('app_name')
            ->set('app_name', "  KOPSIS QONA'AH MTsN 2 Kota Malang ")->call('save')->assertHasNoErrors();

        $this->assertSame("KOPSIS QONA'AH MTsN 2 Kota Malang", MockData::profile()['app_name']);
        $this->get('/pengaturan')->assertSee("<title>Pengaturan Koperasi · KOPSIS QONA&#039;AH MTsN 2 Kota Malang</title>", false);
        auth()->logout();
        $this->get('/masuk')->assertSee("KOPSIS QONA&#039;AH MTsN 2 Kota Malang", false);
        $this->get('/')->assertSee("KOPSIS QONA&#039;AH MTsN 2 Kota Malang", false);

        $this->loginAs('admin');
        Livewire::test(Profile::class)->assertSet('app_name', "KOPSIS QONA'AH MTsN 2 Kota Malang")->set('app_name', '')->call('save');
        $this->assertSame('Kopsis Al-Hikmah', MockData::profile()['app_name']);
    }

    public function test_logo_upload_and_remove(): void
    {
        Storage::fake('local');
        $c = Livewire::test(Profile::class)
            ->set('logoUpload', UploadedFile::fake()->image('logo.png', 200, 200))
            ->assertHasNoErrors()
            ->call('save')
            ->assertHasNoErrors();
        $url = MockData::profile()['logo'];
        $this->assertMatchesRegularExpression('#/logo/logo-[A-Za-z0-9]{16}\.png$#', $url);
        $c->assertSet('logo', $url);
        Storage::disk('local')->assertExists('koperasi/'.basename($url));
        $this->get('/pengaturan')->assertSee('5 dari 5 sudah lengkap')->assertSee($url);

        $c->call('removeLogo')->call('save');
        $this->assertNull(MockData::profile()['logo']);
        Storage::disk('local')->assertMissing('koperasi/'.basename($url));

        Livewire::test(Profile::class)->set('logoUpload', UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'))->assertHasErrors('logoUpload');
        Livewire::test(Profile::class)->set('logoUpload', UploadedFile::fake()->image('kecil.png', 20, 20))->assertHasErrors('logoUpload');
    }

    public function test_opening_hours_grouping_and_status(): void
    {
        $this->assertSame([['days' => 'Senin – Kamis', 'time' => '07.00 – 14.00'], ['days' => 'Jumat', 'time' => '07.00 – 11.00'], ['days' => 'Sabtu', 'time' => '07.00 – 12.00']],
            OpeningHours::group(OpeningHours::DEFAULT));
        $this->assertSame(['Minggu'], OpeningHours::closedDays(OpeningHours::DEFAULT));

        $custom = [1 => ['open' => '08:00', 'close' => '10:00'], 3 => ['open' => '08:00', 'close' => '10:00']];
        $this->assertSame(['days' => 'Senin', 'time' => '08.00 – 10.00'], OpeningHours::group($custom)[0]);
        $this->assertSame('Buka Rabu pukul 08.00', BerandaController::openStatus(Carbon::parse('2026-10-12 11:00'), $custom)['detail']);
    }

    public function test_only_admin(): void
    {
        $this->loginAs('pengurus');
        $this->get('/pengaturan/profil')->assertForbidden();
    }

    public function test_open_status_indicator_in_profile_and_cashier(): void
    {
        $this->travelTo(now()->startOfWeek()->setTime(9, 0)); // Senin 09.00
        $this->loginAs('admin');
        $c = Livewire::test(Profile::class);
        foreach (range(1, 7) as $d) {
            $c->set("schedule.{$d}.open", $d <= 5)->set("schedule.{$d}.from", '07:00')->set("schedule.{$d}.to", '14:00');
        }
        $c->assertSeeHtml('data-open-status="buka"')->assertSee('Saat ini: Sedang buka');
        $c->set('schedule.1.open', false)->assertSeeHtml('data-open-status="tutup"')->assertSee('Buka besok pukul 07.00');
        $c->call('save')->assertHasNoErrors();
        $this->get('/kasir')->assertSeeHtml('data-open-status="tutup"');
    }
}
