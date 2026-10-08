<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AccountingReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, [UserRole::ADMIN, UserRole::NETWORK_OWNER], true);
    }

    public function rules(): array
    {
        return ['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'], 'network_owner_id' => ['prohibited']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->fromDate()->diffInDays($this->toDate()) > 30) {
                $validator->errors()->add('to', 'A report can cover at most 31 calendar days.');
            }
        }];
    }

    public function fromDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->input('from'), 'UTC');
    }

    public function toDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->input('to'), 'UTC');
    }
}
