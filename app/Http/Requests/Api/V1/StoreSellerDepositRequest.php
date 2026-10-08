<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSellerDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::SELLER;
    }

    public function rules(): array
    {
        return ['wallet_id' => ['required', 'ulid'],
            'amount' => ['bail', 'required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,4})?\z/',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (bccomp($value, '0', 4) <= 0) {
                        $fail('The deposit amount must be positive.');
                    }
                }],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'mobile_wallet', 'other'])],
            'external_reference' => ['nullable', 'required_if:payment_method,bank_transfer,mobile_wallet', 'string', 'max:191'],
            'seller_notes' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'currency_code' => ['prohibited'], 'seller_id' => ['prohibited'], 'status' => ['prohibited'],
            'receipt_path' => ['prohibited'], 'reviewed_by' => ['prohibited']];
    }
}
