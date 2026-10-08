<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreNetworkProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::ADMIN;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'display_name' => ['nullable', 'string', 'max:150'],
            'external_product_id' => ['required', 'string', 'max:191', 'regex:/\A[^\x00-\x1F\x7F]+\z/u',
                Rule::unique('network_products')->where('network_id', $this->route('network')->id)],
            'face_value' => ['bail', 'required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,4})?\z/',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (bccomp($value, '0', 4) <= 0) {
                        $fail('The face value must be positive.');
                    }
                }],
            'data_limit_bytes' => ['nullable', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'duration_minutes' => ['nullable', 'integer', 'between:1,4294967295'],
            'metadata' => ['sometimes', 'array:hotspot'],
            'metadata.hotspot' => ['sometimes', 'array:limit_bytes_total,limit_uptime_seconds,allow_unlimited,server'],
            'metadata.hotspot.limit_bytes_total' => ['sometimes', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'metadata.hotspot.limit_uptime_seconds' => ['sometimes', 'integer', 'between:1,315360000'],
            'metadata.hotspot.allow_unlimited' => ['sometimes', 'boolean'],
            'metadata.hotspot.server' => ['sometimes', 'string', 'max:191', 'regex:/\A[^\x00-\x1F\x7F]+\z/u'],
            'currency_code' => ['prohibited'], 'network_id' => ['prohibited'], 'code' => ['prohibited'],
            'status' => ['prohibited'], 'availability_status' => ['prohibited'], 'available_quantity' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->has('metadata.hotspot')) {
                return;
            }
            if (! $this->has('metadata.hotspot.limit_bytes_total') && ! $this->has('metadata.hotspot.limit_uptime_seconds')
                && ! $this->boolean('metadata.hotspot.allow_unlimited')) {
                $validator->errors()->add('metadata.hotspot', 'Specify a service limit or explicitly allow unlimited service.');
            }
        }];
    }
}
