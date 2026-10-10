<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNetworkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, [UserRole::ADMIN, UserRole::NETWORK_OWNER], true);
    }

    public function rules(): array
    {
        return [
            'network_owner_id' => $this->user()?->role === UserRole::NETWORK_OWNER ? ['prohibited'] : ['required', 'ulid', Rule::exists('network_owners', 'id')],
            'name' => ['required', 'string', 'max:150'],
            'display_name' => ['nullable', 'string', 'max:150'],
            'country_code' => ['sometimes', 'string', 'regex:/^[A-Z]{2}$/'],
            'city' => ['nullable', 'string', 'max:100'],
            'timezone' => ['sometimes', 'timezone'],
            'currency_code' => ['required', Rule::in(['YER', 'SAR', 'USD'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'code' => ['prohibited'],
            'status' => ['prohibited'],
            'sales_enabled' => ['prohibited'],
            'health_status' => ['prohibited'],
        ];
    }
}
