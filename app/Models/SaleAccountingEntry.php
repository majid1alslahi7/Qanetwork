<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class SaleAccountingEntry extends Model
{
    use HasUlids;

    protected $fillable = ['sale_id', 'network_owner_id', 'entry_type', 'amount', 'currency_code', 'idempotency_key', 'posted_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'posted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted sale accounting entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Posted sale accounting entries cannot be deleted.'));
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(NetworkOwner::class, 'network_owner_id');
    }

    public function settlementAllocations(): HasMany
    {
        return $this->hasMany(ProviderSettlementAllocation::class);
    }
}
