<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class StoreManualSaleReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::ADMIN;
    }

    public function rules(): array
    {
        return ['idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'], 'status' => ['prohibited'],
            'credentials' => ['prohibited'], 'provider_amount' => ['prohibited']];
    }
}
