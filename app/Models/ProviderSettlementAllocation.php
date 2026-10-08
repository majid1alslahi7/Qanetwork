<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProviderSettlementAllocation extends Model
{
    use HasUlids;

    protected $fillable = ['provider_settlement_id', 'sale_accounting_entry_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Settlement allocations cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Settlement allocations cannot be deleted.'));
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ProviderSettlement::class, 'provider_settlement_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(SaleAccountingEntry::class, 'sale_accounting_entry_id');
    }
}
