<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSellerContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::SELLER;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => preg_replace('/[ ()-]/', '', $this->input('phone'))]);
        }
    }

    public function rules(): array
    {
        $seller = $this->user()->seller()->firstOrFail();
        $contact = $this->route('contact') === null ? null : $seller->contacts()->findOrFail($this->route('contact'));
        $presence = $this->isMethod('post') ? 'required' : 'sometimes';

        return ['name' => [$presence, 'filled', 'string', 'max:150'],
            'phone' => [$presence, 'filled', 'string', 'regex:/\A\+?[0-9]{6,20}\z/', Rule::unique('seller_contacts', 'phone')->where('seller_id', $seller->id)->ignore($contact)],
            'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string', 'max:500'], 'notes' => ['nullable', 'string', 'max:2000'],
            'is_favorite' => ['sometimes', 'boolean'], 'seller_id' => ['prohibited'], 'user_id' => ['prohibited']];
    }
}
