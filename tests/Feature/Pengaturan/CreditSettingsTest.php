<?php

namespace Tests\Feature\Pengaturan;

use App\Livewire\Anggota\Index as MemberIndex;
use App\Livewire\Kasir\Cart;
use App\Livewire\Pengaturan\Credit;
use App\Models\ActivityLog;
use App\Models\Member;
use App\Models\MemberCredit;
use App\Services\CheckoutService;
use App\Services\MemberCreditService;
use App\Services\SettingsService;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CreditSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_in_settings_nav_and_saves(): void
    {
        $this->get('/pengaturan')->assertOk()->assertSee('Bon anggota')->assertSee(route('pengaturan.bon'));
        $this->get('/pengaturan/bon')->assertOk()->assertSee('Batas bon siswa')->assertSee('Rp50.000');

        Livewire::test(Credit::class)->set('limit_siswa', 75000)->set('max_days', 30)->set('default_days', 7)->set('block_overdue', false)
            ->call('save')->assertHasNoErrors()->assertDispatched('toast', type: 'success');
        $s = app(SettingsService::class)->credit();
        $this->assertSame([75000, 30, 7, false], [$s['limit_siswa'], $s['max_days'], $s['default_days'], $s['block_overdue']]);
        $this->assertTrue(ActivityLog::where('category', 'pengaturan')->where('description', 'like', '%Bon anggota%')->exists());
    }

    public function test_validation_and_cross_check(): void
    {
        Livewire::test(Credit::class)->set('max_days', 3)->assertHasErrors(['max_days' => 'min'])
            ->set('max_days', 30)->set('default_days', 45)->call('save')->assertHasErrors('default_days')
            ->assertSee('Jatuh tempo bawaan tidak boleh melebihi jatuh tempo terlama.');
        $this->putJson(route('pengaturan.data.update', 'credit'), ['default_days' => 90])->assertStatus(422)->assertJsonValidationErrors('default_days');
        $this->putJson(route('pengaturan.data.update', 'credit'), ['limit_guru' => 150000])->assertOk();
        $this->assertSame(150000, app(SettingsService::class)->credit()['limit_guru']);
    }

    public function test_non_admin_read_only(): void
    {
        $this->loginAs('pengurus@koperasi.test');
        if (! auth()->user()->canOpen('pengaturan')) {
            $this->get('/pengaturan/bon')->assertForbidden();

            return;
        }
        Livewire::test(Credit::class)->set('limit_siswa', 1000)->call('save')->assertDispatched('toast', type: 'error');
        $this->assertSame(50000, app(SettingsService::class)->credit()['limit_siswa']);
    }

    public function test_settings_drive_credit_rules(): void
    {
        $this->seed(ProductSeeder::class);
        $m = Member::factory()->create(['type' => 'siswa']);
        MemberCredit::create(['number' => 'BON-L', 'member_id' => $m->id, 'amount' => 1000, 'due_at' => today()->subDay()]);
        $svc = app(MemberCreditService::class);
        $this->assertSame('overdue', $svc->check($m, 1000)['reason']);

        app(SettingsService::class)->save('credit', ['block_overdue' => false]);
        $this->assertSame('ok', $svc->check($m, 1000)['reason']);

        app(SettingsService::class)->save('credit', ['enabled' => false]);
        $this->assertSame('disabled', $svc->check($m, 1000)['reason']);
        try {
            app(CheckoutService::class)->checkout([$this->pid('ATK001') => 1], 'bon', null, null, $m->id, null, null, ['due_at' => today()->addWeek()->toDateString()]);
            $this->fail('Seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('dimatikan', $e->errors()['member_id'][0]);
        }

        app(SettingsService::class)->save('credit', ['enabled' => true, 'max_days' => 10]);
        $this->expectException(ValidationException::class);
        MemberCreditService::validDueDate(today()->addDays(11)->toDateString());
    }

    public function test_cashier_follows_settings(): void
    {
        $this->seed(ProductSeeder::class);
        app(SettingsService::class)->save('credit', ['default_days' => 7, 'max_days' => 14]);
        $c = Livewire::test(Cart::class)->call('add', $this->pid('ATK001'))->call('openPayment')->assertSet('bonDays', 7);
        $this->assertSame([7, 14], array_keys($c->instance()->bonTerms()));

        app(SettingsService::class)->save('credit', ['default_days' => 10]);
        $c->call('closePayment')->call('openPayment')->assertSet('bonDays', 0)->assertSet('bonDueAt', today()->addDays(10)->toDateString());

        app(SettingsService::class)->save('credit', ['enabled' => false]);
        $c->call('closePayment')->call('openPayment')->assertDontSee('Bon (bayar nanti)');
        $this->assertArrayNotHasKey('bon', $c->instance()->methods);
    }

    public function test_member_form_credit_limit_override(): void
    {
        $m = Member::factory()->create(['type' => 'siswa']);
        Livewire::test(MemberIndex::class)->call('edit', $m->id)->assertSee('Batas bon khusus')
            ->set('form.credit_limit', 120000)->call('save')->assertHasNoErrors();
        $this->assertSame(120000, $m->fresh()->credit_limit);
        $this->assertSame(120000, MemberCreditService::limitFor($m->fresh()));
        $this->get('/pengaturan/bon')->assertSee($m->name)->assertSee('Rp120.000');

        Livewire::test(MemberIndex::class)->call('edit', $m->id)->assertSet('form.credit_limit', 120000)
            ->set('form.credit_limit', '')->call('save')->assertHasNoErrors();
        $this->assertNull($m->fresh()->credit_limit);
        Livewire::test(MemberIndex::class)->call('edit', $m->id)->set('form.credit_limit', -5)->call('save')->assertHasErrors('form.credit_limit');
    }
}
