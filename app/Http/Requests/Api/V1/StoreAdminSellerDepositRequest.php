<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;

class StoreAdminSellerDepositRequest extends StoreSellerDepositRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::ADMIN;
    }

    public function rules(): array
    {
        return array_replace(parent::rules(), ['seller_notes' => ['prohibited'], 'review_notes' => ['required', 'string', 'max:2000']]);
    }
}
