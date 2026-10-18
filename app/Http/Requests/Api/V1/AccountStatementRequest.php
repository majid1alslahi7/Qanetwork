<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;

class AccountStatementRequest extends AccountingReportRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return in_array($this->user()?->role, [UserRole::ADMIN, UserRole::SELLER, UserRole::NETWORK_OWNER], true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return parent::rules() + ['as_of' => ['sometimes', 'date', 'before_or_equal:now'], 'seller_id' => ['prohibited'], 'user_id' => ['prohibited']];
    }
}
