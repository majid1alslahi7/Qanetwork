<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkProduct extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'network_id',
        'external_product_id',
        'fulfillment_connection_id',
        'code',
        'name',
        'display_name',
        'face_value',
        'currency_code',
        'data_limit_bytes',
        'duration_minutes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'face_value' => 'decimal:4',
            'data_limit_bytes' => 'integer',
            'duration_minutes' => 'integer',
            'metadata' => 'array',
            'available_quantity' => 'integer',
            'availability_checked_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    public function fulfillmentConnection(): BelongsTo
    {
        return $this->belongsTo(NetworkConnection::class, 'fulfillment_connection_id');
    }

    public function inventoryCards(): HasMany
    {
        return $this->hasMany(InventoryCard::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(
            Sale::class,
            'network_product_id'
        );
    }

    public function pricingRules(): HasMany
    {
        return $this->hasMany(PricingRule::class);
    }
}
