<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSellerCommissionRequest;
use App\Http\Resources\Api\V1\CommissionRuleResource;
use App\Models\CommissionRule;
use App\Models\Network;
use App\Models\NetworkProduct;
use App\Models\PricingRule;
use App\Models\Seller;
use App\Services\Pricing\ManageSellerCommissionService;
use Illuminate\Http\Request;

class AdminSellerCommissionController extends Controller
{
    public function store(StoreSellerCommissionRequest $request, Network $network, NetworkProduct $product, PricingRule $pricingRule, ManageSellerCommissionService $service): CommissionRuleResource
    {
        return new CommissionRuleResource($service->publish($request->user(), $pricingRule,
            Seller::query()->findOrFail($request->validated('seller_id')), $request->validated('seller_commission')));
    }

    public function destroy(Request $request, Network $network, NetworkProduct $product, PricingRule $pricingRule, CommissionRule $commission, ManageSellerCommissionService $service): CommissionRuleResource
    {
        return new CommissionRuleResource($service->retire($request->user(), $pricingRule, $commission));
    }
}
