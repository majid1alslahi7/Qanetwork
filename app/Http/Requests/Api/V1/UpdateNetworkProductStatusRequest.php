<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNetworkProductStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user()?->role === UserRole::NETWORK_OWNER) {
            abort_unless($this->route('network')->network_owner_id === $this->user()->networkOwner()->firstOrFail()->id, 404);

            return true;
        }

        return $this->user()?->role === UserRole::ADMIN;
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::in(['active', 'inactive', 'archived'])],
            'face_value' => ['prohibited'], 'metadata' => ['prohibited'], 'external_product_id' => ['prohibited'],
            'currency_code' => ['prohibited'], 'network_id' => ['prohibited']];
    }
}
