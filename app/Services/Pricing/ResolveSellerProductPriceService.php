<?php

namespace App\Services\Pricing;

use App\Models\NetworkProduct;
use Illuminate\Validation\ValidationException;

class ResolveSellerProductPriceService
{
    /**
     * @return array{pricing_rule_id: string, commission_rule_id: string, currency_code: string, face_value: string,
     *     provider_amount: string, seller_commission: string, platform_commission: string, seller_net_amount: string}
     */
    public function handle(NetworkProduct $product, string $sellerId): array
    {
        $rules = $product->relationLoaded('pricingRules') ? $product->pricingRules->where('is_active', true)
            : $product->pricingRules()->where('is_active', true)->get();
        if ($rules->count() !== 1) {
            throw ValidationException::withMessages(['product' => 'Exactly one active pricing rule is required.']);
        }
        $pricing = $rules->first();
        if ($pricing->currency_code !== $product->currency_code || bccomp($pricing->face_value, $product->face_value, 4) !== 0) {
            throw ValidationException::withMessages(['product' => 'The product requires a new pricing rule.']);
        }
        $allCommissions = $pricing->relationLoaded('commissions') ? $pricing->commissions->where('is_active', true)
            : $pricing->commissions()->where('is_active', true)->where(function ($query) use ($sellerId): void {
                $query->where('seller_id', $sellerId)->orWhereNull('seller_id');
            })->get();
        $commissions = $allCommissions->filter(fn ($commission): bool => $commission->seller_id === $sellerId);
        if ($commissions->isEmpty()) {
            $commissions = $allCommissions->filter(fn ($commission): bool => $commission->seller_id === null);
        }
        if ($commissions->count() !== 1) {
            throw ValidationException::withMessages(['product' => 'Exactly one applicable commission rule is required.']);
        }
        $commission = $commissions->first();
        $platform = bcsub(bcsub($pricing->face_value, $pricing->provider_amount, 4), $commission->seller_commission, 4);
        $net = bcsub($pricing->face_value, $commission->seller_commission, 4);
        if (bccomp($platform, '0', 4) < 0 || bccomp($net, '0', 4) <= 0
            || bccomp($pricing->provider_amount, '0', 4) < 0 || bccomp($commission->seller_commission, '0', 4) < 0) {
            throw ValidationException::withMessages(['product' => 'The applicable commission rule is invalid.']);
        }

        return ['pricing_rule_id' => $pricing->id, 'commission_rule_id' => $commission->id,
            'currency_code' => $pricing->currency_code, 'face_value' => $pricing->face_value,
            'provider_amount' => $pricing->provider_amount, 'seller_commission' => $commission->seller_commission,
            'platform_commission' => $platform, 'seller_net_amount' => $net];
    }
}
