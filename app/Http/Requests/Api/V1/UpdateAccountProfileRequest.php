<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateAccountProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::ADMIN;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'business_name' => ['nullable', 'string', 'max:150'], 'phone' => ['nullable', 'string', 'max:50'], 'city' => ['nullable', 'string', 'max:100'], 'address' => ['nullable', 'string', 'max:500'],
            'password' => ['sometimes', 'required', 'string', 'max:1024', Password::min(12)->mixedCase()->numbers()->symbols()],
            'role' => ['prohibited'], 'status' => ['prohibited'], 'balance' => ['prohibited'], 'reserved_balance' => ['prohibited'], 'seller_id' => ['prohibited'], 'network_owner_id' => ['prohibited']];
    }
}
