<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ProviderSettlement extends Model
{
    use HasUlids;

    protected $fillable = ['network_owner_id', 'reference_no', 'amount', 'currency_code', 'payment_method', 'external_reference', 'idempotency_key', 'notes', 'created_by', 'paid_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'paid_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Recorded provider settlements cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Recorded provider settlements cannot be deleted.'));
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(NetworkOwner::class, 'network_owner_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ProviderSettlementAllocation::class);
    }
}
