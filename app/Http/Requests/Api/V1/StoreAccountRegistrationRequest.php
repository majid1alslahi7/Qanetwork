<?php

namespace App\Http\Requests\Api\V1;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreAccountRegistrationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
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
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email', 'unique:account_registrations,email'],
            'password' => ['bail', 'required', 'string', 'max:1024', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols(),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (config('hashing.driver') === 'bcrypt' && strlen($value) > 72) {
                        $fail('The password must not exceed 72 bytes.');
                    }
                }],
            'role' => ['required', Rule::in(['seller', 'network_owner'])],
            'business_name' => ['nullable', 'string', 'max:150'],
            'currency_code' => ['required', Rule::in(['YER', 'SAR', 'USD'])],
            'status' => ['prohibited'],
            'balance' => ['prohibited'],
            'reserved_balance' => ['prohibited'],
            'user_id' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
