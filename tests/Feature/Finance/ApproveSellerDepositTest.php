<?php

namespace Tests\Feature\Finance;

use App\Models\Seller;
use App\Models\SellerDeposit;
use App\Models\SellerLedgerEntry;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Finance\ApproveSellerDepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApproveSellerDepositTest extends TestCase
{
    use RefreshDatabase;

    public function test_deposit_is_posted_once_even_if_approval_is_called_twice(): void
    {
        $user = User::factory()->create();

        $seller = Seller::query()->create([
            'user_id' => $user->id,
            'code' => 'SEL-000001',
            'business_name' => 'Test Seller',
        ]);

        $wallet = SellerWallet::query()->create([
            'seller_id' => $seller->id,
            'currency_code' => 'YER',
        ]);

        $deposit = SellerDeposit::query()->create([
            'seller_id' => $seller->id,
            'seller_wallet_id' => $wallet->id,
            'reference_no' => 'DEP-000001',
            'amount' => '100000.0000',
            'currency_code' => 'YER',
            'payment_method' => 'cash',
            'submitted_at' => now(),
        ]);

        $service = app(ApproveSellerDepositService::class);

        $service->handle($deposit, $user, 'Approved in test');
        $service->handle($deposit->fresh(), $user, 'Repeated approval');

        $wallet->refresh();
        $deposit->refresh();

        $this->assertSame('approved', $deposit->status);
        $this->assertSame('100000.0000', $wallet->balance);

        $this->assertSame(
            1,
            SellerLedgerEntry::query()
                ->where('reference_type', 'seller_deposit')
                ->where('reference_id', $deposit->id)
                ->count()
        );

        $entry = SellerLedgerEntry::query()
            ->where('reference_type', 'seller_deposit')
            ->where('reference_id', $deposit->id)
            ->firstOrFail();

        $this->assertSame('deposit', $entry->entry_type);
        $this->assertSame('credit', $entry->direction);
        $this->assertSame('100000.0000', $entry->amount);
        $this->assertSame('100000.0000', $entry->balance_after);
        $this->assertSame(
            'deposit:' . $deposit->id . ':approved',
            $entry->idempotency_key
        );
    }

    public function test_deposit_currency_must_match_wallet_currency(): void
    {
        $user = User::factory()->create();

        $seller = Seller::query()->create([
            'user_id' => $user->id,
            'code' => 'SEL-000002',
        ]);

        $wallet = SellerWallet::query()->create([
            'seller_id' => $seller->id,
            'currency_code' => 'YER',
        ]);

        $deposit = SellerDeposit::query()->create([
            'seller_id' => $seller->id,
            'seller_wallet_id' => $wallet->id,
            'reference_no' => 'DEP-000002',
            'amount' => '50000.0000',
            'currency_code' => 'USD',
            'payment_method' => 'cash',
            'submitted_at' => now(),
        ]);

        try {
            app(ApproveSellerDepositService::class)
                ->handle($deposit, $user);

            $this->fail('Expected currency mismatch exception.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Deposit currency does not match wallet currency.',
                $exception->getMessage()
            );
        }

        $wallet->refresh();
        $deposit->refresh();

        $this->assertSame('0.0000', $wallet->balance);
        $this->assertSame('pending', $deposit->status);
        $this->assertSame(0, SellerLedgerEntry::query()->count());
    }
}
