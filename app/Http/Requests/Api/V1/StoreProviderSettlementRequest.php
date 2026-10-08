<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProviderSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::ADMIN;
    }

    public function rules(): array
    {
        return ['amount' => ['bail', 'required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,4})?\z/',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (bccomp($value, '0', 4) <= 0) {
                    $fail('The settlement amount must be positive.');
                }
            }],
            'currency_code' => ['required', Rule::in(['YER', 'SAR', 'USD'])],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'mobile_wallet', 'other'])],
            'external_reference' => ['required', 'string', 'max:191'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'paid_at' => ['required', 'date_format:Y-m-d\TH:i:sP', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:2000'], 'allocations' => ['prohibited'], 'created_by' => ['prohibited']];
    }
}
