<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Seller;
use App\Models\SellerWallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SellerWalletAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_only_sees_own_wallets_with_decimal_available_balance(): void
    {
        [$user, $wallet] = $this->seller('first');
        [, $otherWallet] = $this->seller('other');
        $wallet->balance = '1000.0000';
        $wallet->reserved_balance = '150.0000';
        $wallet->save();

        $response = $this->withToken($user->createToken('seller-device', ['account', 'seller'])->plainTextToken)
            ->getJson('/api/v1/seller/wallets');

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $wallet->id)
            ->assertJsonPath('data.0.available_balance', '850.0000');
        $this->assertSame(['id', 'currency_code', 'balance', 'reserved_balance', 'available_balance', 'status'], array_keys($response->json('data.0')));
        $this->assertStringNotContainsString($otherWallet->id, $response->getContent());
    }

    public function test_other_sellers_wallet_and_missing_wallet_both_return_404(): void
    {
        [$user] = $this->seller('first');
        [, $otherWallet] = $this->seller('other');
        $token = $user->createToken('device', ['account', 'seller'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/seller/wallets/'.$otherWallet->id)->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/seller/wallets/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
    }

    public function test_admin_role_cannot_enter_seller_area_even_with_seller_token_ability(): void
    {
        $user = User::factory()->create();
        $user->role = UserRole::ADMIN;
        $user->save();

        $this->withToken($user->createToken('device', ['account', 'seller'])->plainTextToken)
            ->getJson('/api/v1/seller/wallets')->assertForbidden();
    }

    public function test_seller_token_without_seller_ability_returns_403(): void
    {
        [$user] = $this->seller('first');

        $this->withToken($user->createToken('device', ['account'])->plainTextToken)
            ->getJson('/api/v1/seller/wallets')->assertForbidden();
    }

    public function test_suspended_seller_cannot_use_existing_token(): void
    {
        [$user] = $this->seller('first');
        $token = $user->createToken('device', ['account', 'seller'])->plainTextToken;
        $seller = $user->seller()->firstOrFail();
        $seller->status = 'suspended';
        $seller->save();

        $this->withToken($token)->getJson('/api/v1/seller/wallets')->assertForbidden();
    }

    public function test_unauthenticated_wallet_request_returns_401(): void
    {
        $this->getJson('/api/v1/seller/wallets')->assertUnauthorized();
    }

    private function seller(string $code): array
    {
        $user = User::factory()->create();
        $seller = Seller::query()->create(['user_id' => $user->id, 'code' => $code]);
        $seller->status = 'active';
        $seller->save();
        $wallet = SellerWallet::query()->create(['seller_id' => $seller->id, 'currency_code' => 'YER']);

        return [$user, $wallet];
    }
}
