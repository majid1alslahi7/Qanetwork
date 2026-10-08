<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCardDeliveryRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['recipient' => ['required', 'string', 'regex:/\A\+[1-9][0-9]{7,14}\z/'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'status' => ['prohibited'], 'credentials' => ['prohibited'], 'message' => ['prohibited'],
            'channel' => ['prohibited'], 'provider_reference' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['recipient.regex' => 'Use an international phone number beginning with +.'];
    }
}
