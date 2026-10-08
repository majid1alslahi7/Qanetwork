<?php

namespace Tests\Feature\Providers\MikroTik;

use App\Models\Network;
use App\Models\NetworkConnection;
use App\Models\NetworkOwner;
use App\Models\NetworkProduct;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\SellerLedgerEntry;
use App\Models\SellerWallet;
use App\Models\User;
use App\Providers\Data\PurchaseCardRequest;
use App\Providers\Enums\ProviderTransactionStatus;
use App\Providers\MikroTik\MikroTikHotspotAdapter;
use App\Providers\MikroTik\RouterOsClient;
use App\Providers\MikroTik\RouterOsConnectionConfigFactory;
use App\Providers\MikroTik\RouterOsFailure;
use App\Providers\Registry\ProviderAdapterRegistry;
use App\Services\Finance\AdjustSellerWalletService;
use App\Services\Sales\ProcessSaleService;
use App\Services\Sales\ReconcileSaleService;
use App\Services\Sales\SaleFinancialSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Providers\MikroTik\Fakes\FakeHotspotRouterOsClient;

class MikroTikHotspotAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_purchase_creates_one_limited_card_and_replay_reads_it(): void
    {
        [$connection] = $this->catalog();
        $client = new FakeHotspotRouterOsClient;
        $adapter = $this->adapter($client);

        $result = $adapter->purchaseCard($connection, $this->request());
        $replay = $adapter->purchaseCard($connection, $this->request());

        $this->assertSame(ProviderTransactionStatus::CONFIRMED, $result->status);
        $this->assertSame($result->credentials, $replay->credentials);
        $this->assertCount(1, $client->users);
        $this->assertSame('1048576', $client->lastAdd['limit-bytes-total']);
        $this->assertSame('3600s', $client->lastAdd['limit-uptime']);
        $this->assertSame('day', $client->lastAdd['profile']);
        $this->assertStringStartsWith('qanetwork:v1:'.$this->request()->internalTransactionId.':', $client->lastAdd['comment']);
        $this->assertSame(1, count(array_filter($client->commands, fn ($command) => $command === '/ip/hotspot/user/add')));
    }

    #[DataProvider('uncertainFailures')]
    public function test_lost_purchase_response_is_reconciled_without_another_card(
        RouterOsFailure $failure, ProviderTransactionStatus $expected,
    ): void {
        [$connection] = $this->catalog();
        $client = new FakeHotspotRouterOsClient;
        $client->addFailure = $failure;
        $adapter = $this->adapter($client);

        $purchase = $adapter->purchaseCard($connection, $this->request());
        $client->addFailure = null;
        $status = $adapter->checkTransaction($connection, $this->request()->internalTransactionId);

        $this->assertSame($expected, $purchase->status);
        $this->assertNull($purchase->credentials);
        $this->assertSame(ProviderTransactionStatus::CONFIRMED, $status->status);
        $this->assertSame($client->users[0]['password'], $status->credentials['password']);
        $this->assertCount(1, $client->users);
        $this->assertSame(1, count(array_filter($client->commands, fn ($command) => $command === '/ip/hotspot/user/add')));
    }

    public static function uncertainFailures(): array
    {
        return [
            [RouterOsFailure::TIMEOUT, ProviderTransactionStatus::TIMEOUT],
            [RouterOsFailure::DISCONNECTED, ProviderTransactionStatus::UNKNOWN],
            [RouterOsFailure::COMMAND_REJECTED, ProviderTransactionStatus::UNKNOWN],
        ];
    }

    public function test_missing_user_remains_uncertain_and_reconciliation_is_read_only(): void
    {
        [$connection] = $this->catalog();
        $client = new FakeHotspotRouterOsClient;

        $result = $this->adapter($client)->checkTransaction($connection, $this->request()->internalTransactionId);

        $this->assertSame(ProviderTransactionStatus::RECONCILIATION_REQUIRED, $result->status);
        $this->assertNull($result->credentials);
        $this->assertSame(['/ip/hotspot/user/print'], $client->commands);
    }

    public function test_username_collision_does_not_create_or_deliver_another_users_card(): void
    {
        [$connection] = $this->catalog();
        $client = new FakeHotspotRouterOsClient;
        $adapter = $this->adapter($client);
        $adapter->purchaseCard($connection, $this->request());
        $client->users[0]['comment'] = 'unrelated';
        $client->commands = [];

        $result = $adapter->purchaseCard($connection, $this->request());

        $this->assertSame(ProviderTransactionStatus::RECONCILIATION_REQUIRED, $result->status);
        $this->assertNull($result->credentials);
        $this->assertSame(['/ip/hotspot/user/print'], $client->commands);
    }

    public function test_hidden_password_prevents_confirmation(): void
    {
        [$connection] = $this->catalog();
        $client = new FakeHotspotRouterOsClient;
        $client->hidePasswords = true;

        $result = $this->adapter($client)->purchaseCard($connection, $this->request());

        $this->assertSame(ProviderTransactionStatus::RECONCILIATION_REQUIRED, $result->status);
        $this->assertNull($result->credentials);
        $this->assertCount(1, $client->users);
    }

    public function test_transaction_id_mismatch_prevents_confirmation(): void
    {
        [$connection] = $this->catalog();
        $client = new FakeHotspotRouterOsClient;
        $adapter = $this->adapter($client);
        $adapter->purchaseCard($connection, $this->request());

        $result = $adapter->checkTransaction($connection, $this->request()->internalTransactionId, '*other');

        $this->assertSame(ProviderTransactionStatus::RECONCILIATION_REQUIRED, $result->status);
        $this->assertNull($result->credentials);
    }

    public function test_unavailable_profile_fails_before_card_creation(): void
    {
        [$connection] = $this->catalog();
        $client = new FakeHotspotRouterOsClient;
        $client->profiles = [];

        $result = $this->adapter($client)->purchaseCard($connection, $this->request());

        $this->assertSame(ProviderTransactionStatus::FAILED, $result->status);
        $this->assertSame('HOTSPOT_PRODUCT_UNAVAILABLE', $result->errorCode);
        $this->assertSame([], $client->users);
    }

    public function test_missing_issuance_limits_cannot_issue_an_unlimited_card(): void
    {
        [$connection, $product] = $this->catalog();
        $product->metadata = [];
        $product->save();
        $client = new FakeHotspotRouterOsClient;
        try {
            $this->adapter($client)->purchaseCard($connection, $this->request());
            $this->fail('Unlimited card was issued without explicit configuration.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Hotspot issuance requires explicit limits or unlimited approval.', $exception->getMessage());
        }
        $this->assertSame([], $client->users);
    }

    public function test_catalog_uses_local_decimal_prices_and_only_available_profiles(): void
    {
        [$connection] = $this->catalog();
        $client = new FakeHotspotRouterOsClient;
        $adapter = $this->adapter($client);

        $products = $adapter->getProducts($connection);

        $this->assertCount(1, $products);
        $this->assertSame('1000.0000', $products[0]->faceValue);
        $this->assertSame('YER', $products[0]->currencyCode);
        $this->assertSame('day', $products[0]->externalProductId);
        $this->assertTrue($adapter->checkAvailability($connection, 'day'));
        $this->assertFalse($adapter->checkAvailability($connection, 'missing'));
        $this->assertNull($adapter->getBalance($connection));
        $this->assertTrue($adapter->healthCheck($connection));
    }

    public function test_connection_disablement_is_not_reported_as_healthy(): void
    {
        [$connection] = $this->catalog();
        $connection->is_enabled = false;
        $client = new FakeHotspotRouterOsClient;

        $this->assertFalse($this->adapter($client)->healthCheck($connection));
        $this->assertSame([], $client->commands);
    }

    public function test_hotspot_driver_is_registered_in_application(): void
    {
        $this->assertInstanceOf(MikroTikHotspotAdapter::class, $this->app->make(ProviderAdapterRegistry::class)->get('mikrotik_hotspot'));
    }

    #[DataProvider('saleOutcomes')]
    public function test_sale_completes_with_one_card_one_debit_and_encrypted_credentials(bool $loseResponse): void
    {
        [$connection, $product] = $this->catalog();
        $user = User::factory()->create();
        $seller = Seller::query()->create(['user_id' => $user->id, 'code' => 'hotspot-seller']);
        $wallet = SellerWallet::query()->create(['seller_id' => $seller->id, 'currency_code' => 'YER']);
        $this->app->make(AdjustSellerWalletService::class)->handle($wallet, 'credit', '10000', 'initial-credit', 'Initial test credit');
        $sale = Sale::query()->create([
            'seller_id' => $seller->id, 'seller_wallet_id' => $wallet->id,
            'network_id' => $product->network_id, 'network_product_id' => $product->id,
            'reference_no' => 'hotspot-sale', 'idempotency_key' => 'hotspot-sale',
            'currency_code' => 'YER', 'delivery_method' => 'screen',
        ]);
        $this->app->make(SaleFinancialSnapshotService::class)->create($sale, '1000', '800', '150', '50');
        $client = new FakeHotspotRouterOsClient;
        $client->addFailure = $loseResponse ? RouterOsFailure::TIMEOUT : null;
        $this->app->instance(RouterOsClient::class, $client);

        $result = $this->app->make(ProcessSaleService::class)->handle($sale, $connection);
        if ($loseResponse) {
            $this->assertFalse($result->completed);
            $this->assertSame('10000.0000', $wallet->fresh()->balance);
            $this->assertSame('850.0000', $wallet->fresh()->reserved_balance);
            $this->assertDatabaseMissing('seller_ledger_entries', ['reference_id' => $sale->id, 'entry_type' => 'sale']);
            $client->addFailure = null;
            $result = $this->app->make(ReconcileSaleService::class)->handle($sale);
        }
        $commandCount = count($client->commands);
        $replay = $this->app->make(ProcessSaleService::class)->handle($sale);

        $this->assertTrue($result->completed);
        $this->assertTrue($replay->completed);
        $this->assertSame('completed', $sale->fresh()->status);
        $this->assertSame('9150.0000', $wallet->fresh()->balance);
        $this->assertSame('0.0000', $wallet->fresh()->reserved_balance);
        $this->assertSame('captured', $sale->reservation()->firstOrFail()->status);
        $this->assertSame(1, $sale->soldCard()->count());
        $this->assertSame(1, SellerLedgerEntry::query()->where('reference_id', $sale->id)->where('entry_type', 'sale')->count());
        $card = $sale->soldCard()->firstOrFail();
        $this->assertSame($client->users[0]['password'], $card->credentials()['password']);
        $this->assertStringNotContainsString($client->users[0]['password'], $card->getAttributes()['credentials_encrypted']);
        $this->assertCount(1, $client->users);
        $this->assertSame($commandCount, count($client->commands));
    }

    public static function saleOutcomes(): array
    {
        return ['normal response' => [false], 'lost response' => [true]];
    }

    #[DataProvider('readbackLimits')]
    public function test_issued_limits_are_verified_before_confirmation(array $overrides, ProviderTransactionStatus $status): void
    {
        [$connection] = $this->catalog();
        $client = new FakeHotspotRouterOsClient;
        $client->addOverrides = $overrides;

        $result = $this->adapter($client)->purchaseCard($connection, $this->request());

        $this->assertSame($status, $result->status);
        if ($status !== ProviderTransactionStatus::CONFIRMED) {
            $this->assertNull($result->credentials);
        }
        $this->assertCount(1, $client->users);
    }

    public static function readbackLimits(): array
    {
        return [
            'hours notation' => [['limit-uptime' => '1h'], ProviderTransactionStatus::CONFIRMED],
            'clock notation' => [['limit-uptime' => '01:00:00'], ProviderTransactionStatus::CONFIRMED],
            'unlimited bytes' => [['limit-bytes-total' => '0'], ProviderTransactionStatus::RECONCILIATION_REQUIRED],
            'wrong uptime' => [['limit-uptime' => '2h'], ProviderTransactionStatus::RECONCILIATION_REQUIRED],
            'malformed uptime' => [['limit-uptime' => 'secret'], ProviderTransactionStatus::RECONCILIATION_REQUIRED],
        ];
    }

    public function test_reconciliation_does_not_confirm_a_card_with_changed_issuance_limits(): void
    {
        [$connection] = $this->catalog();
        $client = new FakeHotspotRouterOsClient;
        $client->addFailure = RouterOsFailure::TIMEOUT;
        $adapter = $this->adapter($client);
        $adapter->purchaseCard($connection, $this->request());
        $client->users[0]['limit-bytes-total'] = '0';
        $client->commands = [];

        $result = $adapter->checkTransaction($connection, $this->request()->internalTransactionId);

        $this->assertSame(ProviderTransactionStatus::RECONCILIATION_REQUIRED, $result->status);
        $this->assertNull($result->credentials);
        $this->assertSame(['/ip/hotspot/user/print'], $client->commands);
    }

    public function test_catalog_changes_do_not_change_an_already_issued_card(): void
    {
        [$connection, $product] = $this->catalog();
        $client = new FakeHotspotRouterOsClient;
        $adapter = $this->adapter($client);
        $purchase = $adapter->purchaseCard($connection, $this->request());
        $product->metadata = ['hotspot' => ['limit_bytes_total' => 9999]];
        $product->save();

        $replay = $adapter->purchaseCard($connection, $this->request());

        $this->assertSame(ProviderTransactionStatus::CONFIRMED, $replay->status);
        $this->assertSame($purchase->credentials, $replay->credentials);
        $this->assertSame('1048576', $client->users[0]['limit-bytes-total']);
        $this->assertCount(1, $client->users);
    }

    private function catalog(): array
    {
        $owner = NetworkOwner::query()->create(['code' => 'hotspot-owner', 'name' => 'Hotspot Owner']);
        $network = Network::query()->create([
            'network_owner_id' => $owner->id, 'code' => 'hotspot-network', 'name' => 'Hotspot Network',
        ]);
        $connection = NetworkConnection::query()->create([
            'network_id' => $network->id, 'driver' => 'mikrotik_hotspot',
            'config' => ['host' => '192.0.2.1'], 'is_enabled' => true,
        ]);
        $connection->setCredentials(['username' => 'operator', 'password' => 'secret']);
        $connection->save();
        $product = NetworkProduct::query()->create([
            'network_id' => $network->id, 'external_product_id' => 'day',
            'code' => 'hotspot-day', 'name' => 'Day Card', 'face_value' => '1000', 'currency_code' => 'YER',
            'metadata' => ['hotspot' => ['limit_bytes_total' => 1048576, 'limit_uptime_seconds' => 3600]],
        ]);

        return [$connection, $product];
    }

    private function request(): PurchaseCardRequest
    {
        return new PurchaseCardRequest(
            internalTransactionId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            idempotencyKey: 'hotspot-sale:1',
            externalProductId: 'day',
            saleId: '01ARZ3NDEKTSV4RRFFQ69G5FAW',
            expectedFaceValue: '1000.0000',
            currencyCode: 'YER',
        );
    }

    private function adapter(FakeHotspotRouterOsClient $client): MikroTikHotspotAdapter
    {
        return new MikroTikHotspotAdapter($client, new RouterOsConnectionConfigFactory);
    }
}
