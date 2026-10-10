<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNetworkConnectionRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'driver' => ['required', Rule::in(['mikrotik_hotspot', 'mikrotik_user_manager', 'stored_cards'])],
            'config' => ['required_unless:driver,stored_cards', 'array:host,port,tls', 'prohibited_if:driver,stored_cards'],
            'config.host' => ['required_unless:driver,stored_cards', 'string', 'max:253'],
            'config.port' => ['sometimes', 'integer', 'between:1,65535'],
            'config.tls' => ['sometimes', 'boolean'],
            'credentials' => ['required_unless:driver,stored_cards', 'array:username,password', 'prohibited_if:driver,stored_cards'],
            'credentials.username' => ['required_unless:driver,stored_cards', 'string', 'max:255'],
            'credentials.password' => ['required_unless:driver,stored_cards', 'string', 'max:1024'],
            'connect_timeout' => ['sometimes', 'integer', 'between:1,60'],
            'request_timeout' => ['sometimes', 'integer', 'between:1,120'],
            'is_enabled' => ['prohibited'],
            'is_primary' => ['prohibited'],
            'health_status' => ['prohibited'],
            'base_url' => ['prohibited'],
        ];
    }
}
