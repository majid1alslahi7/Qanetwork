<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreAccountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::ADMIN;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'max:1024', Password::min(12)->mixedCase()->numbers()->symbols()],
            'role' => ['required', Rule::enum(UserRole::class)],
            'business_name' => ['nullable', 'string', 'max:150'],
            'currency_code' => ['sometimes', Rule::in(['YER', 'SAR', 'USD'])],
            'balance' => ['prohibited'],
            'reserved_balance' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
