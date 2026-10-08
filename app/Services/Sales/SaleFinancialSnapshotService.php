<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Models\SaleFinancial;
use InvalidArgumentException;

class SaleFinancialSnapshotService
{
    private const SCALE = 4;

    public function create(
        Sale $sale,
        string $faceValue,
        string $providerAmount,
        string $sellerCommission,
        string $platformCommission,
        ?string $pricingRuleId = null,
        ?string $commissionRuleId = null,
    ): SaleFinancial {
        $faceValue = $this->normalize($faceValue);
        $providerAmount = $this->normalize($providerAmount);
        $sellerCommission = $this->normalize($sellerCommission);
        $platformCommission = $this->normalize($platformCommission);

        if (bccomp($faceValue, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException(
                'Face value must be greater than zero.'
            );
        }

        foreach ([
            'provider amount' => $providerAmount,
            'seller commission' => $sellerCommission,
            'platform commission' => $platformCommission,
        ] as $name => $amount) {
            if (bccomp($amount, '0', self::SCALE) < 0) {
                throw new InvalidArgumentException(
                    ucfirst($name).' cannot be negative.'
                );
            }
        }

        /*
         * provider + seller commission + platform commission
         * must equal the face value exactly.
         */
        $components = bcadd(
            bcadd(
                $providerAmount,
                $sellerCommission,
                self::SCALE
            ),
            $platformCommission,
            self::SCALE
        );

        if (bccomp($components, $faceValue, self::SCALE) !== 0) {
            throw new InvalidArgumentException(
                'Sale financial components do not equal face value.'
            );
        }

        /*
         * The seller pays face value minus their commission.
         *
         * Example:
         * face value        = 1000
         * seller commission = 150
         * seller net amount = 850
         */
        $sellerNetAmount = bcsub(
            $faceValue,
            $sellerCommission,
            self::SCALE
        );

        if (bccomp($sellerNetAmount, '0', self::SCALE) < 0) {
            throw new InvalidArgumentException(
                'Seller net amount cannot be negative.'
            );
        }

        /*
         * A sale may have only one immutable financial snapshot.
         * Returning the existing snapshot makes retries safe.
         */
        $existing = SaleFinancial::query()
            ->where('sale_id', $sale->id)
            ->first();

        if ($existing) {
            $this->assertSameSnapshot(
                $existing,
                $faceValue,
                $providerAmount,
                $sellerCommission,
                $platformCommission,
                $sellerNetAmount,
                $sale->currency_code
            );

            return $existing;
        }

        return SaleFinancial::query()->create([
            'sale_id' => $sale->id,
            'network_owner_id' => $sale->network()->firstOrFail()->network_owner_id,
            'face_value' => $faceValue,
            'provider_amount' => $providerAmount,
            'seller_commission' => $sellerCommission,
            'platform_commission' => $platformCommission,
            'seller_net_amount' => $sellerNetAmount,
            'currency_code' => $sale->currency_code,
            'pricing_rule_id' => $pricingRuleId,
            'commission_rule_id' => $commissionRuleId,
        ]);
    }

    private function normalize(string $amount): string
    {
        $amount = trim($amount);

        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $amount)) {
            throw new InvalidArgumentException(
                'Invalid monetary amount.'
            );
        }

        return bcadd($amount, '0', self::SCALE);
    }

    private function assertSameSnapshot(
        SaleFinancial $snapshot,
        string $faceValue,
        string $providerAmount,
        string $sellerCommission,
        string $platformCommission,
        string $sellerNetAmount,
        string $currencyCode,
    ): void {
        $checks = [
            [$snapshot->face_value, $faceValue],
            [$snapshot->provider_amount, $providerAmount],
            [$snapshot->seller_commission, $sellerCommission],
            [$snapshot->platform_commission, $platformCommission],
            [$snapshot->seller_net_amount, $sellerNetAmount],
        ];

        foreach ($checks as [$existing, $expected]) {
            if (
                bccomp(
                    (string) $existing,
                    $expected,
                    self::SCALE
                ) !== 0
            ) {
                throw new InvalidArgumentException(
                    'Sale financial snapshot already exists with different values.'
                );
            }
        }

        if ($snapshot->currency_code !== $currencyCode) {
            throw new InvalidArgumentException(
                'Sale financial snapshot currency mismatch.'
            );
        }
    }
}
