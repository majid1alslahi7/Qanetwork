<?php

namespace App\Services\Pricing;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\CommissionRule;
use App\Models\NetworkProduct;
use App\Models\PricingRule;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageSellerCommissionService
{
    public function publish(User $actor, PricingRule $pricing, Seller $seller, string $amount): CommissionRule
    {
        if (! preg_match('/\A(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,4})?\z/', $amount)) {
            throw ValidationException::withMessages(['seller_commission' => 'Enter a non-negative decimal with up to four decimal places.']);
        }

        return DB::transaction(function () use ($actor, $pricing, $seller, $amount): CommissionRule {
            $currentPricing = $this->lockPricing($actor, $pricing);
            if (! $currentPricing->is_active) {
                throw ValidationException::withMessages(['pricing' => 'Publish commissions only for an active pricing rule.']);
            }
            Seller::query()->findOrFail($seller->id);
            $platform = bcsub(bcsub($currentPricing->face_value, $currentPricing->provider_amount, 4), $amount, 4);
            if (bccomp($platform, '0', 4) < 0 || bccomp(bcsub($currentPricing->face_value, $amount, 4), '0', 4) <= 0) {
                throw ValidationException::withMessages(['seller_commission' => 'The commission exceeds the available margin or leaves no seller payment.']);
            }
            $previous = $currentPricing->commissions()->where('seller_id', $seller->id)->where('is_active', true)->get();
            if ($previous->count() === 1 && bccomp($previous->first()->seller_commission, $amount, 4) === 0) {
                return $previous->first();
            }
            foreach ($previous as $old) {
                $old->is_active = false;
                $old->save();
            }
            $rule = CommissionRule::query()->create(['pricing_rule_id' => $currentPricing->id, 'seller_id' => $seller->id,
                'seller_commission' => bcadd($amount, '0', 4), 'created_by' => $actor->id]);
            $rule->refresh();
            AuditEvent::query()->create(['actor_id' => $actor->id, 'event_type' => 'commission.published',
                'subject_type' => 'commission_rule', 'subject_id' => $rule->id,
                'before' => ['commission_rule_ids' => $previous->modelKeys()],
                'after' => ['pricing_rule_id' => $currentPricing->id, 'seller_id' => $seller->id,
                    'seller_commission' => $rule->seller_commission, 'is_active' => true]]);

            return $rule;
        }, 3);
    }

    public function retire(User $actor, PricingRule $pricing, CommissionRule $commission): CommissionRule
    {
        return DB::transaction(function () use ($actor, $pricing, $commission): CommissionRule {
            $currentPricing = $this->lockPricing($actor, $pricing);
            $current = $currentPricing->commissions()->lockForUpdate()->findOrFail($commission->id);
            if ($current->seller_id === null) {
                throw ValidationException::withMessages(['commission' => 'The general commission must be retained; publish a new pricing rule to replace it.']);
            }
            if (! $current->is_active) {
                return $current;
            }
            $current->is_active = false;
            $current->save();
            AuditEvent::query()->create(['actor_id' => $actor->id, 'event_type' => 'commission.retired',
                'subject_type' => 'commission_rule', 'subject_id' => $current->id,
                'before' => ['is_active' => true], 'after' => ['is_active' => false]]);

            return $current;
        }, 3);
    }

    private function lockPricing(User $actor, PricingRule $pricing): PricingRule
    {
        $currentActor = User::query()->lockForUpdate()->findOrFail($actor->id);
        if ($currentActor->role !== UserRole::ADMIN || ! $currentActor->canAccessApplication()) {
            throw new AuthorizationException;
        }
        NetworkProduct::query()->lockForUpdate()->findOrFail($pricing->network_product_id);

        return PricingRule::query()->lockForUpdate()->findOrFail($pricing->id);
    }
}
