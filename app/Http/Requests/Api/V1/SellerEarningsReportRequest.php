<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;

class SellerEarningsReportRequest extends AccountingReportRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::SELLER;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return parent::rules() + ['seller_id' => ['prohibited']];
    }
}
