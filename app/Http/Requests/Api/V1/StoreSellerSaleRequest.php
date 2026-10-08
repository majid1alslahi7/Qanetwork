<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class StoreSellerSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::SELLER;
    }

    public function rules(): array
    {
        return ['product_id' => ['required', 'ulid'], 'wallet_id' => ['required', 'ulid'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'expected_pricing_rule_id' => ['required_with:expected_commission_rule_id', 'ulid'],
            'expected_commission_rule_id' => ['required_with:expected_pricing_rule_id', 'ulid'],
            'seller_id' => ['prohibited'], 'currency_code' => ['prohibited'], 'face_value' => ['prohibited'],
            'seller_commission' => ['prohibited'], 'provider_amount' => ['prohibited'],
            'delivery_method' => ['prohibited'], 'customer_phone' => ['prohibited']];
    }
}
