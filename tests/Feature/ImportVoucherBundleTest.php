<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\InventoryCard;
use App\Models\NetworkOwner;
use App\Models\User;
use App\Services\Networks\ImportVoucherBundleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImportVoucherBundleTest extends TestCase
{
    use RefreshDatabase;

    public function test_bundle_import_is_atomic_encrypted_and_identical_retries_do_not_reset_cards(): void
    {
        [$user, $network, $connection] = $this->catalog();
        $service = app(ImportVoucherBundleService::class);
        $bundle = $this->bundle();
        $this->assertSame(['products' => 1, 'imported' => 2, 'existing' => 0], $service->handle($user, $network, $connection, $bundle));
        $product = $network->products()->sole();
        $this->assertSame('inactive', $product->status);
        $this->assertSame($connection->id, $product->fulfillment_connection_id);
        $this->assertSame(['validity' => ['starts_when' => 'first_auth', 'days' => 1], 'uptime_hours' => null], $product->metadata['imported_vouchers']);
        $card = InventoryCard::query()->orderBy('id')->firstOrFail();
        $raw = $card->getRawOriginal('credentials_encrypted');
        $this->assertStringNotContainsString('secret-0002', $raw);
        $card->status = 'allocated';
        $card->save();
        $this->assertSame(['products' => 0, 'imported' => 0, 'existing' => 2], $service->handle($user, $network, $connection, $bundle));
        $this->assertSame('allocated', $card->fresh()->status);
        $this->assertSame($raw, $card->fresh()->getRawOriginal('credentials_encrypted'));
        $this->assertDatabaseCount('inventory_cards', 2);
        $this->assertDatabaseCount('network_products', 1);
    }

    public function test_conflicting_second_batch_rolls_back_all_new_products_cards_and_audits(): void
    {
        [$user, $network, $connection] = $this->catalog();
        $bundle = $this->bundle();
        $second = $bundle['batches'][0];
        $second['batch_id'] = 'second';
        $second['product']['external_product_id'] = 'MALKI-500';
        $bundle['batches'][] = $second;
        try {
            app(ImportVoucherBundleService::class)->handle($user, $network, $connection, $bundle);
            $this->fail('A duplicate username must reject the entire bundle.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('network_products', 0);
            $this->assertDatabaseCount('inventory_cards', 0);
            $this->assertDatabaseCount('audit_events', 0);
        }
    }

    public function test_existing_credentials_cannot_be_overwritten_and_provider_products_are_not_rebound(): void
    {
        [$user, $network, $connection] = $this->catalog();
        $service = app(ImportVoucherBundleService::class);
        $bundle = $this->bundle();
        $service->handle($user, $network, $connection, $bundle);
        $bundle['batches'][0]['cards'][0]['password'] = 'changed';
        try {
            $service->handle($user, $network, $connection, $bundle);
            $this->fail('Changed credentials must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('inventory_cards', 2);
        }
        $product = $network->products()->sole();
        $product->fulfillment_connection_id = null;
        $product->save();
        $this->expectException(ValidationException::class);
        $service->handle($user, $network, $connection, $this->bundle());
    }

    public function test_foreign_owner_and_invalid_validity_cannot_import(): void
    {
        [$user, $network, $connection] = $this->catalog();
        [$foreign] = $this->catalog();
        try {
            app(ImportVoucherBundleService::class)->handle($foreign, $network, $connection, $this->bundle());
            $this->fail('Foreign networks must be unavailable.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('inventory_cards', 0);
        }
        $bundle = $this->bundle();
        $bundle['batches'][0]['source_limits']['validity']['days'] = 3;
        $this->expectException(ValidationException::class);
        app(ImportVoucherBundleService::class)->handle($user, $network, $connection, $bundle);
    }

    public function test_command_reads_only_a_private_file_and_reports_counts_without_credentials(): void
    {
        [$user, $network, $connection] = $this->catalog();
        $connection->delete();
        $path = storage_path('app/private/test-vouchers-'.Str::ulid().'.json');
        file_put_contents($path, json_encode($this->bundle(), JSON_THROW_ON_ERROR));
        try {
            $this->artisan('qanetwork:import-vouchers', ['file' => $path, '--actor' => $user->email, '--network' => $network->code])
                ->expectsOutput('Created products: 1; imported cards: 2; identical existing cards: 0.')
                ->assertSuccessful();
            $this->assertDatabaseCount('inventory_cards', 2);
            $source = $network->connections()->sole();
            $this->assertSame('stored_cards', $source->driver);
            $this->assertFalse($source->is_enabled);
            $this->artisan('qanetwork:import-vouchers', ['file' => base_path('composer.json'), '--actor' => $user->email, '--network' => $network->id, '--connection' => $connection->id])->assertFailed();
        } finally {
            unlink($path);
        }
    }

    public function test_failed_bundle_rolls_back_automatic_source_creation_and_multiple_sources_require_selection(): void
    {
        [$user, $network, $connection] = $this->catalog();
        $connection->delete();
        $bundle = $this->bundle();
        $bundle['batches'][0]['cards'][1]['username'] = '0001';
        try {
            app(ImportVoucherBundleService::class)->handle($user, $network, null, $bundle);
            $this->fail('A rejected bundle must roll back its new source.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('network_connections', 0);
            $this->assertDatabaseCount('inventory_cards', 0);
            $this->assertDatabaseCount('audit_events', 0);
        }
        foreach (['A', 'B'] as $name) {
            $network->connections()->create(['name' => $name, 'driver' => 'stored_cards', 'config' => []]);
        }
        $this->expectException(ValidationException::class);
        app(ImportVoucherBundleService::class)->handle($user, $network, null, $this->bundle());
    }

    public function test_suspended_actors_and_non_inventory_sources_are_rejected(): void
    {
        [$user, $network, $connection] = $this->catalog();
        $connection->driver = 'mikrotik_user_manager';
        $connection->save();
        try {
            app(ImportVoucherBundleService::class)->handle($user, $network, $connection, $this->bundle());
            $this->fail('Router sources cannot fulfill imported inventory.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('inventory_cards', 0);
        }
        $user->status = 'suspended';
        $user->save();
        $this->expectException(AuthorizationException::class);
        app(ImportVoucherBundleService::class)->handle($user, $network, null, $this->bundle());
    }

    private function catalog(): array
    {
        $user = User::factory()->create(['role' => UserRole::NETWORK_OWNER]);
        $owner = new NetworkOwner(['code' => 'OWN-'.Str::ulid(), 'name' => 'Voucher owner']);
        $owner->user_id = $user->id;
        $owner->status = 'active';
        $owner->save();
        $network = $owner->networks()->create(['code' => 'NET-'.Str::ulid(), 'name' => 'Voucher network', 'currency_code' => 'YER']);
        $connection = $network->connections()->create(['name' => 'Stored cards', 'driver' => 'stored_cards', 'config' => []]);

        return [$user, $network, $connection];
    }

    private function bundle(): array
    {
        return ['version' => 1, 'network_name' => 'الملكي نت', 'currency_code' => 'YER', 'batches' => [
            ['batch_id' => 'first', 'product' => ['name' => 'MALKI-200', 'display_name' => '200 ريال', 'external_product_id' => 'MALKI-200', 'face_value' => '200', 'data_limit_bytes' => 1000000000, 'duration_minutes' => 1440],
                'source_limits' => ['validity' => ['starts_when' => 'first_auth', 'days' => 1], 'uptime_hours' => null],
                'cards' => [['username' => '0001', 'password' => 'secret-0002'], ['username' => '0003', 'password' => null]]],
        ]];
    }
}
