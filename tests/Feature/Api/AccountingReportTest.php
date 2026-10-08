<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Network;
use App\Models\NetworkOwner;
use App\Models\Sale;
use App\Models\SaleAccountingEntry;
use App\Models\Seller;
use App\Models\SellerWallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountingReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_summary_keeps_currencies_separate_and_includes_whole_date_range(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]), ['account', 'admin']);
        $owner = $this->owner();
        $this->entry($owner, 'provider_payable', '0.1000', 'YER', '2026-10-01 00:00:00');
        $this->entry($owner, 'provider_payable', '0.2000', 'YER', '2026-10-02 23:59:59');
        $this->entry($owner, 'platform_revenue', '0.0500', 'YER', '2026-10-02 12:00:00');
        $this->entry($owner, 'provider_payable', '1.2500', 'SAR', '2026-10-02 12:00:00');
        $this->entry($owner, 'provider_payable', '99', 'YER', '2026-10-03 00:00:00');
        $response = $this->getJson('/api/v1/admin/accounting/summary'.$this->period())->assertOk();
        $response->assertJsonPath('data.period.timezone', 'UTC')->assertJsonPath('data.currencies.0.currency_code', 'SAR')
            ->assertJsonPath('data.currencies.0.provider_payable', '1.2500')->assertJsonPath('data.currencies.1.currency_code', 'YER')
            ->assertJsonPath('data.currencies.1.provider_payable', '0.3000')->assertJsonPath('data.currencies.1.platform_revenue', '0.0500')
            ->assertJsonPath('data.currencies.1.entry_count', 3);
        $this->getJson('/api/v1/admin/accounting/entries'.$this->period())->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('meta.per_page', 25);
    }

    public function test_owner_only_sees_own_provider_entries_and_foreign_or_platform_entry_returns_not_found(): void
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner->user()->firstOrFail(), ['account', 'network_owner']);
        $own = $this->entry($owner, 'provider_payable', '800', 'YER');
        $foreign = $this->entry($this->owner(), 'provider_payable', '500', 'YER');
        $platform = $this->entry($owner, 'platform_revenue', '50', 'YER');
        $response = $this->getJson('/api/v1/owner/accounting/summary'.$this->period())->assertOk()
            ->assertJsonPath('data.currencies.0.provider_payable', '800.0000')->assertJsonPath('data.currencies.0.entry_count', 1);
        $this->assertArrayNotHasKey('platform_revenue', $response->json('data.currencies.0'));
        $this->getJson('/api/v1/owner/accounting/entries'.$this->period())->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/owner/accounting/entries/'.$own->id)->assertOk()->assertJsonPath('data.amount', '800.0000');
        $this->getJson('/api/v1/owner/accounting/entries/'.$foreign->id)->assertNotFound();
        $this->getJson('/api/v1/owner/accounting/entries/'.$platform->id)->assertNotFound();
        $this->getJson('/api/v1/admin/accounting/entries/'.$own->id)->assertForbidden();
    }

    public function test_owner_parameter_cannot_override_report_scope(): void
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner->user()->firstOrFail(), ['account', 'network_owner']);
        $this->getJson('/api/v1/owner/accounting/summary'.$this->period().'&network_owner_id='.$this->owner()->id)
            ->assertUnprocessable()->assertJsonValidationErrors('network_owner_id');
    }

    public function test_report_rejects_missing_dates_invalid_dates_and_excessive_or_reversed_range(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]), ['account', 'admin']);
        $url = '/api/v1/admin/accounting/summary';
        $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->getJson($url.'?from=2026-10-02&to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson($url.'?from=2026-02-30&to=2026-03-01')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson($url.'?from=2026-10-01&to=2026-11-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson($url.'?from=2026-10-01&to=2026-10-31')->assertOk()->assertJsonPath('data.currencies', []);
    }

    public function test_owner_token_requires_owner_ability_and_active_account(): void
    {
        $owner = $this->owner();
        $user = $owner->user()->firstOrFail();
        Sanctum::actingAs($user, ['account']);
        $this->getJson('/api/v1/owner/accounting/summary'.$this->period())->assertForbidden();
        $user->status = 'suspended';
        $user->save();
        Sanctum::actingAs($user, ['account', 'network_owner']);
        $this->getJson('/api/v1/owner/accounting/summary'.$this->period())->assertForbidden();
    }

    public function test_unauthenticated_report_request_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/accounting/summary'.$this->period())->assertUnauthorized();
        $this->getJson('/api/v1/owner/accounting/summary'.$this->period())->assertUnauthorized();
    }

    private function owner(): NetworkOwner
    {
        $user = User::factory()->create(['role' => UserRole::NETWORK_OWNER]);
        $owner = new NetworkOwner(['code' => 'OWN-'.Str::ulid(), 'name' => 'Owner']);
        $owner->user_id = $user->id;
        $owner->status = 'active';
        $owner->save();

        return $owner;
    }

    private function entry(NetworkOwner $owner, string $type, string $amount, string $currency, string $postedAt = '2026-10-02 12:00:00'): SaleAccountingEntry
    {
        $seller = Seller::query()->create(['user_id' => User::factory()->create()->id, 'code' => 'SEL-'.Str::ulid()]);
        $wallet = SellerWallet::query()->create(['seller_id' => $seller->id, 'currency_code' => $currency]);
        $network = Network::query()->create(['network_owner_id' => $owner->id, 'code' => 'NET-'.Str::ulid(), 'name' => 'Network']);
        $product = $network->products()->create(['code' => 'PRD-'.Str::ulid(), 'external_product_id' => 'day', 'name' => 'Day', 'face_value' => '1000', 'currency_code' => $currency]);
        $sale = Sale::query()->create(['seller_id' => $seller->id, 'seller_wallet_id' => $wallet->id, 'network_id' => $network->id,
            'network_product_id' => $product->id, 'reference_no' => 'SALE-'.Str::ulid(), 'idempotency_key' => (string) Str::ulid(), 'currency_code' => $currency]);

        return SaleAccountingEntry::query()->create(['sale_id' => $sale->id, 'network_owner_id' => $type === 'provider_payable' ? $owner->id : null,
            'entry_type' => $type, 'amount' => $amount, 'currency_code' => $currency,
            'idempotency_key' => 'accounting:'.$sale->id, 'posted_at' => $postedAt]);
    }

    private function period(): string
    {
        return '?from=2026-10-01&to=2026-10-02';
    }
}
