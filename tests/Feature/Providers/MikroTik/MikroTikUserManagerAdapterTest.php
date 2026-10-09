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
use App\Providers\MikroTik\MikroTikUserManagerAdapter;
use App\Providers\MikroTik\RouterOsClient;
use App\Providers\MikroTik\RouterOsConnectionConfigFactory;
use App\Providers\Registry\ProviderAdapterRegistry;
use App\Services\Finance\AdjustSellerWalletService;
use App\Services\Sales\ProcessSaleService;
use App\Services\Sales\ReconcileSaleService;
use App\Services\Sales\SaleFinancialSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Providers\MikroTik\Fakes\FakeUserManagerRouterOsClient;

class MikroTikUserManagerAdapterTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('issuableProfiles')]
    public function test_purchase_assigns_one_profile_before_enabling_user_and_replay_is_read_only(string $state, string $startsWhen): void
    {
        [$connection] = $this->catalog();
        $client = new FakeUserManagerRouterOsClient;
        $client->assignedState = $state;
        $client->startsWhen = $startsWhen;
        $adapter = $this->adapter($client);

        $purchase = $adapter->purchaseCard($connection, $this->request());
        $writes = array_values(array_filter($client->commands, fn ($command) => str_ends_with($command, '/add') || str_ends_with($command, '/set')));
        $this->assertSame(['/user-manager/user/add', '/user-manager/user-profile/add', '/user-manager/user/set'], $writes);
        $client->commands = [];
        $replay = $adapter->purchaseCard($connection, $this->request());

        $this->assertSame(ProviderTransactionStatus::CONFIRMED, $purchase->status);
        $this->assertSame($purchase->credentials, $replay->credentials);
        $this->assertCount(1, $client->users);
        $this->assertCount(1, $client->userProfiles);
        $this->assertSame('false', $client->users[0]['disabled']);
        $this->assertSame('day', $client->userProfiles[0]['profile']);
        $this->assertFalse($this->hasWrites($client->commands));
    }

    public static function issuableProfiles(): array
    {
        return [['waiting', 'first-auth'], ['running', 'assigned'], ['running active', 'assigned'], ['running-active', 'assigned']];
    }

    public function test_waiting_profile_cannot_enable_a_user_when_its_start_mode_is_not_first_auth(): void
    {
        [$connection] = $this->catalog();
        $client = new FakeUserManagerRouterOsClient;
        $client->startsWhen = 'assigned';

        $result = $this->adapter($client)->purchaseCard($connection, $this->request());

        $this->assertSame(ProviderTransactionStatus::RECONCILIATION_REQUIRED, $result->status);
        $this->assertNull($result->credentials);
        $this->assertSame('true', $client->users[0]['disabled']);
        $this->assertNotContains('/user-manager/user/set', $client->commands);
    }

    #[DataProvider('interruptedStages')]
    public function test_interrupted_issuance_is_never_repeated_and_partial_cards_are_not_confirmed(
        string $command, bool $persist, ProviderTransactionStatus $expected, int $profileCount,
    ): void {
        [$connection] = $this->catalog();
        $client = new FakeUserManagerRouterOsClient;
        $client->failCommand = $command;
        $client->persistBeforeFailure = $persist;
        $adapter = $this->adapter($client);

        $purchase = $adapter->purchaseCard($connection, $this->request());
        $client->failCommand = null;
        $client->commands = [];
        $status = $adapter->checkTransaction($connection, $this->request()->internalTransactionId);

        $this->assertSame(ProviderTransactionStatus::TIMEOUT, $purchase->status);
        $this->assertNull($purchase->credentials);
        $this->assertSame($expected, $status->status);
        if ($expected !== ProviderTransactionStatus::CONFIRMED) {
            $this->assertNull($status->credentials);
        }
        $this->assertCount(1, $client->users);
        $this->assertCount($profileCount, $client->userProfiles);
        $this->assertFalse($this->hasWrites($client->commands));
    }

    public static function interruptedStages(): array
    {
        return [
            'user created response lost' => ['/user-manager/user/add', true, ProviderTransactionStatus::RECONCILIATION_REQUIRED, 0],
            'profile not created' => ['/user-manager/user-profile/add', false, ProviderTransactionStatus::RECONCILIATION_REQUIRED, 0],
            'profile assigned response lost' => ['/user-manager/user-profile/add', true, ProviderTransactionStatus::RECONCILIATION_REQUIRED, 1],
            'enable not applied' => ['/user-manager/user/set', false, ProviderTransactionStatus::RECONCILIATION_REQUIRED, 1],
            'enable response lost' => ['/user-manager/user/set', true, ProviderTransactionStatus::CONFIRMED, 1],
        ];
    }

    public function test_missing_transaction_remains_unresolved_without_writes(): void
    {
        [$connection] = $this->catalog();
        $client = new FakeUserManagerRouterOsClient;

        $status = $this->adapter($client)->checkTransaction($connection, $this->request()->internalTransactionId);

        $this->assertSame(ProviderTransactionStatus::RECONCILIATION_REQUIRED, $status->status);
        $this->assertNull($status->credentials);
        $this->assertFalse($this->hasWrites($client->commands));
    }

    #[DataProvider('invalidReadback')]
    public function test_changed_or_incomplete_card_is_not_delivered(array $userChanges, array $profileChanges): void
    {
        [$connection] = $this->catalog();
        $client = new FakeUserManagerRouterOsClient;
        $adapter = $this->adapter($client);
        $adapter->purchaseCard($connection, $this->request());
        $client->users[0] = [...$client->users[0], ...$userChanges];
        $client->userProfiles[0] = [...$client->userProfiles[0], ...$profileChanges];
        $client->commands = [];

        $result = $adapter->checkTransaction($connection, $this->request()->internalTransactionId);

        $this->assertSame(ProviderTransactionStatus::RECONCILIATION_REQUIRED, $result->status);
        $this->assertNull($result->credentials);
        $this->assertFalse($this->hasWrites($client->commands));
    }

    public static function invalidReadback(): array
    {
        return [
            'wrong marker' => [['comment' => 'unrelated'], []],
            'changed password' => [['password' => 'changed-secret'], []],
            'disabled user' => [['disabled' => 'true'], []],
            'missing password' => [['password' => ''], []],
            'unexpected group' => [['group' => 'anonymous'], []],
            'unexpected OTP' => [['otp-secret' => 'secret'], []],
            'unexpected attributes' => [['attributes' => 'arbitrary'], []],
            'different profile' => [[], ['profile' => 'other']],
            'expired profile' => [[], ['state' => 'used']],
            'waiting profile with an expiry' => [[], ['end-time' => '2026-10-10 00:00:00']],
            'waiting profile missing expiry state' => [[], ['end-time' => '']],
            'unrecognized profile state' => [[], ['state' => 'unknown']],
        ];
    }

    public function test_extra_profile_prevents_confirmation(): void
    {
        [$connection] = $this->catalog();
        $client = new FakeUserManagerRouterOsClient;
        $adapter = $this->adapter($client);
        $adapter->purchaseCard($connection, $this->request());
        $client->userProfiles[] = [...$client->userProfiles[0], '.id' => '*other'];
        $client->commands = [];

        $result = $adapter->checkTransaction($connection, $this->request()->internalTransactionId);

        $this->assertSame(ProviderTransactionStatus::RECONCILIATION_REQUIRED, $result->status);
        $this->assertNull($result->credentials);
        $this->assertFalse($this->hasWrites($client->commands));
    }

    public function test_unready_service_prevents_issuance(): void
    {
        [$connection] = $this->catalog();
        $client = new FakeUserManagerRouterOsClient;
        $client->useProfiles = false;

        $result = $this->adapter($client)->purchaseCard($connection, $this->request());

        $this->assertSame(ProviderTransactionStatus::FAILED, $result->status);
        $this->assertSame([], $client->users);
        $this->assertFalse($this->hasWrites($client->commands));
    }

    public function test_routeros_six_is_not_reported_healthy(): void
    {
        [$connection] = $this->catalog();
        $client = new FakeUserManagerRouterOsClient;
        $client->version = '6.49.18';

        $this->assertFalse($this->adapter($client)->healthCheck($connection));
        $this->assertSame(['/system/resource/print'], $client->commands);
    }

    public function test_catalog_returns_local_decimal_prices_for_available_profiles(): void
    {
        [$connection] = $this->catalog();
        $client = new FakeUserManagerRouterOsClient;
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

    public function test_user_manager_driver_is_registered(): void
    {
        $this->assertInstanceOf(MikroTikUserManagerAdapter::class, $this->app->make(ProviderAdapterRegistry::class)->get('mikrotik_user_manager'));
    }

    public function test_partial_user_keeps_sale_unresolved_and_balance_reserved(): void
    {
        [$connection, $product] = $this->catalog();
        $user = User::factory()->create();
        $seller = Seller::query()->create(['user_id' => $user->id, 'code' => 'um-partial-seller']);
        $wallet = SellerWallet::query()->create(['seller_id' => $seller->id, 'currency_code' => 'YER']);
        $this->app->make(AdjustSellerWalletService::class)->handle($wallet, 'credit', '10000', 'um-partial-credit', 'Initial test credit');
        $sale = Sale::query()->create([
            'seller_id' => $seller->id, 'seller_wallet_id' => $wallet->id,
            'network_id' => $product->network_id, 'network_product_id' => $product->id,
            'reference_no' => 'um-partial-sale', 'idempotency_key' => 'um-partial-sale',
            'currency_code' => 'YER', 'delivery_method' => 'screen',
        ]);
        $this->app->make(SaleFinancialSnapshotService::class)->create($sale, '1000', '800', '150', '50');
        $client = new FakeUserManagerRouterOsClient;
        $client->failCommand = '/user-manager/user/add';
        $this->app->instance(RouterOsClient::class, $client);

        $this->app->make(ProcessSaleService::class)->handle($sale, $connection);
        $client->failCommand = null;
        $client->commands = [];
        $result = $this->app->make(ReconcileSaleService::class)->handle($sale);
        $replay = $this->app->make(ProcessSaleService::class)->handle($sale);

        $this->assertFalse($result->completed);
        $this->assertTrue($result->requiresReconciliation);
        $this->assertFalse($replay->completed);
        $this->assertSame('10000.0000', $wallet->fresh()->balance);
        $this->assertSame('850.0000', $wallet->fresh()->reserved_balance);
        $this->assertSame('reserved', $sale->reservation()->firstOrFail()->status);
        $this->assertSame(0, $sale->soldCard()->count());
        $this->assertDatabaseMissing('seller_ledger_entries', ['reference_id' => $sale->id, 'entry_type' => 'sale']);
        $this->assertCount(1, $client->users);
        $this->assertSame('true', $client->users[0]['disabled']);
        $this->assertFalse($this->hasWrites($client->commands));
    }

    public function test_lost_activation_response_completes_sale_after_read_only_reconciliation_with_one_debit(): void
    {
        [$connection, $product] = $this->catalog();
        $user = User::factory()->create();
        $seller = Seller::query()->create(['user_id' => $user->id, 'code' => 'um-seller']);
        $wallet = SellerWallet::query()->create(['seller_id' => $seller->id, 'currency_code' => 'YER']);
        $this->app->make(AdjustSellerWalletService::class)->handle($wallet, 'credit', '10000', 'um-initial', 'Initial test credit');
        $sale = Sale::query()->create([
            'seller_id' => $seller->id, 'seller_wallet_id' => $wallet->id,
            'network_id' => $product->network_id, 'network_product_id' => $product->id,
            'reference_no' => 'um-sale', 'idempotency_key' => 'um-sale',
            'currency_code' => 'YER', 'delivery_method' => 'screen',
        ]);
        $this->app->make(SaleFinancialSnapshotService::class)->create($sale, '1000', '800', '150', '50');
        $client = new FakeUserManagerRouterOsClient;
        $client->failCommand = '/user-manager/user/set';
        $this->app->instance(RouterOsClient::class, $client);

        $purchase = $this->app->make(ProcessSaleService::class)->handle($sale, $connection);
        $this->assertFalse($purchase->completed);
        $this->assertSame('10000.0000', $wallet->fresh()->balance);
        $this->assertSame('850.0000', $wallet->fresh()->reserved_balance);
        $client->failCommand = null;
        $client->commands = [];
        $reconciliation = $this->app->make(ReconcileSaleService::class)->handle($sale);
        $replay = $this->app->make(ProcessSaleService::class)->handle($sale);

        $this->assertTrue($reconciliation->completed);
        $this->assertTrue($replay->completed);
        $this->assertSame('9150.0000', $wallet->fresh()->balance);
        $this->assertSame('0.0000', $wallet->fresh()->reserved_balance);
        $this->assertSame(1, SellerLedgerEntry::query()->where('reference_id', $sale->id)->where('entry_type', 'sale')->count());
        $this->assertSame(1, $sale->soldCard()->count());
        $card = $sale->soldCard()->firstOrFail();
        $this->assertSame($client->users[0]['password'], $card->credentials()['password']);
        $this->assertStringNotContainsString($client->users[0]['password'], $card->getAttributes()['credentials_encrypted']);
        $this->assertCount(1, $client->users);
        $this->assertCount(1, $client->userProfiles);
        $this->assertFalse($this->hasWrites($client->commands));
    }

    private function hasWrites(array $commands): bool
    {
        return array_any($commands, fn ($command) => str_ends_with($command, '/add') || str_ends_with($command, '/set'));
    }

    private function catalog(): array
    {
        $owner = NetworkOwner::query()->create(['code' => 'um-owner', 'name' => 'User Manager Owner']);
        $network = Network::query()->create(['network_owner_id' => $owner->id, 'code' => 'um-network', 'name' => 'UM Network']);
        $connection = NetworkConnection::query()->create([
            'network_id' => $network->id, 'driver' => 'mikrotik_user_manager',
            'config' => ['host' => '192.0.2.1'], 'is_enabled' => true,
        ]);
        $connection->setCredentials(['username' => 'operator', 'password' => 'secret']);
        $connection->save();
        $product = NetworkProduct::query()->create([
            'network_id' => $network->id, 'external_product_id' => 'day', 'code' => 'um-day',
            'name' => 'Day Card', 'face_value' => '1000', 'currency_code' => 'YER',
        ]);

        return [$connection, $product];
    }

    private function request(): PurchaseCardRequest
    {
        return new PurchaseCardRequest(
            internalTransactionId: '01ARZ3NDEKTSV4RRFFQ69G5FAV', idempotencyKey: 'um-sale:1',
            externalProductId: 'day', saleId: '01ARZ3NDEKTSV4RRFFQ69G5FAW',
            expectedFaceValue: '1000.0000', currencyCode: 'YER',
        );
    }

    private function adapter(FakeUserManagerRouterOsClient $client): MikroTikUserManagerAdapter
    {
        return new MikroTikUserManagerAdapter($client, new RouterOsConnectionConfigFactory);
    }
}
