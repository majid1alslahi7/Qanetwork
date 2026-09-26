<?php

namespace Tests\Feature\Finance;

use App\Models\Seller;
use App\Models\SellerLedgerEntry;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Finance\AdjustSellerWalletService;
use App\Services\Finance\ReverseSellerLedgerEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ReverseSellerLedgerEntryTest extends TestCase
{
    use RefreshDatabase;

    private function makeWallet(
        string $sellerCode,
        string $balance = '0.0000'
    ): array {
        $user = User::factory()->create();

        $seller = Seller::query()->create([
            'user_id' => $user->id,
            'code' => $sellerCode,
        ]);

        $wallet = SellerWallet::query()->create([
            'seller_id' => $seller->id,
            'currency_code' => 'YER',
        ]);

        if (bccomp($balance, '0', 4) === 1) {
            app(AdjustSellerWalletService::class)->handle(
                $wallet,
                'credit',
                $balance,
                'setup:' . $sellerCode,
                'Test opening balance',
                $user
            );
        }

        return [$user, $wallet->fresh()];
    }

    public function test_credit_entry_can_be_reversed(): void
    {
        [$admin, $wallet] = $this->makeWallet(
            'SEL-REV-001'
        );

        $original = app(AdjustSellerWalletService::class)->handle(
            $wallet,
            'credit',
            '100000.0000',
            'adjustment:rev:credit:001',
            'Credit to reverse',
            $admin
        );

        $reversal = app(ReverseSellerLedgerEntryService::class)->handle(
            $original,
            'reversal:credit:001',
            'Reverse incorrect credit',
            $admin
        );

        $wallet->refresh();

        $this->assertSame('0.0000', $wallet->balance);
        $this->assertSame('reversal', $reversal->entry_type);
        $this->assertSame('debit', $reversal->direction);
        $this->assertSame('100000.0000', $reversal->amount);
        $this->assertSame('0.0000', $reversal->balance_after);
        $this->assertSame($original->id, $reversal->reversal_of_entry_id);
    }

    public function test_debit_entry_can_be_reversed(): void
    {
        [$admin, $wallet] = $this->makeWallet(
            'SEL-REV-002',
            '100000.0000'
        );

        $original = app(AdjustSellerWalletService::class)->handle(
            $wallet,
            'debit',
            '25000.0000',
            'adjustment:rev:debit:001',
            'Debit to reverse',
            $admin
        );

        $this->assertSame(
            '75000.0000',
            $wallet->fresh()->balance
        );

        $reversal = app(ReverseSellerLedgerEntryService::class)->handle(
            $original,
            'reversal:debit:001',
            'Reverse incorrect debit',
            $admin
        );

        $wallet->refresh();

        $this->assertSame('100000.0000', $wallet->balance);
        $this->assertSame('credit', $reversal->direction);
        $this->assertSame('25000.0000', $reversal->amount);
        $this->assertSame('100000.0000', $reversal->balance_after);
    }

    public function test_same_reversal_request_is_idempotent(): void
    {
        [$admin, $wallet] = $this->makeWallet(
            'SEL-REV-003'
        );

        $original = app(AdjustSellerWalletService::class)->handle(
            $wallet,
            'credit',
            '50000.0000',
            'adjustment:rev:idempotent',
            'Credit',
            $admin
        );

        $service = app(ReverseSellerLedgerEntryService::class);

        $first = $service->handle(
            $original,
            'reversal:idempotent:001',
            'Reverse credit',
            $admin
        );

        $second = $service->handle(
            $original->fresh(),
            'reversal:idempotent:001',
            'Repeated request',
            $admin
        );

        $wallet->refresh();

        $this->assertSame($first->id, $second->id);
        $this->assertSame('0.0000', $wallet->balance);

        $this->assertSame(
            1,
            SellerLedgerEntry::query()
                ->where('reversal_of_entry_id', $original->id)
                ->count()
        );
    }

    public function test_entry_cannot_be_reversed_twice_with_different_keys(): void
    {
        [$admin, $wallet] = $this->makeWallet(
            'SEL-REV-004'
        );

        $original = app(AdjustSellerWalletService::class)->handle(
            $wallet,
            'credit',
            '30000.0000',
            'adjustment:rev:double',
            'Credit',
            $admin
        );

        $service = app(ReverseSellerLedgerEntryService::class);

        $service->handle(
            $original,
            'reversal:double:001',
            'First reversal',
            $admin
        );

        try {
            $service->handle(
                $original->fresh(),
                'reversal:double:002',
                'Second reversal',
                $admin
            );

            $this->fail(
                'Expected duplicate reversal to fail.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Ledger entry has already been reversed.',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            '0.0000',
            $wallet->fresh()->balance
        );

        $this->assertSame(
            1,
            SellerLedgerEntry::query()
                ->where('reversal_of_entry_id', $original->id)
                ->count()
        );
    }

    public function test_credit_reversal_cannot_consume_reserved_balance(): void
    {
        [$admin, $wallet] = $this->makeWallet(
            'SEL-REV-005'
        );

        $original = app(AdjustSellerWalletService::class)->handle(
            $wallet,
            'credit',
            '100000.0000',
            'adjustment:rev:reserved',
            'Credit',
            $admin
        );

        $wallet->reserved_balance = '80000.0000';
        $wallet->save();

        try {
            app(ReverseSellerLedgerEntryService::class)->handle(
                $original,
                'reversal:reserved:001',
                'Should fail',
                $admin
            );

            $this->fail(
                'Expected reversal to fail because funds are reserved.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Insufficient available seller balance for reversal.',
                $exception->getMessage()
            );
        }

        $wallet->refresh();

        $this->assertSame('100000.0000', $wallet->balance);
        $this->assertSame('80000.0000', $wallet->reserved_balance);

        $this->assertSame(
            0,
            SellerLedgerEntry::query()
                ->where('reversal_of_entry_id', $original->id)
                ->count()
        );
    }

    public function test_reversal_entry_cannot_be_reversed(): void
    {
        [$admin, $wallet] = $this->makeWallet(
            'SEL-REV-006'
        );

        $original = app(AdjustSellerWalletService::class)->handle(
            $wallet,
            'credit',
            '20000.0000',
            'adjustment:rev:nested',
            'Credit',
            $admin
        );

        $reversal = app(ReverseSellerLedgerEntryService::class)->handle(
            $original,
            'reversal:nested:001',
            'First reversal',
            $admin
        );

        try {
            app(ReverseSellerLedgerEntryService::class)->handle(
                $reversal,
                'reversal:nested:002',
                'Attempt reversal of reversal',
                $admin
            );

            $this->fail(
                'Expected reversal of reversal to fail.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'A reversal entry cannot be reversed.',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            '0.0000',
            $wallet->fresh()->balance
        );
    }
}
