<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SaleFinancial extends Model
{
    use HasFactory, HasUlids;

    /*
     * Financial snapshots are created only by trusted
     * domain services, never directly from request input.
     */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'face_value' => 'decimal:4',
            'provider_amount' => 'decimal:4',
            'seller_commission' => 'decimal:4',
            'platform_commission' => 'decimal:4',
            'seller_net_amount' => 'decimal:4',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Sale financial snapshots cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Sale financial snapshots cannot be deleted.'));
    }
}
