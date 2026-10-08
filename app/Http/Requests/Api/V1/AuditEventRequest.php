<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AuditEventRequest extends FormRequest
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
            'event_type' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_.]*$/'],
            'subject_type' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/'],
            'subject_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'actor_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
