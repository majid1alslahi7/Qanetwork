<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\AccountDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AccountDevice> */
class AccountDeviceFactory extends Factory
{
    public function definition(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $publicKey = openssl_pkey_get_details($key)['key'];

        return ['user_id' => User::factory()->create(['role' => UserRole::ADMIN])->id, 'public_key' => $publicKey, 'fingerprint' => hash('sha256', $publicKey),
            'imei' => '490154203237518', 'device_name' => 'Test phone', 'status' => 'pending'];
    }
}
