<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Sale extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'seller_id',
        'seller_wallet_id',
        'network_id',
        'network_product_id',
        'reference_no',
        'idempotency_key',
        'currency_code',
        'delivery_method',
        'customer_phone',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'provider_confirmed_at' => 'datetime',
            'accounting_posted_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
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

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(
            NetworkProduct::class,
            'network_product_id'
        );
    }

    public function financial(): HasOne
    {
        return $this->hasOne(SaleFinancial::class);
    }

    public function providerTransaction(): HasOne
    {
        return $this->hasOne(ProviderTransaction::class);
    }

    public function soldCard(): HasOne
    {
        return $this->hasOne(SoldCard::class);
    }

    public function reservation(): HasOne
    {
        return $this->hasOne(SaleReservation::class);
    }

    public function manualReviews(): HasMany
    {
        return $this->hasMany(ManualSaleReview::class);
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(CardDelivery::class);
    }
}
