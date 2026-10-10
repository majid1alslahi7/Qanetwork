<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Seller;
use App\Models\SellerContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SellerContact> */
class SellerContactFactory extends Factory
{
    public function definition(): array
    {
        return ['seller_id' => fn () => Seller::query()->create(['user_id' => User::factory()->create(['role' => UserRole::SELLER])->id, 'code' => 'SEL-'.Str::ulid()])->id,
            'name' => fake()->name(), 'phone' => fake()->unique()->numerify('9677########'), 'email' => null, 'is_favorite' => false];
    }
}
