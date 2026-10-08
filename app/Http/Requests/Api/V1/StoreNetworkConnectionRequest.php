<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNetworkConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::ADMIN;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'driver' => ['required', Rule::in(['mikrotik_hotspot', 'mikrotik_user_manager'])],
            'config' => ['required', 'array:host,port,tls'],
            'config.host' => ['required', 'string', 'max:253'],
            'config.port' => ['sometimes', 'integer', 'between:1,65535'],
            'config.tls' => ['sometimes', 'boolean'],
            'credentials' => ['required', 'array:username,password'],
            'credentials.username' => ['required', 'string', 'max:255'],
            'credentials.password' => ['required', 'string', 'max:1024'],
            'connect_timeout' => ['sometimes', 'integer', 'between:1,60'],
            'request_timeout' => ['sometimes', 'integer', 'between:1,120'],
            'is_enabled' => ['prohibited'],
            'is_primary' => ['prohibited'],
            'health_status' => ['prohibited'],
            'base_url' => ['prohibited'],
        ];
    }
}
