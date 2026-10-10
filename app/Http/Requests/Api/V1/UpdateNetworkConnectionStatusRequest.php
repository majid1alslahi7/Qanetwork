<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNetworkConnectionStatusRequest extends FormRequest
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
        return [
            'is_enabled' => ['required', 'boolean'],
            'is_primary' => ['required', 'boolean'],
            'config' => ['prohibited'], 'credentials' => ['prohibited'],
            'driver' => ['prohibited'], 'health_status' => ['prohibited'],
        ];
    }
}
