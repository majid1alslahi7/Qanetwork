<?php

namespace App\Models;

use Database\Factories\AccountRegistrationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountRegistration extends Model
{
    /** @use HasFactory<AccountRegistrationFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['name', 'email', 'password', 'role', 'business_name', 'currency_code'];

    protected $hidden = ['password'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'reviewed_at' => 'datetime'];
    }
}
