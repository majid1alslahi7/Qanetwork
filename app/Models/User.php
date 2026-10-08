<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $attributes = ['role' => 'seller', 'status' => 'active'];

    public function seller(): HasOne
    {
        return $this->hasOne(Seller::class);
    }

    public function networkOwner(): HasOne
    {
        return $this->hasOne(NetworkOwner::class);
    }

    public function canAccessApplication(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        return match ($this->role) {
            UserRole::ADMIN => true,
            UserRole::SELLER => $this->seller()->where('status', 'active')->exists(),
            UserRole::NETWORK_OWNER => $this->networkOwner()->where('status', 'active')->exists(),
        };
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }
}
