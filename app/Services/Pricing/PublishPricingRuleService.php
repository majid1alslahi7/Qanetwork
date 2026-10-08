<?php

namespace App\Services\Pricing;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\CommissionRule;
use App\Models\NetworkProduct;
use App\Models\PricingRule;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublishPricingRuleService
{
    public function handle(User $actor, NetworkProduct $product, string $providerAmount, string $sellerCommission): PricingRule
    {
        foreach (['provider_amount' => $providerAmount, 'seller_commission' => $sellerCommission] as $field => $amount) {
            if (! preg_match('/\A(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,4})?\z/', $amount)) {
                throw ValidationException::withMessages([$field => 'Enter a non-negative decimal with up to four decimal places.']);
            }
        }

        return DB::transaction(function () use ($actor, $product, $providerAmount, $sellerCommission): PricingRule {
            $currentActor = User::query()->lockForUpdate()->findOrFail($actor->id);
            if ($currentActor->role !== UserRole::ADMIN || ! $currentActor->canAccessApplication()) {
                throw new AuthorizationException;
            }
            $current = NetworkProduct::query()->lockForUpdate()->findOrFail($product->id);
            $platform = bcsub(bcsub($current->face_value, $providerAmount, 4), $sellerCommission, 4);
            if (bccomp($platform, '0', 4) < 0 || bccomp($current->face_value, '0', 4) <= 0) {
                throw ValidationException::withMessages(['seller_commission' => 'Provider amount and seller commission cannot exceed the face value.']);
            }
            if (bccomp(bcsub($current->face_value, $sellerCommission, 4), '0', 4) <= 0) {
                throw ValidationException::withMessages(['seller_commission' => 'The seller net amount must be positive.']);
            }
            $previous = PricingRule::query()->where('network_product_id', $current->id)->where('is_active', true)->get();
            foreach ($previous as $old) {
                $old->is_active = false;
                $old->save();
            }
            $rule = PricingRule::query()->create(['network_product_id' => $current->id,
                'face_value' => $current->face_value, 'provider_amount' => bcadd($providerAmount, '0', 4),
                'currency_code' => $current->currency_code, 'created_by' => $actor->id]);
            CommissionRule::query()->create(['pricing_rule_id' => $rule->id, 'seller_id' => null,
                'seller_commission' => bcadd($sellerCommission, '0', 4), 'created_by' => $actor->id]);
            $rule->refresh()->load('commissions');
            AuditEvent::query()->create(['actor_id' => $actor->id, 'event_type' => 'pricing.published',
                'subject_type' => 'network_product', 'subject_id' => $current->id,
                'before' => ['pricing_rule_ids' => $previous->modelKeys()],
                'after' => ['pricing_rule_id' => $rule->id, 'provider_amount' => $rule->provider_amount,
                    'seller_commission' => bcadd($sellerCommission, '0', 4), 'platform_commission' => $platform]]);

            return $rule;
        }, 3);
    }
}
