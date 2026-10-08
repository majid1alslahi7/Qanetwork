<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSellerCommissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::ADMIN;
    }

    public function rules(): array
    {
        return ['seller_id' => ['required', 'ulid', Rule::exists('sellers', 'id')],
            'seller_commission' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,4})?\z/'],
            'provider_amount' => ['prohibited'], 'is_active' => ['prohibited']];
    }
}
