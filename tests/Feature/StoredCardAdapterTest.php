<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\InventoryCard;
use App\Models\Network;
use App\Models\NetworkConnection;
use App\Models\NetworkOwner;
use App\Models\NetworkProduct;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\SellerWallet;
use App\Models\SoldCard;
use App\Models\User;
use App\Providers\Data\PurchaseCardRequest;
use App\Providers\Enums\ProviderTransactionStatus;
use App\Providers\Inventory\StoredCardAdapter;
use App\Services\Finance\AdjustSellerWalletService;
use App\Services\Networks\ImportInventoryCardsService;
use App\Services\Providers\PrepareProviderTransactionService;
use App\Services\Sales\PaidSaleCardService;
use App\Services\Sales\ProcessSaleService;
use App\Services\Sales\ReserveSaleBalanceService;
use App\Services\Sales\SaleFinancialSnapshotService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

class StoredCardAdapterTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('credentials')]
    public function test_inventory_allocates_exactly_one_encrypted_card_and_reconciliation_is_read_only(array $row): void
    {
        [$admin, $connection, $product, $sale, $request] = $this->catalog();
        app(ImportInventoryCardsService::class)->handle($admin, $connection->network, $product, [$row, ['username' => 'another-card']]);
        $adapter = app(StoredCardAdapter::class);
        $result = $adapter->purchaseCard($connection, $request);
        $this->assertSame(ProviderTransactionStatus::CONFIRMED, $result->status);
        $this->assertSame($row['username'], $result->credentials['username']);
        $this->assertStringNotContainsString($row['username'], DB::table('inventory_cards')->where('id', $result->providerTransactionId)->value('credentials_encrypted'));
        $this->assertStringNotContainsString($row['username'], InventoryCard::query()->findOrFail($result->providerTransactionId)->toJson());
        $replay = $adapter->purchaseCard($connection, $request);
        $status = $adapter->checkTransaction($connection, $request->internalTransactionId, $result->providerTransactionId);
        $this->assertSame($result->credentials, $replay->credentials);
        $this->assertSame($result->credentials, $status->credentials);
        $this->assertSame(1, InventoryCard::query()->where('status', 'allocated')->count());
        $this->assertSame(1, InventoryCard::query()->where('status', 'available')->count());
        $this->assertSame($sale->id, InventoryCard::query()->where('status', 'allocated')->sole()->sale_id);
        $card = new SoldCard;
        $card->setCredentials($result->credentials);
        $this->assertSame($result->credentials, app(PaidSaleCardService::class)->credentials($card));
    }

    public function test_full_inventory_sale_captures_balance_once_and_stock_exhaustion_releases_reservation(): void
    {
        [$admin, $connection, $product, $sale] = $this->catalog();
        app(ImportInventoryCardsService::class)->handle($admin, $connection->network, $product, [['username' => '000001']]);
        $service = app(ProcessSaleService::class);
        $this->assertTrue($service->handle($sale, $connection)->completed);
        $wallet = $sale->wallet()->firstOrFail();
        $balance = $wallet->fresh()->balance;
        $this->assertSame('910.0000', $balance);
        $this->assertSame('0.0000', $wallet->fresh()->reserved_balance);
        $service->handle($sale, $connection);
        $this->assertSame($balance, $wallet->fresh()->balance);
        $this->assertDatabaseCount('sold_cards', 1);
        $this->assertDatabaseCount('inventory_cards', 1);
        $next = Sale::query()->create(['seller_id' => $sale->seller_id, 'seller_wallet_id' => $sale->seller_wallet_id, 'network_id' => $sale->network_id, 'network_product_id' => $product->id, 'reference_no' => 'STOCK-NEXT', 'idempotency_key' => 'stock-next', 'currency_code' => 'YER']);
        app(SaleFinancialSnapshotService::class)->create($next, '100', '80', '10', '10');
        $this->assertFalse($service->handle($next, $connection)->completed);
        $this->assertSame('failed', $next->fresh()->status);
        $this->assertSame($balance, $wallet->fresh()->balance);
        $this->assertSame('0.0000', $wallet->fresh()->reserved_balance);
        $this->assertSame('released', $next->reservation()->firstOrFail()->status);
    }

    public static function credentials(): array
    {
        return [[['username' => '00123456789']], [['username' => 'user-a', 'password' => ' leading and trailing ']], [['username' => '123456', 'password' => '000007']]];
    }

    public function test_duplicate_batch_rolls_back_and_expired_cards_never_allocate(): void
    {
        [$admin, $connection, $product, , $request] = $this->catalog();
        try {
            app(ImportInventoryCardsService::class)->handle($admin, $connection->network, $product, [['username' => '001'], ['username' => '001']]);
            $this->fail('Duplicate cards were imported.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('inventory_cards', 0);
        }
        InventoryCard::factory()->create(['network_id' => $product->network_id, 'network_product_id' => $product->id, 'expires_at' => now()->subSecond()]);
        $this->assertFalse(app(StoredCardAdapter::class)->checkAvailability($connection, $product->external_product_id));
        $this->assertSame(ProviderTransactionStatus::FAILED, app(StoredCardAdapter::class)->purchaseCard($connection, $request)->status);
        $this->assertSame('available', InventoryCard::query()->sole()->status);
    }

    public function test_foreign_transaction_or_reconciliation_identifier_cannot_allocate_or_reveal_stock(): void
    {
        [$admin, $connection, $product, , $request] = $this->catalog();
        app(ImportInventoryCardsService::class)->handle($admin, $connection->network, $product, [['username' => '001']]);
        $foreign = new PurchaseCardRequest($request->internalTransactionId, $request->idempotencyKey, $request->externalProductId, (string) Str::ulid(), $request->expectedFaceValue, 'YER');
        $this->assertSame(ProviderTransactionStatus::UNKNOWN, app(StoredCardAdapter::class)->purchaseCard($connection, $foreign)->status);
        $this->assertNull(app(StoredCardAdapter::class)->checkTransaction($connection, (string) Str::ulid())->credentials);
        $this->assertSame('available', InventoryCard::query()->sole()->status);
    }

    public function test_owner_cannot_import_into_another_network_and_partial_username_is_not_revealed_as_valid(): void
    {
        [, $connection, $product] = $this->catalog();
        $ownerUser = User::factory()->create(['role' => UserRole::NETWORK_OWNER]);
        $owner = new NetworkOwner(['code' => 'another-owner', 'name' => 'Another owner']);
        $owner->user_id = $ownerUser->id;
        $owner->status = 'active';
        $owner->save();
        try {
            app(ImportInventoryCardsService::class)->handle($ownerUser, $connection->network, $product, [['username' => 'secret']]);
            $this->fail('Foreign owner imported cards.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('inventory_cards', 0);
        }
        $card = new SoldCard;
        $card->setCredentials(['username' => 'incomplete']);
        $this->expectException(ServiceUnavailableHttpException::class);
        app(PaidSaleCardService::class)->credentials($card);
    }

    /** @return array{User, NetworkConnection, NetworkProduct, Sale, PurchaseCardRequest} */
    private function catalog(): array
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $owner = NetworkOwner::query()->create(['code' => 'stock-owner', 'name' => 'Stock owner']);
        $network = Network::query()->create(['network_owner_id' => $owner->id, 'code' => 'stock-network', 'name' => 'Stock network']);
        $connection = NetworkConnection::query()->create(['network_id' => $network->id, 'driver' => 'stored_cards', 'is_enabled' => true, 'is_primary' => true]);
        $product = NetworkProduct::query()->create(['network_id' => $network->id, 'code' => 'stock-product', 'external_product_id' => 'day', 'name' => 'Day', 'face_value' => '100', 'currency_code' => 'YER']);
        $product->status = 'active';
        $product->save();
        $user = User::factory()->create();
        $seller = Seller::query()->create(['user_id' => $user->id, 'code' => 'stock-seller']);
        $wallet = SellerWallet::query()->create(['seller_id' => $seller->id, 'currency_code' => 'YER']);
        app(AdjustSellerWalletService::class)->handle($wallet, 'credit', '1000', 'stock-funding', 'Initial inventory test funding');
        $sale = Sale::query()->create(['seller_id' => $seller->id, 'seller_wallet_id' => $wallet->id, 'network_id' => $network->id, 'network_product_id' => $product->id, 'reference_no' => 'STOCK-SALE', 'idempotency_key' => 'stock-sale', 'currency_code' => 'YER']);
        app(SaleFinancialSnapshotService::class)->create($sale, '100', '80', '10', '10');
        app(ReserveSaleBalanceService::class)->handle($sale, 'sale:'.$sale->id.':reservation');
        $transaction = app(PrepareProviderTransactionService::class)->handle($sale, $connection);
        $request = new PurchaseCardRequest($transaction->internal_transaction_id, $transaction->idempotency_key, 'day', $sale->id, '100.0000', 'YER');

        return [$admin, $connection, $product, $sale, $request];
    }
}
