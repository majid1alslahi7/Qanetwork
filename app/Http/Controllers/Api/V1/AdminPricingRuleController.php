<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePricingRuleRequest;
use App\Http\Resources\Api\V1\PricingRuleResource;
use App\Models\Network;
use App\Models\NetworkProduct;
use App\Models\PricingRule;
use App\Services\Pricing\PublishPricingRuleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminPricingRuleController extends Controller
{
    public function index(Request $request, Network $network, NetworkProduct $product): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return PricingRuleResource::collection(PricingRule::query()->where('network_product_id', $product->id)
            ->with('commissions.seller:id,business_name')->orderByDesc('id')->paginate(25));
    }

    public function store(StorePricingRuleRequest $request, Network $network, NetworkProduct $product, PublishPricingRuleService $service): PricingRuleResource
    {
        return new PricingRuleResource($service->handle($request->user(), $product,
            $request->validated('provider_amount'), $request->validated('seller_commission')));
    }
}
