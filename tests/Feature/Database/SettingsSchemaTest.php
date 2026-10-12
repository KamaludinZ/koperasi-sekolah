<?php

namespace Tests\Feature\Database;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SettingsSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $loginAs = null;

    public function test_settings_table_has_group_type_and_editor_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('settings', ['key', 'group', 'value', 'type', 'updated_by', 'created_at', 'updated_at']));
    }

    public function test_values_round_trip_with_their_type(): void
    {
        Setting::set('tax.enabled', true);
        Setting::set('tax.rate', 2.5);
        Setting::set('tax.rounding_unit', 500);
        Setting::set('profile.name', 'Kopsis Al-Hikmah');
        Setting::set('profile.schedule', [1 => ['07:00', '15:00'], 7 => null]);

        $this->assertTrue(Setting::get('tax.enabled'));
        $this->assertSame(2.5, Setting::get('tax.rate'));
        $this->assertSame(500, Setting::get('tax.rounding_unit'));
        $this->assertSame('Kopsis Al-Hikmah', Setting::get('profile.name'));
        $this->assertSame([1 => ['07:00', '15:00'], 7 => null], Setting::get('profile.schedule'));

        $this->assertSame('tax', DB::table('settings')->where('key', 'tax.rate')->value('group'));
        $this->assertSame(['enabled' => true, 'rate' => 2.5, 'rounding_unit' => 500], Setting::group('tax'));
    }

    public function test_false_and_null_are_not_confused_with_default(): void
    {
        Setting::set('receipt.show_phone', false);
        Setting::set('receipt.note', null);

        $this->assertFalse(Setting::get('receipt.show_phone', true));
        $this->assertSame('bawaan', Setting::get('receipt.note', 'bawaan'));
    }

    public function test_updating_refreshes_cache_and_records_editor(): void
    {
        $user = User::factory()->create();
        Setting::set('profile.name', 'Lama');
        $this->assertSame('Lama', Setting::get('profile.name'));

        Setting::set('profile.name', 'Baru', $user->id);

        $this->assertSame('Baru', Setting::get('profile.name'));
        $this->assertSame(1, Setting::where('key', 'profile.name')->count());
        $this->assertTrue(Setting::where('key', 'profile.name')->first()->editor->is($user));
    }

    public function test_key_is_unique(): void
    {
        Setting::create(['key' => 'profile.name', 'group' => 'profile', 'value' => 'A']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Setting::create(['key' => 'profile.name', 'group' => 'profile', 'value' => 'B']);
    }
}
