<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNetworkStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::ADMIN;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
            'sales_enabled' => ['prohibited'],
            'health_status' => ['prohibited'],
            'network_owner_id' => ['prohibited'],
            'currency_code' => ['prohibited'],
        ];
    }
}
