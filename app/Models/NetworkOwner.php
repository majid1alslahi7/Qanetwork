<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkOwner extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'code',
        'name',
        'commercial_name',
        'phone',
        'email',
        'country',
        'city',
        'address',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function networks(): HasMany
    {
        return $this->hasMany(Network::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accountingEntries(): HasMany
    {
        return $this->hasMany(SaleAccountingEntry::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(ProviderSettlement::class);
    }
}
