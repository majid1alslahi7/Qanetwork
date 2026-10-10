<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\NetworkOwner;
use App\Models\PricingRule;
use App\Models\User;
use App\Services\Pricing\ResolveSellerProductPriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublishNetworkPricingCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_malki_split_is_exact_and_repeated_publication_does_not_duplicate_rules(): void
    {
        [$actor, $network] = $this->catalog();
        $expected = [200 => ['170.0000', '14.0000', '16.0000', '186.0000'], 500 => ['425.0000', '35.0000', '40.0000', '465.0000'], 1000 => ['850.0000', '70.0000', '80.0000', '930.0000'], 2000 => ['1700.0000', '140.0000', '160.0000', '1860.0000'], 5000 => ['4250.0000', '350.0000', '400.0000', '4650.0000']];
        foreach (array_keys($expected) as $price) {
            $network->products()->create(['code' => 'PRD-'.Str::ulid(), 'name' => 'MALKI-'.$price, 'external_product_id' => 'MALKI-'.$price, 'face_value' => (string) $price, 'currency_code' => 'YER']);
        }
        $options = ['network' => $network->id, '--actor' => $actor->email, '--owner-percent' => '85', '--seller-percent' => '7', '--product' => array_map(fn ($price) => 'MALKI-'.$price, array_keys($expected))];
        $this->artisan('qanetwork:publish-pricing', $options)->assertSuccessful();
        foreach ($network->products()->get() as $product) {
            $price = app(ResolveSellerProductPriceService::class)->handle($product, 'any-seller');
            $values = $expected[(int) $product->face_value];
            $this->assertSame($values, [$price['provider_amount'], $price['seller_commission'], $price['platform_commission'], $price['seller_net_amount']]);
        }
        $this->artisan('qanetwork:publish-pricing', $options)->assertSuccessful();
        $this->assertDatabaseCount('pricing_rules', 5);
        $this->assertDatabaseCount('commission_rules', 5);
        $this->assertDatabaseCount('audit_events', 5);
        $this->assertSame(5, PricingRule::query()->where('is_active', true)->count());
        $this->assertDatabaseCount('seller_ledger_entries', 0);
        $this->assertDatabaseCount('sale_accounting_entries', 0);
    }

    public function test_invalid_percentages_missing_products_and_unauthorized_actor_leave_no_rules(): void
    {
        [$actor, $network] = $this->catalog();
        $options = ['network' => $network->code, '--actor' => $actor->email, '--owner-percent' => '85', '--seller-percent' => '7', '--product' => ['missing']];
        $this->artisan('qanetwork:publish-pricing', $options)->assertFailed();
        foreach (['16', '-7', 'seven', '7.00001'] as $percent) {
            $this->artisan('qanetwork:publish-pricing', array_replace($options, ['--seller-percent' => $percent]))->assertFailed();
        }
        $actor->role = UserRole::SELLER;
        $actor->save();
        $this->artisan('qanetwork:publish-pricing', $options)->assertFailed();
        $this->assertDatabaseCount('pricing_rules', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    private function catalog(): array
    {
        $actor = User::factory()->create(['role' => UserRole::ADMIN]);
        $owner = new NetworkOwner(['code' => 'OWN-'.Str::ulid(), 'name' => 'Owner']);
        $owner->status = 'active';
        $owner->save();
        $network = $owner->networks()->create(['code' => 'NET-'.Str::ulid(), 'name' => 'Malki', 'currency_code' => 'YER']);

        return [$actor, $network];
    }
}
