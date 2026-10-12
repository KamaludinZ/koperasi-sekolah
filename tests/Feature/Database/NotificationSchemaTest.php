<?php

namespace Tests\Feature\Database;

use App\Models\Notification;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected ?string $loginAs = null;

    public function test_tables_have_prd_columns_and_per_user_read_state(): void
    {
        $this->assertTrue(Schema::hasColumns('notifications', ['id', 'type', 'title', 'message', 'target', 'url', 'product_id', 'dedupe_key', 'show_public', 'expires_at', 'created_by', 'created_at']));
        $this->assertTrue(Schema::hasColumns('notification_reads', ['notification_id', 'user_id', 'read_at', 'dismissed_at']));
    }

    public function test_read_state_is_per_user(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $n = Notification::factory()->create();

        $n->readers()->attach($a->id, ['read_at' => now()]);

        $this->assertNotNull($n->readers()->find($a->id)->pivot->read_at);
        $this->assertNull($n->readers()->find($b->id));
    }

    public function test_dedupe_key_is_unique(): void
    {
        Notification::factory()->stock()->create(['dedupe_key' => 'stok:1:habis']);

        $this->expectException(QueryException::class);
        Notification::factory()->stock()->create(['dedupe_key' => 'stok:1:habis']);
    }

    public function test_scopes_for_role_active_and_public(): void
    {
        Notification::factory()->create(['target' => 'kasir', 'title' => 'K']);
        Notification::factory()->create(['target' => 'pengurus', 'title' => 'P']);
        Notification::factory()->create(['target' => 'semua', 'title' => 'S', 'show_public' => true]);
        Notification::factory()->create(['target' => 'anggota', 'title' => 'Lama', 'show_public' => true, 'expires_at' => now()->subDay()]);

        $this->assertEqualsCanonicalizing(['K', 'S', 'Lama'], Notification::forRole('kasir')->pluck('title')->all());
        $this->assertCount(4, Notification::forRole('admin')->get());
        $this->assertSame(['S'], Notification::public()->pluck('title')->all());
        $this->assertCount(3, Notification::active()->get());
    }

    public function test_deleting_user_or_product_keeps_notification(): void
    {
        $user = User::factory()->create();
        $product = Product::create(['code' => 'X1', 'name' => 'Roti', 'price' => 1000]);
        $n = Notification::factory()->stock()->create(['product_id' => $product->id, 'created_by' => $user->id]);
        $n->readers()->attach($user->id, ['read_at' => now()]);

        $product->delete();
        $user->delete();

        $this->assertNull($n->fresh()->product_id);
        $this->assertNull($n->fresh()->created_by);
        $this->assertSame(0, DB::table('notification_reads')->count());
    }

    public function test_postgres_rejects_invalid_type_target_and_public_non_announcement(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Constraint CHECK hanya di PostgreSQL.');
        }

        foreach ([['type' => 'spam'], ['target' => 'siswa'], ['type' => 'stok', 'show_public' => true]] as $bad) {
            try {
                DB::transaction(fn () => Notification::factory()->create($bad));
                $this->fail('Seharusnya ditolak: '.json_encode($bad));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
