<?php

namespace Tests\Feature\Finance;

use App\Models\Seller;
use App\Models\SellerLedgerEntry;
use App\Models\SellerWallet;
use App\Models\User;
use App\Services\Finance\AdjustSellerWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AdjustSellerWalletTest extends TestCase
{
    use RefreshDatabase;

    private function makeWallet(
        string $sellerCode,
        string $balance = '0.0000',
        string $reserved = '0.0000'
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

        /*
         * في الاختبار فقط نجهز الرصيد مباشرة.
         * كود الإنتاج لا يفعل ذلك.
         */
        $wallet->balance = $balance;
        $wallet->reserved_balance = $reserved;
        $wallet->save();

        return [$user, $wallet];
    }

    public function test_credit_adjustment_is_posted_once(): void
    {
        [$admin, $wallet] = $this->makeWallet(
            'SEL-ADJ-001',
            '100000.0000'
        );

        $service = app(AdjustSellerWalletService::class);

        $first = $service->handle(
            $wallet,
            'credit',
            '5000.0000',
            'adjustment:test:credit:001',
            'Administrative credit',
            $admin
        );

        $second = $service->handle(
            $wallet->fresh(),
            'credit',
            '5000.0000',
            'adjustment:test:credit:001',
            'Administrative credit',
            $admin
        );

        $wallet->refresh();

        $this->assertSame('105000.0000', $wallet->balance);
        $this->assertSame($first->id, $second->id);

        $this->assertSame(
            1,
            SellerLedgerEntry::query()->count()
        );

        $this->assertSame('adjustment', $first->entry_type);
        $this->assertSame('credit', $first->direction);
        $this->assertSame('105000.0000', $first->balance_after);
    }

    public function test_debit_adjustment_reduces_balance(): void
    {
        [$admin, $wallet] = $this->makeWallet(
            'SEL-ADJ-002',
            '100000.0000'
        );

        $entry = app(AdjustSellerWalletService::class)->handle(
            $wallet,
            'debit',
            '25000.0000',
            'adjustment:test:debit:001',
            'Administrative debit',
            $admin
        );

        $wallet->refresh();

        $this->assertSame('75000.0000', $wallet->balance);
        $this->assertSame('debit', $entry->direction);
        $this->assertSame('25000.0000', $entry->amount);
        $this->assertSame('75000.0000', $entry->balance_after);
    }

    public function test_debit_cannot_use_reserved_balance(): void
    {
        [$admin, $wallet] = $this->makeWallet(
            'SEL-ADJ-003',
            '100000.0000',
            '80000.0000'
        );

        try {
            app(AdjustSellerWalletService::class)->handle(
                $wallet,
                'debit',
                '25000.0000',
                'adjustment:test:debit:reserved',
                'Should fail',
                $admin
            );

            $this->fail(
                'Expected insufficient available balance exception.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Insufficient available seller balance.',
                $exception->getMessage()
            );
        }

        $wallet->refresh();

        $this->assertSame('100000.0000', $wallet->balance);
        $this->assertSame('80000.0000', $wallet->reserved_balance);
        $this->assertSame(
            0,
            SellerLedgerEntry::query()->count()
        );
    }

    public function test_debit_cannot_make_balance_negative(): void
    {
        [$admin, $wallet] = $this->makeWallet(
            'SEL-ADJ-004',
            '10000.0000'
        );

        try {
            app(AdjustSellerWalletService::class)->handle(
                $wallet,
                'debit',
                '10000.0001',
                'adjustment:test:negative',
                'Should fail',
                $admin
            );

            $this->fail(
                'Expected insufficient balance exception.'
            );
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Insufficient available seller balance.',
                $exception->getMessage()
            );
        }

        $wallet->refresh();

        $this->assertSame('10000.0000', $wallet->balance);
        $this->assertSame(
            0,
            SellerLedgerEntry::query()->count()
        );
    }
}
