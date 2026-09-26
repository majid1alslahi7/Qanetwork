<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerLedgerEntry extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'seller_id',
        'seller_wallet_id',
        'entry_type',
        'direction',
        'amount',
        'currency_code',
        'balance_after',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'description',
        'created_by',
        'reversal_of_entry_id',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(
            SellerWallet::class,
            'seller_wallet_id'
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(
            self::class,
            'reversal_of_entry_id'
        );
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(
            self::class,
            'reversal_of_entry_id'
        );
    }
}
