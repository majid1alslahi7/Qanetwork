<?php

namespace Database\Seeders;

use App\Models\Seller;
use App\Models\SellerContact;
use Illuminate\Database\Seeder;

class SellerContactSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        $seller = Seller::query()->first();
        if ($seller !== null && ! SellerContact::query()->where('seller_id', $seller->id)->exists()) {
            SellerContact::factory()->count(3)->for($seller)->create();
        }
    }
}
