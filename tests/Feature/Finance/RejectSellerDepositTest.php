<?php

namespace Tests\Feature\Finance;

use App\Models\Seller;
use App\Models\SellerDeposit;
use App\Models\SellerLedgerEntry;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Finance\ApproveSellerDepositService;
use App\Services\Finance\RejectSellerDepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RejectSellerDepositTest extends TestCase
{
    use RefreshDatabase;

    private function makeDeposit(string $sellerCode, string $reference): array
    {
        $user = User::factory()->create();

        $seller = Seller::query()->create([
            'user_id' => $user->id,
            'code' => $sellerCode,
        ]);

        $wallet = SellerWallet::query()->create([
            'seller_id' => $seller->id,
            'currency_code' => 'YER',
        ]);

        $deposit = SellerDeposit::query()->create([
            'seller_id' => $seller->id,
            'seller_wallet_id' => $wallet->id,
            'reference_no' => $reference,
            'amount' => '100000.0000',
            'currency_code' => 'YER',
            'payment_method' => 'cash',
            'submitted_at' => now(),
        ]);

        return [$user, $wallet, $deposit];
    }

    public function test_rejected_deposit_does_not_change_wallet_balance(): void
    {
        [$user, $wallet, $deposit] = $this->makeDeposit(
            'SEL-REJ-001',
            'DEP-REJ-001'
        );

        $service = app(RejectSellerDepositService::class);

        $service->handle(
            $deposit,
            $user,
            'Receipt could not be verified'
        );

        // الاستدعاء الثاني يجب ألا يسبب أثراً إضافياً.
        $service->handle(
            $deposit->fresh(),
            $user,
            'Repeated rejection'
        );

        $wallet->refresh();
        $deposit->refresh();

        $this->assertSame('rejected', $deposit->status);
        $this->assertSame('0.0000', $wallet->balance);
        $this->assertNotNull($deposit->rejected_at);
        $this->assertNull($deposit->approved_at);
        $this->assertSame(0, SellerLedgerEntry::query()->count());
    }

    public function test_approved_deposit_cannot_be_rejected(): void
    {
        [$user, $wallet, $deposit] = $this->makeDeposit(
            'SEL-REJ-002',
            'DEP-REJ-002'
        );

        app(ApproveSellerDepositService::class)
            ->handle($deposit, $user);

        try {
            app(RejectSellerDepositService::class)
                ->handle($deposit->fresh(), $user);

            $this->fail(
                'Expected approved deposit rejection to fail.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Only pending deposits can be rejected.',
                $exception->getMessage()
            );
        }

        $wallet->refresh();
        $deposit->refresh();

        $this->assertSame('approved', $deposit->status);
        $this->assertSame('100000.0000', $wallet->balance);

        $this->assertSame(
            1,
            SellerLedgerEntry::query()->count()
        );
    }
}
