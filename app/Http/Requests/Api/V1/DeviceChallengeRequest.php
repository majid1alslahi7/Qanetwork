<?php

namespace App\Http\Requests\Api\V1;

class DeviceChallengeRequest extends LoginRequest
{
    public function rules(): array
    {
        return parent::rules() + ['public_key' => ['required', 'string', 'max:2048'], 'imei' => ['nullable', 'string', 'regex:/\A[0-9]{15}\z/']];
    }
}
