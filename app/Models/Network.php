<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Network extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'network_owner_id',
        'code',
        'name',
        'display_name',
        'country_code',
        'city',
        'timezone',
        'currency_code',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'sales_enabled' => 'boolean',
            'last_health_check_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(
            NetworkOwner::class,
            'network_owner_id'
        );
    }

    public function connections(): HasMany
    {
        return $this->hasMany(NetworkConnection::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(NetworkProduct::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
