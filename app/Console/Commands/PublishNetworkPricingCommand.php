<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Network;
use App\Models\User;
use App\Services\Pricing\PublishPricingRuleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

#[Signature('qanetwork:publish-pricing {network : Network ID or code} {--actor=} {--owner-percent=} {--seller-percent=} {--product=* : Explicit external product IDs}')]
#[Description('Publish an atomic percentage split for selected products, preserving all existing sale snapshots')]
class PublishNetworkPricingCommand extends Command
{
    public function handle(PublishPricingRuleService $publisher): int
    {
        $data = ['actor' => $this->option('actor'), 'owner' => $this->option('owner-percent'), 'seller' => $this->option('seller-percent'), 'products' => $this->option('product')];
        $validator = Validator::make($data, [
            'actor' => ['required', 'email'],
            'owner' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,4})?\z/'],
            'seller' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,4})?\z/'],
            'products' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'products.*' => ['required', 'string', 'max:191', 'distinct:strict'],
        ]);
        if ($validator->fails() || bccomp(bcadd($data['owner'], $data['seller'], 4), '100', 4) > 0) {
            $this->error('Specify an actor, explicit unique products and non-negative percentages totaling at most 100.');

            return self::FAILURE;
        }
        try {
            $rows = DB::transaction(function () use ($data, $publisher): array {
                $actor = User::query()->where('email', $data['actor'])->lockForUpdate()->firstOrFail();
                if ($actor->role !== UserRole::ADMIN || ! $actor->canAccessApplication()) {
                    throw new AuthorizationException;
                }
                $network = Network::query()->where(fn ($query) => $query->whereKey($this->argument('network'))->orWhere('code', $this->argument('network')))->lockForUpdate()->firstOrFail();
                $products = $network->products()->whereIn('external_product_id', $data['products'])->orderBy('id')->lockForUpdate()->get();
                if ($products->count() !== count($data['products'])) {
                    throw ValidationException::withMessages(['products' => 'Every selected product must belong to the network.']);
                }
                $rows = [];
                foreach ($products as $product) {
                    $owner = bcdiv(bcmul($product->face_value, $data['owner'], 8), '100', 4);
                    $seller = bcdiv(bcmul($product->face_value, $data['seller'], 8), '100', 4);
                    $rules = $product->pricingRules()->where('is_active', true)->get();
                    $rule = $rules->count() === 1 ? $rules->first() : null;
                    $commissions = $rule?->commissions()->where('is_active', true)->get();
                    $same = $rule !== null && $rule->currency_code === $product->currency_code && bccomp($rule->face_value, $product->face_value, 4) === 0
                        && bccomp($rule->provider_amount, $owner, 4) === 0 && $commissions->count() === 1
                        && $commissions->first()->seller_id === null && bccomp($commissions->first()->seller_commission, $seller, 4) === 0;
                    if (! $same) {
                        $publisher->handle($actor, $product, $owner, $seller);
                    }
                    $rows[] = [$product->external_product_id, $product->face_value, $owner, $seller,
                        bcsub(bcsub($product->face_value, $owner, 4), $seller, 4), $same ? 'unchanged' : 'published'];
                }

                return $rows;
            }, 3);
            $this->table(['Product', 'Face value', 'Owner', 'Seller', 'Platform net', 'Result'], $rows);

            return self::SUCCESS;
        } catch (AuthorizationException|ModelNotFoundException|ValidationException) {
            $this->error('Pricing was not published: check actor, network, selected products and the percentage split. No changes were committed.');

            return self::FAILURE;
        }
    }
}
