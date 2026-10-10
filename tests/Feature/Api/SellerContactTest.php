<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\SellerContact;
use App\Models\User;
use App\Services\Accounts\CreateAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SellerContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_manages_own_contacts_and_favorites_with_normalized_phone_and_audit(): void
    {
        $user = $this->seller();
        Sanctum::actingAs($user, ['account', 'seller']);
        $body = ['name' => 'Customer', 'phone' => '+967 777-123-456', 'is_favorite' => true];
        $created = $this->postJson('/api/v1/seller/contacts', $body)->assertCreated()->assertJsonPath('data.phone', '+967777123456')->assertJsonPath('data.is_favorite', true);
        $id = $created->json('data.id');
        $this->getJson('/api/v1/seller/contacts?favorite=1&search=Customer')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson('/api/v1/seller/contacts/'.$id, ['name' => 'Edited', 'phone' => '+967777123456', 'is_favorite' => false])->assertOk()->assertJsonPath('data.name', 'Edited');
        $this->getJson('/api/v1/seller/contacts?favorite=1')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson('/api/v1/seller/contacts/'.$id)->assertNoContent();
        $this->assertDatabaseCount('seller_contacts', 0);
        foreach (['contact.created', 'contact.updated', 'contact.deleted'] as $event) {
            $this->assertDatabaseHas('audit_events', ['actor_id' => $user->id, 'subject_id' => $id, 'event_type' => $event]);
        }
    }

    public function test_contacts_are_scoped_to_seller_and_duplicate_phone_validation_does_not_leak_other_sellers(): void
    {
        $own = $this->seller();
        $other = $this->seller();
        $foreign = SellerContact::factory()->for($other->seller)->create(['phone' => '+967777123456']);
        Sanctum::actingAs($own, ['account', 'seller']);
        $this->getJson('/api/v1/seller/contacts')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/seller/contacts/'.$foreign->id)->assertNotFound();
        $this->patchJson('/api/v1/seller/contacts/'.$foreign->id, ['name' => 'Attack', 'phone' => '+967777123456'])->assertNotFound();
        $this->deleteJson('/api/v1/seller/contacts/'.$foreign->id)->assertNotFound();
        $this->postJson('/api/v1/seller/contacts', ['name' => 'Own', 'phone' => '+967777123456'])->assertCreated();
        $this->postJson('/api/v1/seller/contacts', ['name' => 'Duplicate', 'phone' => '+967 777123456'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertSame($other->seller->id, $foreign->fresh()->seller_id);
        $this->assertDatabaseCount('seller_contacts', 2);
    }

    public function test_contacts_require_seller_ability_and_reject_ownership_injection_and_invalid_fields(): void
    {
        $user = $this->seller();
        $this->getJson('/api/v1/seller/contacts')->assertUnauthorized();
        Sanctum::actingAs($user, ['account']);
        $this->getJson('/api/v1/seller/contacts')->assertForbidden();
        Sanctum::actingAs($user, ['account', 'seller']);
        $this->postJson('/api/v1/seller/contacts', ['name' => '', 'phone' => 'wrong', 'seller_id' => 'foreign'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'phone', 'seller_id']);
        $this->assertDatabaseCount('seller_contacts', 0);
    }

    private function seller(): User
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        return app(CreateAccountService::class)->handle($admin, ['name' => 'Seller', 'email' => fake()->unique()->safeEmail(), 'password' => 'Strong-Pass123!', 'role' => 'seller']);
    }
}
