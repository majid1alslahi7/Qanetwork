<?php

namespace Database\Factories;

use App\Models\InventoryCard;
use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\NetworkProduct;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InventoryCard>
 */
class InventoryCardFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $username = fake()->unique()->numerify('00############');

        return [
            'network_id' => function (): string {
                $owner = NetworkOwner::query()->create(['code' => 'OWN-'.Str::ulid(), 'name' => fake()->company()]);

                return Network::query()->create(['network_owner_id' => $owner->id, 'code' => 'NET-'.Str::ulid(), 'name' => fake()->company()])->id;
            },
            'network_product_id' => fn (array $attributes): string => NetworkProduct::query()->create([
                'network_id' => $attributes['network_id'], 'code' => 'PRD-'.Str::ulid(), 'external_product_id' => 'stock-'.Str::ulid(),
                'name' => 'Inventory test product', 'face_value' => '100.0000', 'currency_code' => 'YER',
            ])->id,
            'fingerprint' => hash_hmac('sha256', $username, (string) config('app.key')),
            'credentials_encrypted' => ['username' => $username, 'login_mode' => 'username_only'],
        ];
    }
}
