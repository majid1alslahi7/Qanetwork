<?php

namespace Database\Factories;

use App\Models\AccountRegistration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountRegistration>
 */
class AccountRegistrationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'Secure-Registration12!',
            'role' => 'seller',
            'currency_code' => 'YER',
        ];
    }
}
