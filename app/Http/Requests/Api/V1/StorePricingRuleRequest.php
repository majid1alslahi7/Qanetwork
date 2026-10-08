<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class StorePricingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::ADMIN;
    }

    public function rules(): array
    {
        $money = ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,4})?\z/'];

        return ['provider_amount' => $money, 'seller_commission' => $money,
            'face_value' => ['prohibited'], 'platform_commission' => ['prohibited'], 'currency_code' => ['prohibited']];
    }
}
