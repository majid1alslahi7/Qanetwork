<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerWallet extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'seller_id',
        'currency_code',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:4',
            'reserved_balance' => 'decimal:4',
            'last_transaction_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(SellerDeposit::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SellerLedgerEntry::class);
    }
}
