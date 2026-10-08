<?php

namespace Tests\Feature\Console;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_hidden_password_creates_active_admin_with_hashed_password(): void
    {
        $password = 'Long-admin-pass!123';

        $this->artisan('users:create-admin', ['email' => 'admin@example.test', '--name' => 'Administrator'])
            ->expectsQuestion('Administrator password', $password)
            ->expectsQuestion('Confirm administrator password', $password)
            ->expectsOutput('Administrator created: admin@example.test')
            ->doesntExpectOutputToContain($password)->assertSuccessful();

        $user = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $this->assertSame(UserRole::ADMIN, $user->role);
        $this->assertSame('active', $user->status);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertNotSame($password, $user->password);
        $this->assertTrue($user->canAccessApplication());
    }

    public function test_existing_account_cannot_be_promoted_or_overwritten(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.test']);
        $originalPassword = $user->password;

        $this->artisan('users:create-admin', ['email' => $user->email])
            ->expectsOutput('This email already belongs to an account; no changes were made.')->assertFailed();

        $this->assertSame(UserRole::SELLER, $user->fresh()->role);
        $this->assertSame($originalPassword, $user->fresh()->password);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_password_confirmation_mismatch_does_not_create_account(): void
    {
        $this->artisan('users:create-admin', ['email' => 'admin@example.test', '--name' => 'Administrator'])
            ->expectsQuestion('Administrator password', 'Long-admin-pass!123')
            ->expectsQuestion('Confirm administrator password', 'Different-admin-pass!123')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_noninteractive_invocation_fails_without_changes(): void
    {
        $this->artisan('users:create-admin', ['email' => 'admin@example.test', '--no-interaction' => true])
            ->expectsOutput('Run this command interactively to enter the password securely.')->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
