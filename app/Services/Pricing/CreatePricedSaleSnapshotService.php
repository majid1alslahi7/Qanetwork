<?php

namespace App\Services\Pricing;

use App\Models\NetworkProduct;
use App\Models\Sale;
use App\Models\SaleFinancial;
use App\Services\Sales\SaleFinancialSnapshotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePricedSaleSnapshotService
{
    public function __construct(private readonly SaleFinancialSnapshotService $snapshots, private readonly ResolveSellerProductPriceService $prices) {}

    public function handle(Sale $sale): SaleFinancial
    {
        return DB::transaction(function () use ($sale): SaleFinancial {
            $current = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            $existing = $current->financial()->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($current->status !== 'created') {
                throw ValidationException::withMessages(['sale' => 'Pricing must be fixed before processing the sale.']);
            }
            $product = NetworkProduct::query()->lockForUpdate()->findOrFail($current->network_product_id);
            if ($product->network_id !== $current->network_id || $product->currency_code !== $current->currency_code) {
                throw ValidationException::withMessages(['product' => 'The sale product or currency does not match.']);
            }
            $price = $this->prices->handle($product, $current->seller_id);

            return $this->snapshots->create($current, $price['face_value'], $price['provider_amount'],
                $price['seller_commission'], $price['platform_commission'], $price['pricing_rule_id'], $price['commission_rule_id']);
        }, 3);
    }
}
