<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class AuthenticateAccountService
{
    public function handle(string $email, #[SensitiveParameter] string $password): User
    {
        $user = User::query()->where('email', $email)->first();
        $hash = $user?->password ?? '$2y$12$UzIBP7X2z4SXBA1h7Irq/O1UWhCcNnMsmG7HZsjZXKrTctGeY1NdS';
        $validPassword = Hash::check($password, $hash);
        if (! $validPassword || ! $user || ! $user->canAccessApplication()) {
            throw ValidationException::withMessages(['email' => 'The provided credentials are invalid.']);
        }

        return $user;
    }
}
