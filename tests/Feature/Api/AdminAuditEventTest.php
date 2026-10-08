<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAuditEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_audit_events_and_receives_401(): void
    {
        $this->getJson('/api/v1/admin/audit-events')->assertUnauthorized();
    }

    public function test_admin_without_admin_ability_receives_403(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN, 'status' => 'active']);

        $this->withToken($admin->createToken('limited', ['account'])->plainTextToken)
            ->getJson('/api/v1/admin/audit-events')->assertForbidden();
    }

    #[DataProvider('otherRoles')]
    public function test_non_admin_with_forged_admin_ability_receives_403(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active']);

        $this->withToken($user->createToken('forged', ['account', 'admin'])->plainTextToken)
            ->getJson('/api/v1/admin/audit-events')->assertForbidden();
    }

    public static function otherRoles(): array
    {
        return ['seller' => [UserRole::SELLER], 'owner' => [UserRole::NETWORK_OWNER]];
    }

    public function test_admin_reads_newest_events_with_safe_changes_and_bounded_pages(): void
    {
        $this->freezeTime();
        $admin = $this->authenticateAdmin();
        for ($index = 1; $index <= 26; $index++) {
            $this->event($admin, ['subject_id' => 'subject-'.$index]);
        }
        $last = $this->event($admin, [
            'before' => ['status' => 'active', 'password' => 'never-expose-before'],
            'after' => ['status' => 'suspended', 'credentials' => ['password' => 'router-secret'],
                'config' => ['host' => 'internal-host'], 'recipient' => '+967771234567',
                'sale_id' => ['password' => 'nested-secret']],
        ]);

        $response = $this->getJson('/api/v1/admin/audit-events');

        $response->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.id', (string) $last->id)
            ->assertJsonPath('data.0.actor', ['id' => (string) $admin->id, 'name' => 'Audit administrator'])
            ->assertJsonPath('data.0.before', ['status' => 'active'])
            ->assertJsonPath('data.0.after', ['status' => 'suspended']);
        $this->assertSame(['id', 'event_type', 'subject_type', 'subject_id', 'actor', 'before', 'after', 'created_at'], array_keys($response->json('data.0')));
        foreach (['never-expose-before', 'router-secret', 'internal-host', '+967771234567', 'nested-secret', $admin->email] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->getJson('/api/v1/admin/audit-events?page=2')->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseCount('audit_events', 27);
    }

    public function test_filters_combine_exactly_and_subject_input_is_not_executed_as_sql(): void
    {
        $admin = $this->authenticateAdmin();
        $other = User::factory()->create(['role' => UserRole::ADMIN]);
        $target = $this->event($admin, ['subject_id' => 'target']);
        $this->event($admin, ['subject_id' => 'another']);
        $this->event($other, ['subject_id' => 'target']);
        $this->event($admin, ['subject_id' => 'target', 'event_type' => 'account.created']);
        $this->event($admin, ['subject_id' => 'target', 'subject_type' => 'network']);
        $query = http_build_query(['event_type' => 'account.status_changed', 'subject_type' => 'user',
            'subject_id' => 'target', 'actor_id' => $admin->id]);

        $this->getJson('/api/v1/admin/audit-events?'.$query)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $target->id);
        $this->getJson('/api/v1/admin/audit-events?'.http_build_query(['subject_id' => "' OR 1=1 --"]))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filter_receives_422(string $field, mixed $value): void
    {
        $this->authenticateAdmin();

        $this->getJson('/api/v1/admin/audit-events?'.http_build_query([$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidFilters(): array
    {
        return ['event-array' => ['event_type', ['account.created']],
            'event-too-long' => ['event_type', str_repeat('a', 81)],
            'event-invalid' => ['event_type', 'account%'],
            'subject-array' => ['subject_type', ['user']],
            'subject-too-long' => ['subject_type', str_repeat('a', 81)],
            'subject-invalid' => ['subject_type', 'user.name'],
            'reference-array' => ['subject_id', ['one']],
            'reference-too-long' => ['subject_id', str_repeat('x', 101)],
            'actor-zero' => ['actor_id', 0], 'actor-text' => ['actor_id', 'admin'],
            'page-zero' => ['page', 0], 'page-fraction' => ['page', '1.5']];
    }

    public function test_audit_route_does_not_allow_writes(): void
    {
        $this->authenticateAdmin();

        $this->postJson('/api/v1/admin/audit-events', ['event_type' => 'forged'])->assertMethodNotAllowed();
        $this->assertDatabaseCount('audit_events', 0);
    }

    private function authenticateAdmin(): User
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN, 'status' => 'active', 'name' => 'Audit administrator']);
        $this->withToken($admin->createToken('admin', ['account', 'admin'])->plainTextToken);

        return $admin;
    }

    /** @param array<string, mixed> $attributes */
    private function event(User $actor, array $attributes = []): AuditEvent
    {
        return AuditEvent::query()->create([...[
            'actor_id' => $actor->id, 'event_type' => 'account.status_changed',
            'subject_type' => 'user', 'subject_id' => 'account-1',
            'before' => ['status' => 'active'], 'after' => ['status' => 'suspended'],
        ], ...$attributes]);
    }
}
