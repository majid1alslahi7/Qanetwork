<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNetworkConnectionStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::ADMIN;
    }

    public function rules(): array
    {
        return [
            'is_enabled' => ['required', 'boolean'],
            'is_primary' => ['required', 'boolean'],
            'config' => ['prohibited'], 'credentials' => ['prohibited'],
            'driver' => ['prohibited'], 'health_status' => ['prohibited'],
        ];
    }
}
