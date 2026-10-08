<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SellerCatalogNetworkResource;
use App\Http\Resources\Api\V1\SellerCatalogProductResource;
use App\Models\NetworkProduct;
use App\Services\Networks\SaleableNetworkService;
use App\Services\Pricing\ResolveSellerProductPriceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class SellerCatalogController extends Controller
{
    public function __construct(private readonly SaleableNetworkService $networks, private readonly ResolveSellerProductPriceService $prices) {}

    public function networks(Request $request): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return SellerCatalogNetworkResource::collection($this->networks->query()
            ->whereHas('products', fn (Builder $query) => $query->where('status', 'active'))
            ->orderBy('name')->orderBy('id')->paginate(25));
    }

    public function products(Request $request, string $network): AnonymousResourceCollection
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $this->networks->query()->findOrFail($network);
        $sellerId = $request->user()->seller()->firstOrFail()->id;
        $products = $this->productsQuery($network, $sellerId)->orderBy('face_value')->orderBy('id')->paginate(25);
        $products->getCollection()->each(fn (NetworkProduct $product) => $this->attachOffer($product, $sellerId));

        return SellerCatalogProductResource::collection($products);
    }

    public function product(Request $request, string $network, string $product): SellerCatalogProductResource
    {
        $this->networks->query()->findOrFail($network);
        $sellerId = $request->user()->seller()->firstOrFail()->id;
        $product = $this->productsQuery($network, $sellerId)->findOrFail($product);
        $this->attachOffer($product, $sellerId);

        return new SellerCatalogProductResource($product);
    }

    /** @return Builder<NetworkProduct> */
    private function productsQuery(string $networkId, string $sellerId): Builder
    {
        return NetworkProduct::query()->where('network_id', $networkId)->where('status', 'active')
            ->select(['id', 'network_id', 'name', 'display_name', 'face_value', 'currency_code', 'data_limit_bytes', 'duration_minutes'])
            ->with(['pricingRules' => fn ($query) => $query->where('is_active', true),
                'pricingRules.commissions' => fn ($query) => $query->where('is_active', true)->where(function ($query) use ($sellerId): void {
                    $query->where('seller_id', $sellerId)->orWhereNull('seller_id');
                })]);
    }

    private function attachOffer(NetworkProduct $product, string $sellerId): void
    {
        try {
            $product->setAttribute('seller_offer', $this->prices->handle($product, $sellerId));
        } catch (ValidationException) {
            $product->setAttribute('seller_offer', null);
        }
    }
}
